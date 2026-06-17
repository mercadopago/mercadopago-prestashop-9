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

final class Version20240101000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create mp_transactions table for MercadoPago checkout custom';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'mp_transactions` (
            `id_mp_transaction` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `cart_id` INT(10) UNSIGNED NOT NULL,
            `order_id` INT(10) UNSIGNED NULL,
            `customer_id` INT(11) UNSIGNED NOT NULL,
            `total` DECIMAL(15,2) NULL,
            `mp_module_id` INT(11) NOT NULL,
            `payment_id` VARCHAR(100) NULL,
            `payment_method` VARCHAR(100) NULL,
            `payment_type` VARCHAR(100) NULL,
            `payment_status` VARCHAR(100) NULL,
            `payment_amount` VARCHAR(100) NULL,
            `merchant_order_id` VARCHAR(100) NULL,
            `notification_url` TEXT NULL,
            `is_payment_test` TINYINT(1) NULL,
            `received_webhook` TINYINT(1) NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id_mp_transaction`),
            INDEX `idx_cart_id` (`cart_id`),
            INDEX `idx_order_id` (`order_id`),
            INDEX `idx_merchant_order_id` (`merchant_order_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT="Transações MercadoPago (igual PPCO-3679)"');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'mp_transactions`');
    }
}
