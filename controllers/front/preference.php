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

use MercadoPago\Service\Preference\PreferenceService;
use MercadoPago\Client\MPApi;
use MercadoPago\Repository\MPTransactionRepository;
use MercadoPago\Service\Configuration\ConfigurationDataService;

class MercadopagoPreferenceModuleFrontController extends AbstractMercadopagoModuleFrontController
{
    public function initContent()
    {
        $typeReturn = \Tools::getValue('typeReturn');

        if ($typeReturn) {
            $this->handleReturn($typeReturn);
            return;
        }

        $this->handlePreferenceCreation();
    }

    private function handlePreferenceCreation()
    {
        $preferenceService = new PreferenceService();

        try {
            $cart = $this->context->cart;

            if (!$cart || !$cart->id) {
                \Tools::redirect('index.php?controller=order');
                return;
            }

            $apiResult = $preferenceService->createPreference($cart);

            $httpStatus = $apiResult['status'] ?? 0;
            $preferenceResponse = $apiResult['response'] ?? [];

            $isApiSuccess = $httpStatus >= 200 && $httpStatus < 300;

            if ($isApiSuccess && isset($preferenceResponse['init_point'])) {
                $preferenceService->saveCreateOrderData($cart, $preferenceResponse);

                $isModal = (bool) \Configuration::get('MERCADOPAGO_STANDARD_MODAL');

                if ($isModal) {
                    header('Content-Type: application/json');
                    echo json_encode([
                        'code' => 200,
                        'preference' => $preferenceResponse,
                    ]);
                    exit;
                }

                $isProd = (new ConfigurationDataService())->isProductionMode();
                $initPoint = $isProd
                    ? $preferenceResponse['init_point']
                    : ($preferenceResponse['sandbox_init_point'] ?? $preferenceResponse['init_point']);

                \Tools::redirect($initPoint);
                return;
            }

            $message = MPApi::extractApiErrorMessage($preferenceResponse);

            \PrestaShopLogger::addLog(
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
        } catch (\Exception $e) {
            \PrestaShopLogger::addLog(
                '[MercadoPago Standard] Exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(),
                3
            );
            $this->context->cookie->__set('redirect_message', $e->getMessage());
        }

        \Tools::redirect('index.php?controller=order&step=3&typeReturn=failure');
    }

    /**
     * @param string $typeReturn
     */
    private function handleReturn(string $typeReturn)
    {
        if ($typeReturn === 'failure') {
            \Tools::redirect('index.php?controller=order&step=3&typeReturn=failure');
            return;
        }

        $cartId = (int) \Tools::getValue('cart_id');

        if (!$cartId) {
            \Tools::redirect('index.php?controller=order&step=3&typeReturn=failure');
            return;
        }

        try {
            $cart = new \Cart($cartId);

            if (!$cart->id || (int) $cart->id_customer !== (int) $this->context->customer->id) {
                \Tools::redirect('index.php?controller=order&step=3&typeReturn=failure');
                return;
            }

            $verifiedState = $this->verifyPaymentStatus($cartId, $typeReturn);
            $order = $this->getOrCreatePreferenceOrder($cart, $verifiedState);

            $preferenceService = new PreferenceService();
            $preferenceService->updateTransactionOrderId($order->id, $cartId);
            $preferenceService->clearCartAfterOrder($this->context);

            $uri = __PS_BASE_URI__ . 'index.php?controller=order-confirmation';
            $uri .= '&id_cart=' . $order->id_cart;
            $uri .= '&key=' . $order->secure_key;
            $uri .= '&id_order=' . $order->id;
            $uri .= '&id_module=' . $this->module->id;
            $uri .= '&checkout_type=preference';

            \Tools::redirect($uri);
        } catch (\Exception $e) {
            \PrestaShopLogger::addLog(
                '[MercadoPago Standard] Return handling error: ' . $e->getMessage(),
                3
            );
            $this->context->cookie->__set('redirect_message', $e->getMessage());
            \Tools::redirect('index.php?controller=order&step=3&typeReturn=failure');
        }
    }

    private function verifyPaymentStatus(int $cartId, string $typeReturn): string
    {
        try {
            $repository = new MPTransactionRepository();
            $transaction = $repository->findByCartId($cartId);

            if (!$transaction) {
                return $typeReturn;
            }

            $paymentId = (string) ($transaction['payment_id'] ?? '');

            if ($paymentId === '') {
                return $typeReturn;
            }

            $mpApi = new MPApi();
            $result = $mpApi->getPayment($paymentId);
            $httpStatus = $result['status'] ?? 0;
            $payment = $result['response'] ?? [];

            if ($httpStatus < 200 || $httpStatus >= 300 || empty($payment['status'])) {
                return $typeReturn;
            }

            $mpStatus = (string) $payment['status'];

            if (in_array($mpStatus, ['approved', 'processed'], true)) {
                return 'success';
            }

            if (in_array($mpStatus, ['cancelled', 'rejected', 'failed', 'canceled'], true)) {
                return 'failure';
            }

            return 'pending';
        } catch (\Throwable $th) {
            \PrestaShopLogger::addLog(
                '[MercadoPago Standard] Payment status verification failed: ' . $th->getMessage(),
                2
            );
            return $typeReturn;
        }
    }

    private function getOrCreatePreferenceOrder(\Cart $cart, string $typeReturn): \Order
    {
        $orderId = (int) \Order::getIdByCartId((int) $cart->id);

        if ($orderId <= 0) {
            if ($typeReturn === 'success') {
                $mpState = (int) \Configuration::get('MERCADOPAGO_STATUS_1');
                $orderState = $mpState > 0 ? $mpState : (int) \Configuration::get('PS_OS_PAYMENT');
            } else {
                $mpState = (int) \Configuration::get('MERCADOPAGO_STATUS_7');
                $orderState = $mpState > 0 ? $mpState : (int) \Configuration::get('PS_OS_PREPARATION');
            }

            $amount = (float) $cart->getOrderTotal();
            $customer = new \Customer((int) $cart->id_customer);

            $this->module->validateOrder(
                (int) $cart->id,
                $orderState,
                $amount,
                $this->module->displayName,
                null,
                [],
                (int) $cart->id_currency,
                false,
                $customer->secure_key
            );

            $orderId = (int) \Order::getIdByCartId((int) $cart->id);
        }

        if ($orderId <= 0) {
            throw new \Exception('Order could not be created');
        }

        return new \Order($orderId);
    }
}
