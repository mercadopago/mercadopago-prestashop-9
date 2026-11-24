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

class MercadopagoWalletbuttonModuleFrontController extends AbstractMercadopagoModuleFrontController
{
    /**
     * @return void
     */
    public function initContent()
    {
        $typeReturn = Tools::getValue('typeReturn');

        if ($typeReturn) {
            $this->handleReturn($typeReturn);

            return;
        }

        $this->handlePreferenceCreation();
    }

    /**
     * @return void
     */
    private function handlePreferenceCreation(): void
    {
        try {
            $walletService = new PreferenceService($this->context, new PreferenceGuardRepository());
            $cart = $this->context->cart;

            if (!$cart || !$cart->id) {
                $this->returnJson(['error' => 'Invalid cart'], 400);

                return;
            }

            // An approved payment of this cart is still waiting for its order: never start a second one.
            if ($this->approvedPaymentAwaitingOrder((int) $cart->id) !== null) {
                $this->returnJson(['redirect' => $this->paymentReceivedUrl('walletbutton', (int) $cart->id)], 409);

                return;
            }

            $apiResult = $walletService->createWalletPreference($cart);

            $httpStatus = $apiResult['status'] ?? 0;
            $preferenceResponse = $apiResult['response'] ?? [];
            $isApiSuccess = $httpStatus >= 200 && $httpStatus < 300;

            if ($isApiSuccess && isset($preferenceResponse['id'])) {
                if ($walletService->saveGuardedPreference(
                    $cart,
                    $preferenceResponse,
                    $apiResult['_preference_snapshot'] ?? []
                ) <= 0) {
                    throw new RuntimeException('Could not persist checkout transaction');
                }

                $this->returnJson(['preference' => $preferenceResponse]);

                return;
            }

            $message = MPApi::extractApiErrorMessage($preferenceResponse);
            $this->returnJson(['error' => $message ?: 'Could not create preference'], 500);
        } catch (Exception $e) {
            PrestaShopLogger::addLog('[MercadoPago WalletButton] Exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(), 3);
            $this->returnJson(['error' => 'An unexpected error occurred. Please try again.'], 500);
        }
    }

    /**
     * @param string $typeReturn
     *
     * @return void
     */
    private function handleReturn(string $typeReturn): void
    {
        if ($typeReturn === 'received') {
            $this->renderPaymentReceived('wallet_button');

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
            $walletService = new PreferenceService($this->context, new PreferenceGuardRepository());
            $walletService->clearCartAfterOrder($this->context);

            $uri = __PS_BASE_URI__ . 'index.php?controller=order-confirmation';
            $uri .= '&id_cart=' . $order->id_cart;
            $uri .= '&key=' . $order->secure_key;
            $uri .= '&id_order=' . $order->id;
            $uri .= '&id_module=' . $this->module->id;
            $uri .= '&checkout_type=wallet_button';

            Tools::redirect($uri);
        } catch (Exception $e) {
            PrestaShopLogger::addLog('[MercadoPago WalletButton] Exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(), 3);
            // The payment was approved but the order is not there yet (or needs reconciliation):
            // tell the buyer it was received instead of inviting a second payment.
            if ($this->approvedPaymentAwaitingOrder($cartId) !== null) {
                Tools::redirect($this->paymentReceivedUrl('walletbutton', $cartId));

                return;
            }
            $this->context->cookie->__set('redirect_message', 'An unexpected error occurred. Please try again.');
            Tools::redirect('index.php?controller=order&step=3&typeReturn=failure');
        }
    }

    /**
     * @param array $data
     * @param int $status
     *
     * @return void
     */
    private function returnJson(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    protected function createPreferenceReturnService(): MercadoPago\Service\Preference\PreferenceReturnService
    {
        return PaymentNotificationServiceFactory::createReturnService($this->context);
    }
}
