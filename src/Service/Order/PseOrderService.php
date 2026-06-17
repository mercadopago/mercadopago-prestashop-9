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

class PseOrderService extends AbstractOrderService
{
    const PAYMENT_METHOD_ID = 'pse';
    const PAYMENT_TYPE_ID = 'bank_transfer';

    public function __construct()
    {
        parent::__construct();
        $this->checkout = 'pse';
    }

    /**
     * Create a PSE order in the Mercado Pago API.
     *
     * @param array $pseInfo Must contain 'person_type', 'document_type', 'document_number', 'financial_institution' and 'ip_address'
     */
    public function createMPOrder(\Cart $cart, array $pseInfo): array
    {
        $this->validatePseInfo($pseInfo);

        $mpOrder = $this->getCommonMPOrder($cart);
        $mpOrder['type'] = self::ORDER_TYPE_ONLINE;
        $mpOrder['description'] = $this->getPreferenceDescription($cart);
        $mpOrder['ip_address'] = $pseInfo['ip_address'];
        $mpOrder['config'] = $this->getConfigData($cart);
        $mpOrder['payer'] = $this->getPayerData($cart, $pseInfo);
        $mpOrder['shipment'] = $this->getShipmentData($cart);
        $mpOrder['billing'] = $this->getBillingData($cart);
        $discountPercent = (int) \Configuration::get('MERCADOPAGO_PSE_DISCOUNT_PERCENT');
        $mpOrder['items'] = $this->getCartItems($cart, $discountPercent > 0 ? $discountPercent : null);
        $mpOrder['platform'] = $this->getPlatformData();
        $mpOrder['seller'] = $this->getSellerData();
        $locationData = $this->getLocationData($cart);
        if ($locationData !== null) {
            $mpOrder['location'] = $locationData;
        }

        $this->setCartRule($cart, \Configuration::get('MERCADOPAGO_PSE_DISCOUNT_PERCENT'));

        $transactionAmount = $this->getTransactionAmount($cart);
        $mpOrder['transaction_amount'] = $transactionAmount;
        $mpOrder['payments'] = $this->getPaymentData($transactionAmount, $pseInfo);

        return $this->mpApi->createOrder($mpOrder, (int) $cart->id);
    }

    public function savePseData(\Cart $cart, string $paymentUrl): void
    {
        $this->transactionRepository->savePseData((int) $cart->id, $paymentUrl);
    }

    protected function getDiscountConfigKey(): string
    {
        return 'MERCADOPAGO_PSE_DISCOUNT_PERCENT';
    }

    private function validatePseInfo(array $pseInfo): void
    {
        if (empty($pseInfo['person_type'])) {
            throw new \InvalidArgumentException('The person type was not provided');
        }

        $allowedPersonTypes = ['individual', 'association'];
        if (!in_array($pseInfo['person_type'], $allowedPersonTypes, true)) {
            throw new \InvalidArgumentException('The person type is invalid');
        }

        $required = [
            'document_type'         => 'The document type was not provided',
            'document_number'       => 'The document number was not provided',
            'financial_institution' => 'The financial institution was not provided',
            'ip_address'            => 'The IP address was not provided',
        ];

        foreach ($required as $field => $message) {
            if (empty($pseInfo[$field])) {
                throw new \InvalidArgumentException($message);
            }
        }
    }

    private function getConfigData(\Cart $cart): array
    {
        return [
            'currency_id' => 'COP',
            'online_custom' => [
                'callback_url' => $this->getCallbackUrl($cart),
            ],
        ];
    }

    private function getPayerData(\Cart $cart, array $pseInfo): array
    {
        $customerData = $this->getCustomCustomerData($cart) ?? [];
        $addressInvoice = new \Address((int) $cart->id_address_invoice);

        return [
            'email' => $this->getCustomerEmail(),
            'first_name' => $customerData['first_name'] ?? '',
            'last_name' => $customerData['last_name'] ?? '',
            'entity_type' => $pseInfo['person_type'],
            'identification' => [
                'type'   => $pseInfo['document_type'],
                'number' => $pseInfo['document_number'],
            ],
            'address' => [
                'zip_code'      => $addressInvoice->postcode,
                'street_name'   => $customerData['address']['street_name'] ?? '',
                // Placeholder '-': PrestaShop does not separate street number from address1
                'street_number' => '-',
                'city'          => $addressInvoice->city,
            ],
            'phone' => [
                'area_code' => $customerData['phone']['area_code'] ?? '-',
                'number'    => $customerData['phone']['number'] ?? '',
            ],
            'registration_date' => $customerData['registration_date'] ?? '',
            'registered_user'   => $customerData['registered_user'] ?? 'no',
        ];
    }

    private function getPaymentData(float $transactionAmount, array $pseInfo): array
    {
        return [
            [
                'amount' => $transactionAmount,
                'payment_method' => [
                    'id'                    => self::PAYMENT_METHOD_ID,
                    'type'                  => self::PAYMENT_TYPE_ID,
                    'financial_institution' => $pseInfo['financial_institution'],
                ],
            ],
        ];
    }

    private function getTransactionAmount(\Cart $cart): float
    {
        return (float) \Tools::ps_round($cart->getOrderTotal());
    }

    public function getRound(): bool
    {
        return true;
    }

    private function getCallbackUrl(\Cart $cart): string
    {
        $module = \Module::getInstanceByName('mercadopago');
        $idModule = $module ? (int) $module->id : 0;

        $params = http_build_query([
            'id_cart'        => (int) $cart->id,
            'key'            => pSQL($cart->secure_key),
            'id_module'      => $idModule,
            'payment_status' => 'pending',
            'checkout_type'  => 'pse',
        ]);

        return \Context::getContext()->link->getPageLink('order-confirmation', true) . '?' . $params;
    }
}
