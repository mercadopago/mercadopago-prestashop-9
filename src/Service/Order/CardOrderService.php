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

/**
 * 2007-2025 PrestaShop.
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * @copyright Copyright (c) MercadoPago
 * @license   http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

namespace MercadoPago\Service\Order;

if (!defined('_PS_VERSION_')) {
    exit;
}

class CardOrderService extends AbstractOrderService
{
    /**
     * @var array
     */
    private $custom_info = null;

    public function __construct()
    {
        parent::__construct();
        $this->checkout = 'custom';
    }

    /**
     * Create a Card order in the Mercado Pago API.
     *
     * @param \Cart $cart
     * @param array $custom_info Payment form data
     * @return array API response
     * @throws \Exception on API error or missing required fields
     */
    public function createMPOrder(\Cart $cart, array $custom_info): array
    {
        $this->custom_info = $custom_info;

        $mpOrder = $this->getCommonMPOrder($cart);
        $mpOrder['description'] = $this->getPreferenceDescription($cart);
        $mpOrder['config'] = $this->getConfigData();
        $mpOrder['payer'] = $this->getPayerData($cart);
        $mpOrder['shipment'] = $this->getShipmentData($cart);
        $mpOrder['billing'] = $this->getBillingData($cart);
        $mpOrder['type'] = self::ORDER_TYPE_ONLINE;
        $locationData = $this->getLocationData($cart);
        if ($locationData !== null) {
            $mpOrder['location'] = $locationData;
        }
        $discountPercent = (float) \Configuration::get('MERCADOPAGO_CUSTOM_DISCOUNT_PERCENT');
        $mpOrder['items'] = $this->getCartItems($cart, $discountPercent > 0 ? $discountPercent : null);
        $mpOrder['platform'] = $this->getPlatformData();
        $mpOrder['seller'] = $this->getSellerData();

        $this->setCartRule($cart, \Configuration::get('MERCADOPAGO_CUSTOM_DISCOUNT_PERCENT'));
        $mpOrder['transaction_amount'] = $this->getTransactionAmount($cart);
        $mpOrder['payments'] = $this->getPaymentData($cart);

        return $this->mpApi->createOrder($mpOrder, (int) $cart->id);
    }

    private function getTransactionAmount($cart)
    {
        $total = (float) $cart->getOrderTotal();
        $localization = \Configuration::get('MERCADOPAGO_COUNTRY_LINK');
        if ($localization == 'MCO' || $localization == 'MLC') {
            return (int) $total;
        }
        return (string) $total;
    }

    protected function getDiscountConfigKey(): string
    {
        return 'MERCADOPAGO_CUSTOM_DISCOUNT_PERCENT';
    }

    private function getConfigData(): array
    {
        return [
            'currency_id' => $this->getCurrencyId(),
            'binary_mode' => (bool) \Configuration::get('MERCADOPAGO_CUSTOM_BINARY_MODE'),
        ];
    }

    private function validateRequiredFields(array $requiredFields)
    {
        foreach ($requiredFields as $field => $errorMessage) {
            if (!isset($this->custom_info[$field]) || empty($this->custom_info[$field])) {
                throw new \Exception($errorMessage);
            }
        }
    }

    private function getPayerData($cart)
    {
        $customer_data = $this->getCustomCustomerData($cart);

        if (!$this->custom_info || !is_array($this->custom_info)) {
            throw new \Exception('Checkout data was not received from the frontend');
        }

        return array(
            'email' => $this->getCustomerEmail(),
            'first_name' => $customer_data['first_name'],
            'last_name' => $customer_data['last_name'],
            'identification' => array(
                'type' => $this->custom_info['doc_type'] ?? '',
                'number' => $this->custom_info['doc_number'] ?? ''
            ),
            'address' => array(
                'zip_code' => $customer_data['address']['zip_code'],
                'street_name' => $customer_data['address']['street_name'],
                'street_number' => $customer_data['address']['street_number'],
            ),
            'phone' => array(
                'area_code' => $customer_data['phone']['area_code'],
                'number' => $customer_data['phone']['number'],
            ),
            'registration_date' => $customer_data['registration_date'],
            'registered_user' => $customer_data['registered_user']
        );
    }

    private function getPaymentData($cart)
    {
        $this->validateRequiredFields(array(
            'card_token_id' => 'Card token was not provided by the frontend',
            'payment_type_id' => 'Payment type was not provided by the frontend',
            'payment_method_id' => 'Payment method was not provided by the frontend',
            'installments' => 'Number of installments was not provided by the frontend',
        ));

        $token = $this->custom_info['card_token_id'];
        $payment_type = $this->custom_info['payment_type_id'];
        $payment_method_id = $this->custom_info['payment_method_id'];
        $installments = (int) $this->custom_info['installments'];

        $payment_method = array(
            'id' => $payment_method_id,
            'type' => $payment_type,
            'token' => $token,
            'installments' => $installments,
            'statement_descriptor' => $this->getStatementDescriptor(),
        );

        if (!empty($this->custom_info['issuer'])) {
            $payment_method['issuer_id'] = (string) $this->custom_info['issuer'];
        }

        return array(
            array(
                'amount' => $this->getTransactionAmount($cart),
                'payment_method' => $payment_method,
            ),
        );
    }
}
