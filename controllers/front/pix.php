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

use MercadoPago\Service\Order\PixOrderService;
use MercadoPago\Client\MPApi;

class MercadopagoPixModuleFrontController extends AbstractMercadopagoModuleFrontController
{
    /**
     * Process PIX payment form submission.
     */
    public function postProcess()
    {
        $pixOrderService = new PixOrderService();

        try {
            $cart = $this->context->cart;
            $apiResult = $pixOrderService->createMPOrder($cart);
            $httpStatus = $apiResult['status'] ?? 0;
            $mpOrderResponse = $apiResult['response'] ?? [];

            $isApiSuccess = $httpStatus >= 200 && $httpStatus < 300;
            $isOrderRejected = isset($mpOrderResponse['status']) && $mpOrderResponse['status'] === 'failed';

            if (!$isApiSuccess) {
                \PrestaShopLogger::addLog(
                    '[MercadoPago PIX] API error - status: ' . $httpStatus . ' - ' . (MPApi::extractApiErrorMessage($mpOrderResponse) ?? 'unknown error'),
                    3,
                    null,
                    'Cart',
                    (int) $cart->id,
                    true
                );
            }

            if ($isApiSuccess && is_array($mpOrderResponse) && !$isOrderRejected) {
                $pixOrderService->saveCreateOrderData($cart, $mpOrderResponse);
                $pixOrderService->disableCartRule();

                $order = $this->getOrCreateOrder($cart, $mpOrderResponse);

                $payment = $mpOrderResponse['payments'][0] ?? [];
                $paymentMethod = $payment['payment_method'] ?? [];

                $pixOrderService->savePixData(
                    $cart,
                    (string) ($paymentMethod['qr_code'] ?? ''),
                    (string) ($paymentMethod['qr_code_base64'] ?? ''),
                    (string) ($payment['date_of_expiration'] ?? '')
                );

                $uri = __PS_BASE_URI__ . 'index.php?controller=order-confirmation';
                $uri .= '&id_cart=' . $cart->id;
                $uri .= '&key=' . $order->secure_key;
                $uri .= '&id_order=' . $order->id;
                $uri .= '&id_module=' . $this->module->id;

                $pixOrderService->clearCartAfterOrder($this->context);
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
                '[MercadoPago PIX] Exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(),
                3,
                null,
                'Cart',
                (int) $this->context->cart->id,
                true
            );
            $this->errors[] = $e->getMessage();
        }

        $pixOrderService->removeCartRuleFromCart($this->context->cart);
        $pixOrderService->redirectError();
    }
}
