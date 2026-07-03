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

use MercadoPago\Service\Order\YapeOrderService;
use MercadoPago\Client\MPApi;

class MercadopagoYapeModuleFrontController extends AbstractMercadopagoModuleFrontController
{
    private const MP_STATUS_PROCESSED = 'processed';
    private const MP_STATUS_APPROVED  = 'approved';

    /**
     * Process Yape payment form submission.
     */
    public function postProcess()
    {
        $yapeOrderService = null;

        try {
            $yapeOrderService = new YapeOrderService();
            $cart = $this->context->cart;

            $yapePost = \Tools::getValue('mercadopago_yape');
            $phone    = isset($yapePost['phone']) ? preg_replace('/\D/', '', (string) $yapePost['phone']) : '';
            $otp      = isset($yapePost['otp'])   ? preg_replace('/\D/', '', (string) $yapePost['otp'])   : '';

            if (strlen($phone) !== 9 || strlen($otp) !== 6) {
                $this->context->cookie->__set(
                    'redirect_message',
                    $this->module->l('Invalid payment data.')
                );
                $yapeOrderService->redirectError();
                return;
            }

            $tokenResult = (new MPApi())->createYapeToken($phone, $otp, (int) $cart->id);
            $yapeToken   = $tokenResult['response']['id'] ?? '';

            if (($tokenResult['status'] ?? 0) !== 200 || $yapeToken === '') {
                $message = MPApi::extractApiErrorMessage($tokenResult['response'] ?? []);
                if (!empty($message)) {
                    $this->context->cookie->__set('redirect_message', $message);
                }
                $yapeOrderService->redirectError();
                return;
            }

            $apiResult = $yapeOrderService->createMPOrder($cart, $yapeToken);
            $httpStatus = $apiResult['status'] ?? 0;
            $mpOrderResponse = $apiResult['response'] ?? [];

            $isApiSuccess = $httpStatus >= 200 && $httpStatus < 300;
            $isOrderRejected = isset($mpOrderResponse['status']) && $mpOrderResponse['status'] === 'failed';

            if (!$isApiSuccess) {
                \PrestaShopLogger::addLog(
                    '[MercadoPago Yape] API error - status: ' . $httpStatus . ' - ' . (MPApi::extractApiErrorMessage($mpOrderResponse) ?? 'unknown error'),
                    3,
                    null,
                    'Cart',
                    (int) $cart->id,
                    true
                );
            }

            if ($isApiSuccess && is_array($mpOrderResponse) && !$isOrderRejected) {
                $yapeOrderService->saveCreateOrderData($cart, $mpOrderResponse);
                $yapeOrderService->disableCartRule();

                $order = $this->getOrCreateOrder($cart, $mpOrderResponse, $this->resolveOrderState($mpOrderResponse));

                $payment = $mpOrderResponse['payments'][0] ?? [];

                $uri = __PS_BASE_URI__ . 'index.php?' . http_build_query([
                    'controller'     => 'order-confirmation',
                    'id_cart'        => $order->id_cart,
                    'key'            => $order->secure_key,
                    'id_order'       => $order->id,
                    'id_module'      => $this->module->id,
                    'checkout_type'  => 'yape',
                    'payment_id'     => $payment['id'] ?? '',
                    'payment_status' => $mpOrderResponse['status'] ?? '',
                ]);

                $yapeOrderService->clearCartAfterOrder($this->context);
                \Tools::redirect($uri);
            }

            if (!$isApiSuccess) {
                $message = MPApi::extractApiErrorMessage($mpOrderResponse);
                if (!empty($message)) {
                    $this->context->cookie->__set('redirect_message', $message);
                }
            } elseif ($isApiSuccess && $isOrderRejected) {
                $statusDetail = $mpOrderResponse['status_detail'] ?? null;
                if ($statusDetail) {
                    $this->context->cookie->__set('redirect_message', str_replace('_', ' ', $statusDetail));
                }
            } else {
                $this->context->cookie->__set(
                    'redirect_message',
                    $this->module->l('Unexpected response from payment API.')
                );
            }
        } catch (\Exception $e) {
            \PrestaShopLogger::addLog(
                '[MercadoPago Yape] Exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(),
                3,
                null,
                'Cart',
                (int) ($this->context->cart->id ?? 0),
                true
            );
            $this->errors[] = $e->getMessage();
        }

        if ($yapeOrderService !== null) {
            $yapeOrderService->removeCartRuleFromCart($this->context->cart);
            $yapeOrderService->redirectError();
        } else {
            \Tools::redirect(__PS_BASE_URI__ . 'index.php?controller=order&step=1');
        }
    }

    /**
     * Maps a Mercado Pago order status to the PrestaShop initial order state.
     * Yape is synchronous, so a successful response can already land in PS_OS_PAYMENT.
     *
     * Status contract reference: Mercado Pago Orders API — Yape returns terminal
     * status on the same HTTP call. "processed"/"approved" represent confirmed
     * payment; any other status falls back to PS_OS_PREPARATION until reconciled.
     * @see https://www.mercadopago.com.pe/developers/en/docs/checkout-api/integration-configuration/integration-via-cardform/yape
     */
    private function resolveOrderState(array $mpOrderResponse): int
    {
        $mpStatus = $mpOrderResponse['status'] ?? '';

        if ($mpStatus === self::MP_STATUS_PROCESSED || $mpStatus === self::MP_STATUS_APPROVED) {
            $mpState = (int) \Configuration::get('MERCADOPAGO_STATUS_1');
            return $mpState > 0 ? $mpState : (int) \Configuration::get('PS_OS_PAYMENT');
        }
        $mpState = (int) \Configuration::get('MERCADOPAGO_STATUS_7');
        return $mpState > 0 ? $mpState : (int) \Configuration::get('PS_OS_PREPARATION');
    }
}
