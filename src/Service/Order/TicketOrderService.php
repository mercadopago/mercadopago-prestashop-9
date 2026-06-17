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

class TicketOrderService extends AbstractOrderService
{
    public function __construct()
    {
        parent::__construct();
        $this->checkout = 'ticket';
    }

    /**
     * Create a Ticket order in the Mercado Pago API.
     *
     * @param \Cart $cart
     * @param array $ticketInfo Payment form data
     * @return array API response
     * @throws \InvalidArgumentException when required fields are missing
     * @throws \Exception on API error
     */
    public function createMPOrder(\Cart $cart, array $ticketInfo): array
    {
        $this->validateTicketInfo($ticketInfo);

        $mpOrder = $this->getCommonMPOrder($cart);
        $mpOrder['type'] = self::ORDER_TYPE_ONLINE;
        $mpOrder['description'] = $this->getPreferenceDescription($cart);
        $mpOrder['config'] = $this->getConfigData();
        $mpOrder['payer'] = $this->getPayerData($cart, $ticketInfo);
        $mpOrder['shipment'] = $this->getShipmentData($cart);
        $mpOrder['billing'] = $this->getBillingData($cart);
        $discountPercent = (float) \Configuration::get('MERCADOPAGO_TICKET_DISCOUNT');
        $mpOrder['items'] = $this->getCartItems($cart, $discountPercent > 0 ? $discountPercent : null);
        $mpOrder['platform'] = $this->getPlatformData();
        $mpOrder['seller'] = $this->getSellerData();
        $locationData = $this->getLocationData($cart);
        if ($locationData !== null) {
            $mpOrder['location'] = $locationData;
        }

        $this->setCartRule($cart, \Configuration::get('MERCADOPAGO_TICKET_DISCOUNT'));

        $transactionAmount = $this->getTransactionAmount($cart);
        $mpOrder['transaction_amount'] = $transactionAmount;
        $mpOrder['payments'] = $this->getPaymentData($transactionAmount, $ticketInfo);

        return $this->mpApi->createOrder($mpOrder, (int) $cart->id);
    }

    private function getConfigData(): array
    {
        return [
            'currency_id' => $this->getCurrencyId(),
        ];
    }

    protected function getDiscountConfigKey(): string
    {
        return 'MERCADOPAGO_TICKET_DISCOUNT';
    }

    private function validateTicketInfo(array $ticketInfo): void
    {
        $required = [
            'docType'         => 'The document type was not provided',
            'docNumber'       => 'The document number was not provided',
            'paymentMethodId' => 'The payment method was not provided',
            'paymentTypeId'   => 'The payment type was not provided',
        ];

        foreach ($required as $field => $message) {
            if (!isset($ticketInfo[$field]) || $ticketInfo[$field] === '') {
                throw new \InvalidArgumentException($message);
            }
        }

        if (isset($ticketInfo['paymentOptionId']) && !is_string($ticketInfo['paymentOptionId'])) {
            throw new \InvalidArgumentException('The payment option id must be a string');
        }
    }

    private function getPayerData(\Cart $cart, array $ticketInfo): array
    {
        $payer = [
            'email' => $this->getCustomerEmail(),
            'identification' => [
                'type'   => $ticketInfo['docType'],
                'number' => $ticketInfo['docNumber'],
            ],
        ];

        $countryLink = strtolower((string) \Configuration::get('MERCADOPAGO_COUNTRY_LINK'));
        if ($countryLink === 'mlb') {
            $isCnpj = $ticketInfo['docType'] === 'CNPJ';
            $payer['first_name'] = $ticketInfo['firstname'] ?? '';
            $payer['last_name']  = $isCnpj ? '' : ($ticketInfo['lastname'] ?? '');
            $payer['address'] = [
                'zip_code'      => $ticketInfo['zipcode'] ?? '',
                'street_name'   => $ticketInfo['address'] ?? '',
                'street_number' => (string) ($ticketInfo['number'] ?? ''),
                'neighborhood'  => $ticketInfo['city'] ?? '',
                'city'          => $ticketInfo['city'] ?? '',
                'state'         => $ticketInfo['state'] ?? '',
            ];
        } else {
            $customerData = $this->getCustomCustomerData($cart) ?? [];
            $addressInvoice = new \Address((int) $cart->id_address_invoice);
            $payer['first_name'] = $customerData['first_name'] ?? '';
            $payer['last_name']  = $customerData['last_name'] ?? '';
            $payer['address'] = [
                'zip_code'      => $addressInvoice->postcode,
                'street_name'   => $customerData['address']['street_name'] ?? '',
                'street_number' => '-',
                'city'          => $addressInvoice->city,
            ];
            $payer['phone'] = [
                'area_code' => $customerData['phone']['area_code'] ?? '',
                'number'    => $customerData['phone']['number'] ?? '',
            ];
            $payer['registration_date'] = $customerData['registration_date'] ?? '';
            $payer['registered_user']   = $customerData['registered_user'] ?? 'no';
        }

        return $payer;
    }

    private function getTransactionAmount(\Cart $cart): float
    {
        $total = (float) $cart->getOrderTotal();
        $localization = \Configuration::get('MERCADOPAGO_COUNTRY_LINK');
        if ($localization == 'MCO' || $localization == 'MLC') {
            return (int) $total;
        }
        return $total;
    }

    private function getExpirationDate(): string
    {
        $days = (int) \Configuration::get('MERCADOPAGO_TICKET_EXPIRATION');
        if ($days > 0) {
            return 'P' . $days . 'D';
        }
        return 'P3D';
    }

    private function getPaymentData(float $transactionAmount, array $ticketInfo): array
    {
        $paymentMethod = [
            'id'   => $ticketInfo['paymentMethodId'],
            'type' => $ticketInfo['paymentTypeId'],
        ];

        if (!empty($ticketInfo['paymentOptionId'])) {
            $paymentMethod['payment_option_id'] = $ticketInfo['paymentOptionId'];
        }

        return [
            [
                'amount'             => $transactionAmount,
                'date_of_expiration' => $this->getExpirationDate(),
                'payment_method'     => $paymentMethod,
            ],
        ];
    }
}
