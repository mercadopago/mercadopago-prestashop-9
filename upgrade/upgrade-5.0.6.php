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
 *
 * @author    PrestaShop SA and Contributors
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

/** Apply pending Doctrine migrations when PrestaShop upgrades an installed module. */
function upgrade_module_5_0_6($module): bool
{
    $installationService = new MercadoPago\Service\Installation\InstallationService($module);

    if ($installationService->createTables()) {
        return true;
    }

    // The PrestaShop module manager records the new version even when an
    // upgrade returns false. Stop that flow after disabling every shop.
    if (!$module->disable(true)) {
        throw new RuntimeException('Mercado Pago upgrade failed and the module could not be disabled for all shops.');
    }

    throw new RuntimeException('Mercado Pago upgrade failed: database schema is incomplete. Repair the schema and retry the upgrade.');
}
