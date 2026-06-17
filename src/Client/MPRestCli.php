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

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

if (!defined('_PS_VERSION_')) {
    exit;
}

class MPRestCli
{
    const PRODUCT_ID = 'BC32CCRU643001OI39AG';
    const PLATFORM_ID = 'BP1EEMU0A3M001J8OJUG';
    const API_BASE_MP_URL = 'https://api.mercadopago.com';
    const API_BASE_MELI_URL = 'https://api.mercadolibre.com';

    /**
     * @param $method
     * @param $uri
     * @param $data
     * @param $headers
     * @param $uri_base
     * @return array
     * @throws Exception
     */
    private static function exec($method, $uri, $data, $headers, $uri_base)
    {
        $client = HttpClient::create();
        $headers_default = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'x-platform-id' => self::PLATFORM_ID,
            'x-product-id' => self::PRODUCT_ID,
            'x-integrator-id' => \Configuration::get('MERCADOPAGO_INTEGRATOR_ID'),
            'User-Agent' => 'MercadoPago Prestashop v' . MP_VERSION
        ];
        is_array($headers) ? $headers = array_merge($headers_default, $headers): $headers = $headers_default;
        $isProd = (bool) \Configuration::get('MERCADOPAGO_PROD_STATUS');
        $accessToken = $isProd
            ? \Configuration::get('MERCADOPAGO_ACCESS_TOKEN')
            : \Configuration::get('MERCADOPAGO_SANDBOX_ACCESS_TOKEN');

        $options = [
            'headers' => $headers,
            'json' => $data,
        ];
        if (is_string($accessToken) && $accessToken !== '') {
            $options['auth_bearer'] = $accessToken;
        }
        $response = $client->request($method, $uri_base . $uri, $options);

        $statusCode = $response->getStatusCode();

        try {
            $responseData = $response->toArray();
        } catch (\Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface $e) {
            $responseData = [];
            try {
                $responseData = $e->getResponse()->toArray(false);
            } catch (\Exception $parseEx) {
                $responseData = [
                    'message' => $e->getMessage(),
                    'raw_content' => $e->getResponse()->getContent(false),
                ];
            }
        }

        $result = array(
            'status' => $statusCode,
            'response' => $responseData,
        );

        return $result;
    }

    /**
     * @param $uri
     * @param $data
     * @param null $headers
     * @return array
     * @throws Exception
     */
    public static function post($uri, $data, $uri_base, $headers = null)
    {
        return self::exec('POST', $uri, $data, $headers, $uri_base);
    }

    /**
     * @param $uri
     * @param null $headers
     * @param string $uri_base
     * @return array
     * @throws Exception
     */
    public static function get($uri, $headers = null, $uri_base = self::API_BASE_MP_URL)
    {
        return self::exec('GET', $uri, null, $headers, $uri_base);
    }

    /**
     * Generate a RFC4122 version 4 (random) UUID.
     */
    public static function uuidv4(): string
    {
        $data = random_bytes(16);
        // Set version to 0100
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        // Set bits 6-7 to 10
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
