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

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

if (!defined('_PS_VERSION_')) {
    exit;
}

final class Version20260413000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Insert default configuration values for PSE payment method';
    }

    public function up(Schema $schema): void
    {
        $defaults = [
            'MERCADOPAGO_PSE_ENABLED'         => '0',
            'MERCADOPAGO_PSE_DISCOUNT_PERCENT' => '0',
        ];

        foreach ($defaults as $name => $value) {
            $this->addSql(
                "INSERT INTO `" . _DB_PREFIX_ . "configuration` (`name`, `value`, `date_add`, `date_upd`)
                 SELECT ?, ?, NOW(), NOW()
                 WHERE NOT EXISTS (SELECT 1 FROM `" . _DB_PREFIX_ . "configuration` WHERE `name` = ?)",
                [$name, $value, $name]
            );
        }
    }

    public function down(Schema $schema): void
    {
        $keys = [
            'MERCADOPAGO_PSE_ENABLED',
            'MERCADOPAGO_PSE_DISCOUNT_PERCENT',
        ];

        foreach ($keys as $name) {
            $this->addSql(
                "DELETE FROM `" . _DB_PREFIX_ . "configuration` WHERE `name` = ?",
                [$name]
            );
        }
    }
}
