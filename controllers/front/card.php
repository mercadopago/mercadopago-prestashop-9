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

use MercadoPago\Service\Order\CardOrderService;
use MercadoPago\Client\MPApi;

class MercadopagoCardModuleFrontController extends ModuleFrontController
{
    /**
     * Process payment form submission
     */
    public function postProcess()
    {
        $mpOrder = new CardOrderService();

        try {
            $custom_info = \Tools::getValue('mercadopago_custom');

            if (empty($custom_info) || !is_array($custom_info)) {
                throw new \Exception('Form data not received from the frontend');
            }

            $apiResult = $mpOrder->createMPOrder($this->context->cart, $custom_info);
            $httpStatus = $apiResult['status'] ?? 0;
            $mpOrderResponse = $apiResult['response'] ?? [];

            $isApiSuccess = $httpStatus >= 200 && $httpStatus < 300;
            $isOrderRejected = isset($mpOrderResponse['status']) && $mpOrderResponse['status'] === 'failed';

            if (!$isApiSuccess) {
                \PrestaShopLogger::addLog(
                    '[MercadoPago Card] API error - status: ' . $httpStatus . ' - ' . (MPApi::extractApiErrorMessage($mpOrderResponse) ?? 'unknown error'),
                    3,
                    null,
                    'Cart',
                    (int) $this->context->cart->id,
                    true
                );
            }

            if ($isApiSuccess && is_array($mpOrderResponse) && !$isOrderRejected) {
                $mpOrder->saveCreateOrderData($this->context->cart, $mpOrderResponse);
                $mpOrder->disableCartRule();

                $cart = $this->context->cart;
                $order = $this->getOrCreateCardOrder($cart, $mpOrderResponse);

                $uri = __PS_BASE_URI__ . 'index.php?' . http_build_query([
                    'controller'     => 'order-confirmation',
                    'id_cart'        => $order->id_cart,
                    'key'            => $order->secure_key,
                    'id_order'       => $order->id,
                    'id_module'      => $this->module->id,
                    'checkout_type'  => 'custom',
                    'payment_id'     => $mpOrderResponse['id'] ?? '',
                    'payment_status' => $mpOrderResponse['status'] ?? '',
                ]);

                $mpOrder->clearCartAfterOrder($this->context);
                \Tools::redirect($uri);
                return;
            }

            if ($isApiSuccess && $isOrderRejected) {
                $statusDetail = $mpOrderResponse['status_detail'] ?? null;
                if ($statusDetail) {
                    $this->context->cookie->__set('redirect_message', str_replace('_', ' ', $statusDetail));
                }
            } else {
                $message = MPApi::extractApiErrorMessage($mpOrderResponse);
                if (!empty($message)) {
                    $this->context->cookie->__set('redirect_message', $message);
                }
            }
        } catch (\Exception $e) {
            \PrestaShopLogger::addLog(
                '[MercadoPago Card] Exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(),
                3
            );
            $this->errors[] = $e->getMessage();
        }

        $mpOrder->removeCartRuleFromCart($this->context->cart);
        $mpOrder->redirectError();
    }

    /**
     * Returns the PrestaShop order for the cart, creating it via validateOrder if it does not exist yet.
     *
     * @param \Cart   $cart
     * @param array   $mpOrderResponse Response from Mercado Pago (id, status, total_amount, etc.)
     * @return \Order
     * @throws \Exception When the order cannot be created
     */
    private function getOrCreateCardOrder(\Cart $cart, array $mpOrderResponse): \Order
    {
        $orderId = (int) \Order::getIdByCartId((int) $cart->id);
        if ($orderId <= 0) {
            $status = $mpOrderResponse['status'] ?? '';
            if ($status === 'processed' || $status === 'approved') {
                $mpState = (int) \Configuration::get('MERCADOPAGO_STATUS_1');
                $orderState = $mpState > 0 ? $mpState : (int) \Configuration::get('PS_OS_PAYMENT');
            } elseif ($status === 'in_process' || $status === 'authorized') {
                $mpState = (int) \Configuration::get('MERCADOPAGO_STATUS_0');
                $orderState = $mpState > 0 ? $mpState : (int) \Configuration::get('PS_OS_PREPARATION');
            } else {
                $mpState = (int) \Configuration::get('MERCADOPAGO_STATUS_7');
                $orderState = $mpState > 0 ? $mpState : (int) \Configuration::get('PS_OS_PREPARATION');
            }
            $amount = (float) ($mpOrderResponse['total_amount'] ?? $cart->getOrderTotal());
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
