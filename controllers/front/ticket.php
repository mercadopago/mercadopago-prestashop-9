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

use MercadoPago\Service\Order\TicketOrderService;
use MercadoPago\Client\MPApi;

class MercadopagoTicketModuleFrontController extends AbstractMercadopagoModuleFrontController
{
    /**
     * Process Ticket/Boleto payment form submission.
     */
    public function postProcess()
    {
        $ticketOrderService = new TicketOrderService();

        try {
            $ticketInfo = \Tools::getValue('mercadopago_ticket');

            if (empty($ticketInfo) || !is_array($ticketInfo)) {
                throw new \Exception('Form data not received from the frontend');
            }

            $cart = $this->context->cart;
            $apiResult = $ticketOrderService->createMPOrder($cart, $ticketInfo);
            $httpStatus = $apiResult['status'] ?? 0;
            $mpOrderResponse = $apiResult['response'] ?? [];
            if (!is_array($mpOrderResponse)) {
                $mpOrderResponse = [];
            }

            $isApiSuccess = $httpStatus >= 200 && $httpStatus < 300;
            $isOrderRejected = isset($mpOrderResponse['status']) && $mpOrderResponse['status'] === 'failed';

            if (!$isApiSuccess) {
                \PrestaShopLogger::addLog(
                    '[MercadoPago Ticket] API error - status: ' . $httpStatus . ' - ' . (MPApi::extractApiErrorMessage($mpOrderResponse) ?? 'unknown error'),
                    3,
                    null,
                    'Cart',
                    (int) $cart->id,
                    true
                );
            }

            if ($isApiSuccess && !$isOrderRejected) {
                $ticketOrderService->saveCreateOrderData($cart, $mpOrderResponse);
                $ticketOrderService->disableCartRule();

                $order = $this->getOrCreateOrder($cart, $mpOrderResponse);

                $payment = $mpOrderResponse['payments'][0] ?? [];
                $paymentMethod = $payment['payment_method'] ?? [];
                $ticketUrl = (string) ($paymentMethod['payment_url'] ?? '');
                $digitableLine = (string) ($paymentMethod['digitable_line'] ?? '');
                $paymentId = $payment['references']['payment_id'] ?? '';
                $paymentStatus = $mpOrderResponse['status'] ?? '';

                $transactionRepository = new \MercadoPago\Repository\MPTransactionRepository();
                $transactionRepository->saveTicketData((int) $cart->id, $ticketUrl, $digitableLine);

                $uri = __PS_BASE_URI__ . 'index.php?controller=order-confirmation';
                $uri .= '&id_cart=' . $cart->id;
                $uri .= '&id_order=' . $order->id;
                $uri .= '&id_module=' . $this->module->id;
                $uri .= '&key=' . $order->secure_key;
                $uri .= '&payment_id=' . urlencode($paymentId);
                $uri .= '&payment_status=' . urlencode($paymentStatus);

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
                '[MercadoPago Ticket] Exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(),
                3,
                null,
                'Cart',
                (int) ($this->context->cart?->id ?? 0),
                true
            );
            $this->errors[] = $e->getMessage();
        }

        $ticketOrderService->removeCartRuleFromCart($this->context->cart);
        $ticketOrderService->redirectError();
    }
}
