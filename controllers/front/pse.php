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

require_once __DIR__ . '/AbstractMercadopagoModuleFrontController.php';

use MercadoPago\Service\Order\PseOrderService;
use MercadoPago\Client\MPApi;

class MercadopagoPseModuleFrontController extends AbstractMercadopagoModuleFrontController
{
    /**
     * Process PSE payment submission.
     * PSE is asynchronous: the API returns status `action_required` plus a `payment_url`
     * to which the customer must be redirected to authenticate on their bank.
     */
    public function postProcess()
    {
        $pseOrderService = new PseOrderService();

        try {
            $pseInfo = \Tools::getValue('mercadopago_pse');

            if (empty($pseInfo) || !is_array($pseInfo)) {
                throw new \Exception('Form data not received from the frontend');
            }

            $pseInfo['ip_address'] = (string) \Tools::getRemoteAddr();

            $cart = $this->context->cart;
            $apiResult = $pseOrderService->createMPOrder($cart, $pseInfo);
            $httpStatus = $apiResult['status'] ?? 0;
            $mpOrderResponse = $apiResult['response'] ?? [];
            if (!is_array($mpOrderResponse)) {
                $mpOrderResponse = [];
            }

            $isApiSuccess = $httpStatus >= 200 && $httpStatus < 300;
            $isOrderRejected = isset($mpOrderResponse['status']) && $mpOrderResponse['status'] === 'failed';

            if (!$isApiSuccess) {
                \PrestaShopLogger::addLog(
                    '[MercadoPago PSE] API error - status: ' . $httpStatus . ' - ' . (MPApi::extractApiErrorMessage($mpOrderResponse) ?? 'unknown error'),
                    3,
                    null,
                    'Cart',
                    (int) $cart->id,
                    true
                );
            }

            if ($isApiSuccess && !$isOrderRejected) {
                $pseOrderService->saveCreateOrderData($cart, $mpOrderResponse);
                $pseOrderService->disableCartRule();

                $this->getOrCreateOrder($cart, $mpOrderResponse);

                $payment = $mpOrderResponse['payments'][0] ?? [];
                $paymentMethod = $payment['payment_method'] ?? [];
                $paymentUrl = (string) ($paymentMethod['payment_url'] ?? '');

                if ($paymentUrl !== '' && filter_var($paymentUrl, FILTER_VALIDATE_URL)) {
                    $pseOrderService->savePseData($cart, $paymentUrl);
                    $pseOrderService->clearCartAfterOrder($this->context);
                    \Tools::redirect($paymentUrl);
                    return;
                }

                \PrestaShopLogger::addLog(
                    '[MercadoPago PSE] Invalid or missing payment_url in API response: "' . $paymentUrl . '"',
                    3,
                    null,
                    'Cart',
                    (int) $cart->id,
                    true
                );
                $pseOrderService->redirectError();
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
                '[MercadoPago PSE] Exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(),
                3,
                null,
                'Cart',
                (int) $this->context->cart->id,
                true
            );
            $this->errors[] = $e->getMessage();
        }

        $pseOrderService->removeCartRuleFromCart($this->context->cart);
        $pseOrderService->redirectError();
    }
}
