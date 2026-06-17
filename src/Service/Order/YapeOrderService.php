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

class YapeOrderService extends AbstractOrderService
{
    const PAYMENT_METHOD_ID = 'yape';
    const PAYMENT_TYPE_ID = 'wallet';
    const ORDER_TYPE_ONLINE = 'online';

    public function __construct()
    {
        parent::__construct();
        $this->checkout = 'yape';
    }

    /**
     * Create a Yape order in the Mercado Pago API.
     *
     * @param \Cart  $cart
     * @param string $yapeToken Token returned by MPApi::createYapeToken()
     * @return array API response
     */
    public function createMPOrder(\Cart $cart, string $yapeToken): array
    {
        $mpOrder = $this->getCommonMPOrder($cart);
        $mpOrder['type'] = self::ORDER_TYPE_ONLINE;
        $mpOrder['description'] = $this->getPreferenceDescription($cart);
        $mpOrder['config'] = $this->getConfigData();
        $mpOrder['payer'] = $this->getPayerData($cart);
        $mpOrder['shipment'] = $this->getShipmentData($cart);
        $mpOrder['billing'] = $this->getBillingData($cart);

        $discountPercent = (float) \Configuration::get('MERCADOPAGO_YAPE_DISCOUNT');
        $mpOrder['items'] = $this->getCartItems($cart, $discountPercent > 0 ? $discountPercent : null);
        $mpOrder['platform'] = $this->getPlatformData();
        $mpOrder['seller'] = $this->getSellerData();

        $locationData = $this->getLocationData($cart);
        if ($locationData !== null) {
            $mpOrder['location'] = $locationData;
        }

        $this->setCartRule($cart, \Configuration::get('MERCADOPAGO_YAPE_DISCOUNT'));

        $transactionAmount = $this->getTransactionAmount($cart);
        $mpOrder['transaction_amount'] = $transactionAmount;
        $mpOrder['payments'] = $this->getPaymentData($yapeToken, $transactionAmount);

        return $this->mpApi->createOrder($mpOrder, (int) $cart->id);
    }

    protected function getDiscountConfigKey(): string
    {
        return 'MERCADOPAGO_YAPE_DISCOUNT';
    }

    private function getConfigData(): array
    {
        // Yape is Peru-only; currency must always be PEN regardless of store context currency.
        return [
            'currency_id' => 'PEN',
        ];
    }

    private function getPayerData(\Cart $cart): array
    {
        $customerData = $this->getCustomCustomerData($cart) ?? [];
        $addressInvoice = new \Address((int) $cart->id_address_invoice);

        return [
            'email' => $this->getCustomerEmail(),
            'first_name' => $customerData['first_name'] ?? '',
            'last_name' => $customerData['last_name'] ?? '',
            'address' => [
                'zip_code' => $addressInvoice->postcode,
                'street_name' => $customerData['address']['street_name'] ?? '',
                'street_number' => '-',
                'city' => $addressInvoice->city,
            ],
            'phone' => [
                'area_code' => $customerData['phone']['area_code'] ?? '-',
                'number' => $customerData['phone']['number'] ?? '',
            ],
            'registration_date' => $customerData['registration_date'] ?? '',
            'registered_user' => $customerData['registered_user'] ?? 'no',
        ];
    }

    private function getTransactionAmount(\Cart $cart): float
    {
        // Yape is Peru-only (MPE); no integer rounding needed unlike MCO/MLC.
        return (float) \Tools::ps_round($cart->getOrderTotal(), 2);
    }

    private function getPaymentData(string $yapeToken, float $transactionAmount): array
    {
        return [
            [
                'amount' => $transactionAmount,
                'payment_method' => [
                    'id'           => self::PAYMENT_METHOD_ID,
                    'type'         => self::PAYMENT_TYPE_ID,
                    'token'        => $yapeToken,
                    'installments' => 1,
                ],
            ],
        ];
    }
}
