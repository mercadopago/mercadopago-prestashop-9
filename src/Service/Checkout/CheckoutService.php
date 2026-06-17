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

declare(strict_types=1);

namespace MercadoPago\Service\Checkout;

use MercadoPago\Service\Checkout\Methods\CardCheckoutService;
use MercadoPago\Service\Checkout\Methods\PixCheckoutService;
use MercadoPago\Service\Checkout\Methods\TicketCheckoutService;
use MercadoPago\Service\Checkout\Methods\PreferenceCheckoutService;
use MercadoPago\Service\Checkout\Methods\YapeCheckoutService;
use MercadoPago\Service\Checkout\Methods\PseCheckoutService;
use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

/**
 * Checkout Service
 * Orchestrates checkout options for payment frontend
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class CheckoutService
{
    /**
     * @var \mercadopago
     */
    private $module;

    /**
     * @param \mercadopago $module
     */
    public function __construct(\mercadopago $module)
    {
        $this->module = $module;
    }

    /**
     * @param array $params Hook parameters
     * @return PaymentOption[]
     */
    public function getPaymentOptions(array $params): array
    {
        if (empty($params['cart'])) {
            return [];
        }

        /** @var \Cart $cart */
        $cart = $params['cart'];

        if (!$cart || !$cart->id) {
            return [];
        }

        $options = [];

        $cardCheckoutService = new CardCheckoutService($this->module);
        $customOption = $cardCheckoutService->createPaymentOption($cart);
        if ($customOption !== null) {
            $options[] = $customOption;
        }

        $pixCheckoutService = new PixCheckoutService($this->module);
        $pixOption = $pixCheckoutService->createPaymentOption($cart);
        if ($pixOption !== null) {
            $options[] = $pixOption;
        }

        $preferenceCheckoutService = new PreferenceCheckoutService($this->module);
        $standardOption = $preferenceCheckoutService->createPaymentOption($cart);
        if ($standardOption !== null) {
            $options[] = $standardOption;
        }

        $ticketCheckoutService = new TicketCheckoutService($this->module);
        $ticketOption = $ticketCheckoutService->createPaymentOption($cart);
        if ($ticketOption !== null) {
            $options[] = $ticketOption;
        }

        $yapeCheckoutService = new YapeCheckoutService($this->module);
        $yapeOption = $yapeCheckoutService->createPaymentOption($cart);
        if ($yapeOption !== null) {
            $options[] = $yapeOption;
        }

        $pseCheckoutService = new PseCheckoutService($this->module);
        $pseOption = $pseCheckoutService->createPaymentOption($cart);
        if ($pseOption !== null) {
            $options[] = $pseOption;
        }

        return $options;
    }
}
