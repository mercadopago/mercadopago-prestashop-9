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

use MercadoPago\PP\Sdk\Sdk;
use MercadoPago\Service\Configuration\ConfigurationDataService;

if (!defined('_PS_VERSION_')) {
    exit;
}

class MPSdk
{
    private static ?Sdk $instance = null;

    public static function getInstance(): Sdk
    {
        return self::$instance ??= self::build();
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    private static function build(): Sdk
    {
        $config = new ConfigurationDataService();
        $isProd = $config->isProductionMode();

        $accessToken = $isProd
            ? (string) $config->get('MERCADOPAGO_ACCESS_TOKEN', '')
            : (string) $config->get('MERCADOPAGO_SANDBOX_ACCESS_TOKEN', '');

        $publicKey = $isProd
            ? (string) $config->get('MERCADOPAGO_PUBLIC_KEY', '')
            : (string) $config->get('MERCADOPAGO_SANDBOX_PUBLIC_KEY', '');

        $integratorId = (string) $config->get('MERCADOPAGO_INTEGRATOR_ID', '');

        return new Sdk(
            $accessToken,
            MPRestCli::PLATFORM_ID,
            MPRestCli::PRODUCT_ID,
            $integratorId,
            $publicKey
        );
    }
}
