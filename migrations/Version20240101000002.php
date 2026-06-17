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
 * Migration para criar tabela ps_mp_config
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

final class Version20240101000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create ps_mp_config table for MercadoPago configurations';
    }

    public function up(Schema $schema): void
    {
        // Cria tabela de configurações
        $this->addSql('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'mp_config` (
            `id_mp_config` INT(11) NOT NULL AUTO_INCREMENT,
            `config_key` VARCHAR(100) NOT NULL UNIQUE,
            `config_value` TEXT NULL,
            `config_type` VARCHAR(20) NOT NULL DEFAULT "string",
            `is_public` TINYINT(1) NOT NULL DEFAULT 0,
            `description` TEXT NULL,
            `group_name` VARCHAR(50) NULL,
            `sort_order` INT(11) NULL DEFAULT 0,
            `is_required` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id_mp_config`),
            UNIQUE KEY `idx_config_key` (`config_key`),
            INDEX `idx_config_type` (`config_type`),
            INDEX `idx_is_public` (`is_public`),
            INDEX `idx_group_name` (`group_name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT="Configurações do módulo MercadoPago"');

        // Inserir configurações padrão
        $this->insertDefaultConfigurations();
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'mp_config`');
    }

    /**
     * Insere configurações padrão do módulo
     */
    private function insertDefaultConfigurations(): void
    {
        $defaultConfigs = [
            // Credenciais
            [
                'key' => 'mp_access_token',
                'value' => null,
                'type' => 'string',
                'is_public' => false,
                'description' => 'Token de acesso do MercadoPago',
                'group' => 'credentials',
                'sort_order' => 1,
                'is_required' => true
            ],
            [
                'key' => 'mp_public_key',
                'value' => null,
                'type' => 'string',
                'is_public' => false,
                'description' => 'Chave pública do MercadoPago',
                'group' => 'credentials',
                'sort_order' => 2,
                'is_required' => true
            ],
            [
                'key' => 'mp_client_id',
                'value' => null,
                'type' => 'string',
                'is_public' => false,
                'description' => 'Client ID da aplicação',
                'group' => 'credentials',
                'sort_order' => 3,
                'is_required' => false
            ],
            [
                'key' => 'mp_client_secret',
                'value' => null,
                'type' => 'string',
                'is_public' => false,
                'description' => 'Client Secret da aplicação',
                'group' => 'credentials',
                'sort_order' => 4,
                'is_required' => false
            ],
            // Configurações gerais
            [
                'key' => 'mp_sandbox_mode',
                'value' => '1',
                'type' => 'boolean',
                'is_public' => true,
                'description' => 'Modo sandbox/teste ativo',
                'group' => 'general',
                'sort_order' => 10,
                'is_required' => false
            ],
            [
                'key' => 'mp_debug_mode',
                'value' => '0',
                'type' => 'boolean',
                'is_public' => true,
                'description' => 'Modo debug ativo para logs',
                'group' => 'general',
                'sort_order' => 11,
                'is_required' => false
            ],
            [
                'key' => 'mp_webhook_url',
                'value' => null,
                'type' => 'string',
                'is_public' => true,
                'description' => 'URL do webhook para notificações',
                'group' => 'general',
                'sort_order' => 12,
                'is_required' => false
            ],
            // Configurações PIX
            [
                'key' => 'mp_pix_enabled',
                'value' => '1',
                'type' => 'boolean',
                'is_public' => true,
                'description' => 'PIX habilitado',
                'group' => 'pix',
                'sort_order' => 20,
                'is_required' => false
            ],
            [
                'key' => 'mp_pix_discount_percent',
                'value' => '0',
                'type' => 'float',
                'is_public' => true,
                'description' => 'Desconto percentual para PIX',
                'group' => 'pix',
                'sort_order' => 21,
                'is_required' => false
            ],
            [
                'key' => 'mp_pix_expiration_minutes',
                'value' => '30',
                'type' => 'integer',
                'is_public' => true,
                'description' => 'Tempo de expiração do PIX em minutos',
                'group' => 'pix',
                'sort_order' => 22,
                'is_required' => false
            ],
            // Configurações de cartão
            [
                'key' => 'mp_card_enabled',
                'value' => '1',
                'type' => 'boolean',
                'is_public' => true,
                'description' => 'Cartão de crédito habilitado',
                'group' => 'card',
                'sort_order' => 30,
                'is_required' => false
            ],
            [
                'key' => 'mp_card_max_installments',
                'value' => '12',
                'type' => 'integer',
                'is_public' => true,
                'description' => 'Máximo de parcelas permitidas',
                'group' => 'card',
                'sort_order' => 31,
                'is_required' => false
            ],
            [
                'key' => 'mp_card_min_installment_amount',
                'value' => '5.00',
                'type' => 'float',
                'is_public' => true,
                'description' => 'Valor mínimo por parcela',
                'group' => 'card',
                'sort_order' => 32,
                'is_required' => false
            ],
            // Configurações de boleto
            [
                'key' => 'mp_ticket_enabled',
                'value' => '1',
                'type' => 'boolean',
                'is_public' => true,
                'description' => 'Boleto habilitado',
                'group' => 'ticket',
                'sort_order' => 40,
                'is_required' => false
            ],
            [
                'key' => 'mp_ticket_expiration_days',
                'value' => '3',
                'type' => 'integer',
                'is_public' => true,
                'description' => 'Dias para vencimento do boleto',
                'group' => 'ticket',
                'sort_order' => 41,
                'is_required' => false
            ]
        ];

        foreach ($defaultConfigs as $config) {
            $this->addSql(
                'INSERT IGNORE INTO ' . _DB_PREFIX_ . 'mp_config 
                (config_key, config_value, config_type, is_public, description, group_name, sort_order, is_required) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $config['key'],
                    $config['value'],
                    $config['type'],
                    $config['is_public'] ? 1 : 0,
                    $config['description'],
                    $config['group'],
                    $config['sort_order'],
                    $config['is_required'] ? 1 : 0
                ]
            );
        }
    }
}
