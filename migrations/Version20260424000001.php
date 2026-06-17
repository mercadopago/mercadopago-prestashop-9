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

final class Version20260424000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add PSE payment_url column to mp_transactions table';
    }

    public function up(Schema $schema): void
    {
        $table = _DB_PREFIX_ . 'mp_transactions';
        $column = 'pse_payment_url';

        $exists = $this->connection->fetchOne(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            [$table, $column]
        );
        if (!$exists) {
            $this->addSql("ALTER TABLE `{$table}` ADD COLUMN `{$column}` TEXT NULL AFTER `digitable_line`");
        }
    }

    public function down(Schema $schema): void
    {
        $table = _DB_PREFIX_ . 'mp_transactions';
        $column = 'pse_payment_url';

        $exists = $this->connection->fetchOne(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            [$table, $column]
        );
        if ($exists) {
            $this->addSql("ALTER TABLE `{$table}` DROP COLUMN `{$column}`");
        }
    }
}
