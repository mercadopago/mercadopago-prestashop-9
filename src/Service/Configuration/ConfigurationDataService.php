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

namespace MercadoPago\Service\Configuration;

use Configuration;
use Context;
use Shop;

if (!defined('_PS_VERSION_')) {
    exit;
}

class ConfigurationDataService
{
    private const SHOP_PRIVATE_KEYS = [
        'MERCADOPAGO_ACCESS_TOKEN',
        'MERCADOPAGO_PUBLIC_KEY',
        'MERCADOPAGO_SANDBOX_ACCESS_TOKEN',
        'MERCADOPAGO_SANDBOX_PUBLIC_KEY',
        'MERCADOPAGO_PROD_STATUS',
        'MERCADOPAGO_INTEGRATOR_ID',
        'MERCADOPAGO_ONBOARDING_CODE_VERIFIER',
        'MERCADOPAGO_ONBOARDING_ID',
        'MERCADOPAGO_ONBOARDING_STATUS',
        'MERCADOPAGO_ONBOARDING_URL',
    ];

    public function get(string $key, $defaultValue = null)
    {
        [$idShopGroup, $idShop] = $this->getShopContext();

        if ($idShop > 0 && in_array($key, self::SHOP_PRIVATE_KEYS, true)) {
            if (Configuration::hasKey($key, 0, null, $idShop)) {
                return Configuration::get($key, null, $idShopGroup, $idShop, $defaultValue);
            }

            // Keep legacy global credentials available only to the default shop.
            $defaultShopId = (int) Configuration::getGlobalValue('PS_SHOP_DEFAULT');
            if ($idShop !== $defaultShopId) {
                return $defaultValue;
            }
        }

        return Configuration::get($key, null, $idShopGroup, $idShop, $defaultValue);
    }

    public function set(string $key, $value): bool
    {
        [$idShopGroup, $idShop] = $this->getShopContext();

        return Configuration::updateValue($key, $value, false, $idShopGroup, $idShop);
    }

    public function delete(string $key): bool
    {
        [$idShopGroup, $idShop] = $this->getShopContext();
        if ($idShop > 0) {
            Configuration::deleteFromGivenContext($key, $idShopGroup, $idShop);

            return true;
        }

        return Configuration::deleteByName($key);
    }

    public function isProductionMode(): bool
    {
        $prodStatus = $this->get('MERCADOPAGO_PROD_STATUS');
        return in_array($prodStatus, [true, 1, '1'], true);
    }

    /** @return array{0: int|null, 1: int|null} */
    private function getShopContext(): array
    {
        $contextShop = Context::getContext()->shop;
        $idShop = isset($contextShop->id) ? (int) $contextShop->id : 0;
        if ($idShop <= 0) {
            $idShop = (int) Shop::getContextShopID(true);
        }

        $idShopGroup = $idShop > 0
            ? (int) Shop::getGroupFromShop($idShop, true)
            : (int) Shop::getContextShopGroupID(true);

        return [$idShopGroup ?: null, $idShop ?: null];
    }
}
