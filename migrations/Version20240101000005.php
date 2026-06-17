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

final class Version20240101000005 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Migrate MERCADOPAGO_STANDARD_EXPIRATION_DAYS (days) to MERCADOPAGO_EXPIRATION_DATE_TO (hours)';
    }

    public function up(Schema $schema): void
    {
        // Read the old value (days) from ps_configuration
        $oldValue = $this->connection->fetchOne(
            "SELECT `value` FROM `" . _DB_PREFIX_ . "configuration` WHERE `name` = 'MERCADOPAGO_STANDARD_EXPIRATION_DAYS' LIMIT 1"
        );

        if ($oldValue !== false && $oldValue !== '') {
            $hours = (int) $oldValue * 24;
            $this->addSql(
                "INSERT INTO `" . _DB_PREFIX_ . "configuration` (`name`, `value`, `date_add`, `date_upd`)
                 VALUES ('MERCADOPAGO_EXPIRATION_DATE_TO', :hours, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE `value` = IF(`value` = '', :hours, `value`), `date_upd` = NOW()",
                ['hours' => (string) $hours]
            );
        }

        // Remove the old key
        $this->addSql(
            "DELETE FROM `" . _DB_PREFIX_ . "configuration` WHERE `name` = 'MERCADOPAGO_STANDARD_EXPIRATION_DAYS'"
        );
    }

    public function down(Schema $schema): void
    {
        // Read the current value (hours) and convert back to days
        $hours = $this->connection->fetchOne(
            "SELECT `value` FROM `" . _DB_PREFIX_ . "configuration` WHERE `name` = 'MERCADOPAGO_EXPIRATION_DATE_TO' LIMIT 1"
        );

        if ($hours !== false && $hours !== '') {
            $days = (int) ceil((int) $hours / 24);
            $this->addSql(
                "INSERT INTO `" . _DB_PREFIX_ . "configuration` (`name`, `value`, `date_add`, `date_upd`)
                 VALUES ('MERCADOPAGO_STANDARD_EXPIRATION_DAYS', :days, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE `value` = :days, `date_upd` = NOW()",
                ['days' => (string) $days]
            );
        }

        $this->addSql(
            "DELETE FROM `" . _DB_PREFIX_ . "configuration` WHERE `name` = 'MERCADOPAGO_EXPIRATION_DATE_TO'"
        );
    }
}
