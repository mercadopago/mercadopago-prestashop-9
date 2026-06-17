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

use MercadoPago\Service\Order\AbstractOrderService;

if (!defined('_PS_VERSION_')) {
    exit;
}

abstract class AbstractPreferenceService extends AbstractOrderService
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * @param \Cart $cart
     * @return array
     */
    public function getCommonPreference($cart)
    {
        $preference = [
            'external_reference' => (string) $cart->id,
            'notification_url' => $this->getNotificationUrl($cart),
            'statement_descriptor' => $this->getStatementDescriptor(),
        ];

        if (!$this->isTestUser()) {
            $preference['sponsor_id'] = $this->getSponsorId();
        }

        return $preference;
    }

    /**
     * @param \Cart $cart
     * @return array
     */
    public function getBackUrls($cart)
    {
        return [
            'success' => $this->getReturnUrl($cart, 'success'),
            'failure' => $this->getReturnUrl($cart, 'failure'),
            'pending' => $this->getReturnUrl($cart, 'pending'),
        ];
    }

    /**
     * @param \Cart $cart
     * @param string $typeReturn
     * @return string
     */
    public function getReturnUrl($cart, $typeReturn)
    {
        return \Tools::getShopDomainSsl(true, true) . __PS_BASE_URI__ .
            '?fc=module&module=mercadopago&controller=preference&cart_id=' . (int) $cart->id . '&typeReturn=' . $typeReturn;
    }

    /**
     * @return string|null
     */
    public function getAutoReturn()
    {
        return (bool) \Configuration::get('MERCADOPAGO_STANDARD_AUTO_RETURN') ? 'approved' : null;
    }

    /**
     * @return bool
     */
    public function getBinaryMode()
    {
        return (bool) \Configuration::get('MERCADOPAGO_STANDARD_BINARY_MODE');
    }

    /**
     * @param \Cart $cart
     * @return array
     */
    public function getPayer($cart)
    {
        return ['email' => $this->getCustomerEmail()];
    }

    /**
     * @param \Cart $cart
     * @return array
     */
    public function getItemsForPreference($cart)
    {
        $items = $this->getCartItems($cart);
        $currencyId = $this->getCurrencyId();

        foreach ($items as &$item) {
            $item['currency_id'] = $currencyId;
        }

        return $items;
    }

    /**
     * @return array
     */
    protected function getPaymentMethods()
    {
        $maxInstallments = (int) \Configuration::get('MERCADOPAGO_MAX_INSTALLMENTS');

        if ($maxInstallments > 0) {
            return ['installments' => $maxInstallments];
        }

        return [];
    }

    /**
     * @return bool
     */
    public function getExpirationStatus()
    {
        $value = \Configuration::get('MERCADOPAGO_EXPIRATION_DATE_TO');
        return $value !== false && $value !== '';
    }

    /**
     * @return string|null
     */
    public function getExpirationDate()
    {
        $hours = \Configuration::get('MERCADOPAGO_EXPIRATION_DATE_TO');
        if ($hours === false || $hours === '') {
            return null;
        }
        return date('Y-m-d\TH:i:s.000O', strtotime('+' . (int) $hours . ' hours'));
    }

    /**
     * Returns the payment_methods block for the preference payload.
     * Fetches all available methods from the API and excludes those disabled by the admin.
     * account_money is always included.
     *
     * @return array
     */
    public function getPaymentOptions(): array
    {
        $excludedMethods = [];

        try {
            $result = $this->mpApi->getPaymentMethods();
            if ($result && ($result['status'] ?? 0) <= 202) {
                \Configuration::updateValue('MERCADOPAGO_PAYMENT_account_money', 'on');

                foreach ($result['response'] as $pm) {
                    $id  = $pm['id'] ?? '';
                    if (!$id) {
                        continue;
                    }
                    $configKey = 'MERCADOPAGO_PAYMENT_' . $id;
                    $value     = \Configuration::get($configKey);

                    // Exclude when explicitly set to '' (disabled); default (false/not set) = included
                    if ($value === '') {
                        $excludedMethods[] = ['id' => strtolower($id)];
                    }
                }
            }
        } catch (\Exception $e) {
            // If API is unavailable, send no exclusions
        }

        return [
            'installments'              => $this->getMaxInstallments(),
            'excluded_payment_types'    => [],
            'excluded_payment_methods'  => $excludedMethods,
        ];
    }

    /**
     * @return int
     */
    public function getMaxInstallments(): int
    {
        return (int) \Configuration::get('MERCADOPAGO_MAX_INSTALLMENTS') ?: 12;
    }
}
