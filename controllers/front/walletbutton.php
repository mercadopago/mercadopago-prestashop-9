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
use MercadoPago\Service\Preference\PreferenceService;

class MercadopagoWalletbuttonModuleFrontController extends AbstractMercadopagoModuleFrontController
{
    /**
     * @return void
     */
    public function initContent()
    {
        $typeReturn = \Tools::getValue('typeReturn');

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
            $walletService = new PreferenceService();
            $cart = $this->context->cart;

            if (!$cart || !$cart->id) {
                $this->returnJson(['error' => 'Invalid cart'], 400);
                return;
            }

            $apiResult = $walletService->createWalletPreference($cart);

            $httpStatus = $apiResult['status'] ?? 0;
            $preferenceResponse = $apiResult['response'] ?? [];
            $isApiSuccess = $httpStatus >= 200 && $httpStatus < 300;

            if ($isApiSuccess && isset($preferenceResponse['id'])) {
                $walletService->saveCreateOrderData($cart, $preferenceResponse);

                $this->returnJson(['preference' => $preferenceResponse]);
                return;
            }

            $message = MPApi::extractApiErrorMessage($preferenceResponse);
            $this->returnJson(['error' => $message ?: 'Could not create preference'], 500);
        } catch (\Exception $e) {
            \PrestaShopLogger::addLog('[MercadoPago WalletButton] Exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(), 3);
            $this->returnJson(['error' => 'An unexpected error occurred. Please try again.'], 500);
        }
    }

    /**
     * @param string $typeReturn
     * @return void
     */
    private function handleReturn(string $typeReturn): void
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

            // Cria o pedido num estado do módulo MP (MERCADOPAGO_STATUS_*) para que as
            // notificações consigam atualizá-lo depois (isCurrentStateAllowed só permite
            // atualizar pedidos cujo estado pertence ao módulo). PS_OS_* fica como fallback.
            if ($typeReturn === 'success') {
                $mpState = (int) \Configuration::get('MERCADOPAGO_STATUS_1');
                $orderState = $mpState > 0 ? $mpState : (int) \Configuration::get('PS_OS_PAYMENT');
            } else {
                $mpState = (int) \Configuration::get('MERCADOPAGO_STATUS_7');
                $orderState = $mpState > 0 ? $mpState : (int) \Configuration::get('PS_OS_PREPARATION');
            }

            $order = $this->getOrCreateOrder($cart, [], $orderState);

            $walletService = new PreferenceService();
            $walletService->updateTransactionOrderId($order->id, $cartId);
            $walletService->clearCartAfterOrder($this->context);

            $uri = __PS_BASE_URI__ . 'index.php?controller=order-confirmation';
            $uri .= '&id_cart=' . $order->id_cart;
            $uri .= '&key=' . $order->secure_key;
            $uri .= '&id_order=' . $order->id;
            $uri .= '&id_module=' . $this->module->id;
            $uri .= '&checkout_type=wallet_button';

            \Tools::redirect($uri);
        } catch (\Exception $e) {
            \PrestaShopLogger::addLog('[MercadoPago WalletButton] Exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(), 3);
            $this->context->cookie->__set('redirect_message', 'An unexpected error occurred. Please try again.');
            \Tools::redirect('index.php?controller=order&step=3&typeReturn=failure');
        }
    }

    /**
     * @param array $data
     * @param int   $status
     * @return void
     */
    private function returnJson(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
}
