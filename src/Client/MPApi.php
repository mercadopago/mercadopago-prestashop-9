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

namespace MercadoPago\Client;

if (!defined('_PS_VERSION_')) {
    exit;
}

class MPApi
{
    const API_BASE_MP_OP_PP_ORDER_URL = 'https://api.mercadopago.com/plugins-platforms';
    const API_BASE_MP_OP_PP_PAYMENT_METHODS = 'https://api.mercadopago.com/ppcore/prod/payment-methods';
    const API_BASE_MP_OP_PP_PREFERENCES = 'https://api.mercadopago.com/ppcore/prod/transaction';

    public function __construct()
    {
    }

    public function createOrder($order, int $cartId)
    {
        try {
            $headers = [
                'x-idempotency-key' => 'ps_' . md5(_COOKIE_KEY_ . '_' . $cartId)
            ];
            $response = MPRestCli::post('/v1/orders', $order, self::API_BASE_MP_OP_PP_ORDER_URL, $headers);
            return $response;
        } catch (\Exception $e) {
            throw new \Exception('Error creating order: ' . $e->getMessage());
        }
    }

    public function createYapeToken(string $phoneNumber, string $otp, int $cartId): array
    {
        try {
            $isProd = (bool) \Configuration::get('MERCADOPAGO_PROD_STATUS');
            $publicKey = $isProd
                ? \Configuration::get('MERCADOPAGO_PUBLIC_KEY')
                : \Configuration::get('MERCADOPAGO_SANDBOX_PUBLIC_KEY');

            $uri = '/platforms/pci/yape/v1/payment?public_key=' . rawurlencode((string) $publicKey);
            $payload = [
                'phoneNumber' => $phoneNumber,
                'otp'         => $otp,
                'requestId'   => MPRestCli::uuidv4(),
            ];

            return MPRestCli::post($uri, $payload, MPRestCli::API_BASE_MP_URL);
        } catch (\Exception $e) {
            throw new \Exception('Error creating Yape token: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get payment methods from Mercado Pago.
     * Result is cached per-request via a static property.
     *
     * @return array
     * @throws \Exception
     */
    public function getPaymentMethods(): array
    {
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        try {
            $cache = MPRestCli::get('/v1/payment-methods', null, self::API_BASE_MP_OP_PP_PAYMENT_METHODS);
        } catch (\Exception $e) {
            throw new \Exception('Error getting payment methods: ' . $e->getMessage());
        }

        return $cache;
    }

    /**
     * Get access token
     *
     * @return string
     */
    public function getAccessToken()
    {
        if (\Configuration::get('MERCADOPAGO_PROD_STATUS') == true) {
            return \Configuration::get('MERCADOPAGO_ACCESS_TOKEN');
        }

        return \Configuration::get('MERCADOPAGO_SANDBOX_ACCESS_TOKEN');
    }

    public function createPreference(array $preference)
    {
        try {
            $response = MPRestCli::post(
                '/v1/preferences',
                $preference,
                self::API_BASE_MP_OP_PP_PREFERENCES
            );
            return $response;
        } catch (\Exception $e) {
            throw new \Exception('Error creating preference: ' . $e->getMessage());
        }
    }

    /**
     * Get payment details by ID from Mercado Pago.
     *
     * @param string $paymentId
     * @return array
     * @throws \Exception
     */
    public function getPayment(string $paymentId): array
    {
        try {
            return MPRestCli::get('/v1/payments/' . rawurlencode($paymentId));
        } catch (\Exception $e) {
            throw new \Exception('Error getting payment ' . $paymentId . ': ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get merchant order details by ID from Mercado Pago.
     *
     * @param string $merchantOrderId
     * @return array
     * @throws \Exception
     */
    public function getMerchantOrder(string $merchantOrderId): array
    {
        try {
            return MPRestCli::get('/merchant_orders/' . rawurlencode($merchantOrderId));
        } catch (\Exception $e) {
            throw new \Exception('Error getting merchant order ' . $merchantOrderId . ': ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Is valid access token
     *
     * @return array
     * @throws Exception
     */
    public function getUserInfo()
    {
        try {
            return MPRestCli::get('/users/me');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @param array|null $apiResponse
     * @return string|null
     */
    public static function extractApiErrorMessage($apiResponse = null)
    {
        if ($apiResponse === null) {
            return null;
        }

        $originalMessage = $apiResponse['original_message'] ?? null;

        if ($originalMessage !== null) {
            $jsonStart = strpos($originalMessage, '{');
            if ($jsonStart !== false) {
                $decoded = json_decode(substr($originalMessage, $jsonStart), true);
                if (isset($decoded['message'])) {
                    return str_replace('_', ' ', $decoded['message']);
                }
            }

            if (strpos($originalMessage, 'validation failed:') !== false) {
                return str_replace('_', ' ', trim(explode(':', $originalMessage, 2)[1] ?? ''));
            }

            return str_replace('_', ' ', $originalMessage);
        }

        // Standard MP API error format: {"message": "...", "error": "...", "status": 400}
        if (!empty($apiResponse['message'])) {
            return str_replace('_', ' ', (string) $apiResponse['message']);
        }

        return null;
    }
}
