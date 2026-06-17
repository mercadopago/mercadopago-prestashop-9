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

abstract class AbstractMercadopagoModuleFrontController extends ModuleFrontController
{
    /**
     * Returns the PrestaShop order for the cart, creating it via validateOrder if it does not exist yet.
     *
     * By default the order is created with PS_OS_PREPARATION (pending) since payment confirmation
     * is asynchronous via webhook. Subclasses with synchronous status (e.g. Yape) can pass an
     * explicit state to override this default.
     *
     * @param \Cart    $cart
     * @param array    $mpOrderResponse
     * @param int|null $orderState Initial PS order state; defaults to PS_OS_PREPARATION when null.
     * @return \Order
     * @throws \Exception
     */
    protected function getOrCreateOrder(\Cart $cart, array $mpOrderResponse, ?int $orderState = null): \Order
    {
        $orderId = (int) \Order::getIdByCartId((int) $cart->id);

        if ($orderId <= 0) {
            $pendingState = (int) \Configuration::get('MERCADOPAGO_STATUS_7');
            $resolvedState = $orderState ?? ($pendingState > 0 ? $pendingState : (int) \Configuration::get('PS_OS_PREPARATION'));
            $amount = (float) ($mpOrderResponse['total_amount'] ?? $cart->getOrderTotal());
            $customer = new \Customer((int) $cart->id_customer);

            $this->module->validateOrder(
                (int) $cart->id,
                $resolvedState,
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
