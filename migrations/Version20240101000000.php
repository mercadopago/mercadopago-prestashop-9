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

/**
 * Auto-generated Migration: Please modify to your needs!
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

final class Version20240101000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create MP Module table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'mp_module` (
            `id_mp_module` INT(11) NOT NULL AUTO_INCREMENT,
            `version` VARCHAR(20) NOT NULL,
            `updated` TINYINT(1) NULL,
            `evaluation` VARCHAR(20) NULL,
            `comments` TINYTEXT NULL,
            `recommend` TINYINT(1) NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NULL,
            PRIMARY KEY (`id_mp_module`)
        ) ENGINE = InnoDB DEFAULT CHARSET=utf8;');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'mp_module`');
    }
}
