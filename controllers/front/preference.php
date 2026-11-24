<?php
/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/OSL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * @author    PrestaShop SA and Contributors <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

// Necessário: este controller depende da classe base, que é autoloaded via
// classmap. O PHP-Scoper embrulha o arquivo dela (sem namespace próprio) e o
// class_alias() resultante só roda após ela ser incluída, então o autoload por
// classmap nunca a encontra pelo nome global (ver scoper.inc.php). Não remover
// achando redundante com o autoload.
require_once __DIR__ . '/AbstractMercadopagoModuleFrontController.php';

use MercadoPago\Client\MPApi;
use MercadoPago\Repository\PreferenceGuardRepository;
use MercadoPago\Service\Notification\PaymentNotificationServiceFactory;
use MercadoPago\Service\Preference\PreferenceService;

class MercadopagoPreferenceModuleFrontController extends AbstractMercadopagoModuleFrontController
{
    public function initContent()
    {
        $typeReturn = Tools::getValue('typeReturn');

        if ($typeReturn) {
            $this->handleReturn($typeReturn);

            return;
        }

        $this->handlePreferenceCreation();
    }

    private function handlePreferenceCreation()
    {
        $preferenceService = new PreferenceService($this->context, new PreferenceGuardRepository());

        try {
            $cart = $this->context->cart;

            if (!$cart || !$cart->id) {
                Tools::redirect('index.php?controller=order');

                return;
            }

            // An approved payment of this cart is still waiting for its order: never start a second one.
            if ($this->approvedPaymentAwaitingOrder((int) $cart->id) !== null) {
                $this->redirectToPaymentReceived((int) $cart->id);

                return;
            }

            $apiResult = $preferenceService->createPreference($cart);

            $httpStatus = $apiResult['status'] ?? 0;
            $preferenceResponse = $apiResult['response'] ?? [];

            $isApiSuccess = $httpStatus >= 200 && $httpStatus < 300;

            if ($isApiSuccess && isset($preferenceResponse['init_point'])) {
                if ($preferenceService->saveGuardedPreference(
                    $cart,
                    $preferenceResponse,
                    $apiResult['_preference_snapshot'] ?? []
                ) <= 0) {
                    throw new RuntimeException('Could not persist checkout transaction');
                }

                $isModal = (bool) Configuration::get('MERCADOPAGO_STANDARD_MODAL');

                if ($isModal) {
                    header('Content-Type: application/json');
                    echo json_encode([
                        'code' => 200,
                        'preference' => $preferenceResponse,
                    ]);
                    exit;
                }

                $isProd = (bool) Configuration::get('MERCADOPAGO_PROD_STATUS');
                $initPoint = $isProd
                    ? $preferenceResponse['init_point']
                    : ($preferenceResponse['sandbox_init_point'] ?? $preferenceResponse['init_point']);

                Tools::redirect($initPoint);

                return;
            }

            $message = MPApi::extractApiErrorMessage($preferenceResponse);

            PrestaShopLogger::addLog(
                '[MercadoPago Standard] API error - status: ' . $httpStatus . ' - ' . ($message ?? 'unknown error'),
                3,
                null,
                'Cart',
                (int) $cart->id,
                true
            );

            if (!empty($message)) {
                $this->context->cookie->__set('redirect_message', $message);
            }
        } catch (Exception $e) {
            PrestaShopLogger::addLog(
                '[MercadoPago Standard] Exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(),
                3
            );
            $this->context->cookie->__set('redirect_message', $e->getMessage());
        }

        Tools::redirect('index.php?controller=order&step=3&typeReturn=failure');
    }

    /**
     * @param string $typeReturn
     */
    private function handleReturn(string $typeReturn)
    {
        if ($typeReturn === 'received') {
            $this->renderPaymentReceived('preference');

            return;
        }

        if ($typeReturn === 'failure') {
            Tools::redirect('index.php?controller=order&step=3&typeReturn=failure');

            return;
        }

        $cartId = (int) Tools::getValue('cart_id');
        $preferenceIdValue = Tools::getValue('preference_id');
        $preferenceId = is_string($preferenceIdValue) ? trim($preferenceIdValue) : '';

        if (!$cartId || $preferenceId === '') {
            Tools::redirect('index.php?controller=order&step=3&typeReturn=failure');

            return;
        }

        try {
            $cart = new Cart($cartId);

            if (!$cart->id || (int) $cart->id_customer !== (int) $this->context->customer->id) {
                Tools::redirect('index.php?controller=order&step=3&typeReturn=failure');

                return;
            }

            $paymentValue = Tools::getValue('payment_id', Tools::getValue('collection_id', ''));
            $paymentId = is_scalar($paymentValue) ? (string) $paymentValue : '';
            $returnService = $this->createPreferenceReturnService();
            $order = $returnService->complete(
                $cartId,
                $preferenceId,
                $paymentId,
                (string) $this->context->customer->secure_key
            );
            $preferenceService = new PreferenceService($this->context, new PreferenceGuardRepository());
            $preferenceService->clearCartAfterOrder($this->context);

            $uri = __PS_BASE_URI__ . 'index.php?controller=order-confirmation';
            $uri .= '&id_cart=' . $order->id_cart;
            $uri .= '&key=' . $order->secure_key;
            $uri .= '&id_order=' . $order->id;
            $uri .= '&id_module=' . $this->module->id;
            $uri .= '&checkout_type=preference';

            Tools::redirect($uri);
        } catch (Exception $e) {
            PrestaShopLogger::addLog(
                '[MercadoPago Standard] Return handling error: ' . $e->getMessage(),
                3
            );
            // The payment was approved but the order is not there yet (or needs reconciliation):
            // tell the buyer it was received instead of inviting a second payment.
            if ($this->approvedPaymentAwaitingOrder($cartId) !== null) {
                Tools::redirect($this->paymentReceivedUrl('preference', $cartId));

                return;
            }
            $this->context->cookie->__set('redirect_message', $e->getMessage());
            Tools::redirect('index.php?controller=order&step=3&typeReturn=failure');
        }
    }

    /** Modal checkout calls this endpoint with fetch(), so it needs JSON instead of a redirect. */
    private function redirectToPaymentReceived(int $cartId): void
    {
        $url = $this->paymentReceivedUrl('preference', $cartId);
        if ((bool) Configuration::get('MERCADOPAGO_STANDARD_MODAL')) {
            header('Content-Type: application/json');
            echo json_encode(['code' => 409, 'redirect' => $url]);
            exit;
        }

        Tools::redirect($url);
    }

    protected function createPreferenceReturnService(): MercadoPago\Service\Preference\PreferenceReturnService
    {
        return PaymentNotificationServiceFactory::createReturnService($this->context);
    }
}
