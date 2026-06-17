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

namespace MercadoPago\Service\Preference;

if (!defined('_PS_VERSION_')) {
    exit;
}

class PreferenceService extends AbstractPreferenceService
{
    public function __construct()
    {
        parent::__construct();
        $this->checkout = 'preference';
    }

    /**
     * @param \Cart $cart
     * @return array
     */
    public function createPreference($cart)
    {
        $paymentMethods = $this->getPaymentMethods();

        $payload = array_merge(
            $this->getCommonPreference($cart),
            [
                'items'           => $this->getItemsForPreference($cart),
                'payer'           => $this->getPayer($cart),
                'back_urls'       => $this->getBackUrls($cart),
                'auto_return'     => $this->getAutoReturn(),
                'binary_mode'     => $this->getBinaryMode(),
                'payment_methods' => $this->getPaymentOptions(),
                'metadata'        => $this->getMetadata($cart),
            ]
        );

        if (!empty($paymentMethods)) {
            $payload['payment_methods'] = $paymentMethods;
        }

        if ($this->getExpirationStatus()) {
            $payload['expires'] = true;
            $payload['expiration_date_to'] = $this->getExpirationDate();
        }

        return $this->mpApi->createPreference($payload);
    }

    /**
     * @param \Cart $cart
     * @return array
     */
    public function createWalletPreference($cart)
    {
        $payload = array_merge(
            $this->getCommonPreference($cart),
            [
                'items'           => $this->getItemsForPreference($cart),
                'payer'           => $this->getPayer($cart),
                'back_urls'       => $this->getWalletBackUrls($cart),
                'auto_return'     => $this->getAutoReturn(),
                'binary_mode'     => $this->getBinaryMode(),
                'payment_methods' => $this->getPaymentOptions(),
                'metadata'        => $this->getWalletMetadata(),
                'purpose'         => 'wallet_purchase',
            ]
        );

        if ($this->getExpirationStatus()) {
            $payload['expires'] = true;
            $payload['expiration_date_to'] = $this->getExpirationDate();
        }

        return $this->mpApi->createPreference($payload);
    }

    /**
     * @param \Cart $cart
     * @return array
     */
    private function getMetadata($cart)
    {
        $checkoutType = (bool) \Configuration::get('MERCADOPAGO_STANDARD_MODAL') ? 'modal' : 'redirect';
        return [
            'checkout' => 'preference',
            'checkout_type' => $checkoutType,
        ];
    }


    /**
     * @return array
     */
    private function getWalletMetadata(): array
    {
        return [
            'checkout'      => 'custom',
            'checkout_type' => 'wallet_button',
        ];
    }

    /**
     * @param \Cart $cart
     * @return array
     */
    private function getWalletBackUrls(\Cart $cart): array
    {
        $base = \Tools::getShopDomainSsl(true, true) . __PS_BASE_URI__ .
            '?fc=module&module=mercadopago&controller=walletbutton&cart_id=' . (int) $cart->id;

        return [
            'success' => $base . '&typeReturn=success',
            'failure' => $base . '&typeReturn=failure',
            'pending' => $base . '&typeReturn=pending',
        ];
    }
}
