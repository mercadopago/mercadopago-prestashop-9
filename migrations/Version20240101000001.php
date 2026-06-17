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
 * Migration para criar tabela ps_mp_transaction com compatibilidade legacy
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

final class Version20240101000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create ps_mp_transaction table with legacy compatibility';
    }

    public function up(Schema $schema): void
    {
        // Cria tabela principal ps_mp_transaction
        $this->addSql('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'mp_transaction` (
            `id_mp_transaction` INT(11) NOT NULL AUTO_INCREMENT,
            `id_order` INT(11) NULL,
            `id_cart` INT(11) NOT NULL,
            `id_customer` INT(11) NOT NULL,
            `payment_method` VARCHAR(50) NOT NULL,
            `payment_type` VARCHAR(50) NOT NULL,
            `mp_payment_id` VARCHAR(50) NULL,
            `mp_preference_id` VARCHAR(50) NULL,
            `status` VARCHAR(50) NOT NULL DEFAULT "pending",
            `amount` DECIMAL(10,2) NOT NULL,
            `currency` VARCHAR(3) NOT NULL DEFAULT "BRL",
            `installments` INT(11) NULL,
            `card_token` VARCHAR(255) NULL,
            `pix_qr_code` TEXT NULL,
            `pix_qr_code_base64` TEXT NULL,
            `external_reference` VARCHAR(255) NULL,
            `notification_url` VARCHAR(255) NULL,
            `notification_data` JSON NULL,
            `failure_reason` VARCHAR(255) NULL,
            `status_detail` VARCHAR(100) NULL,
            `legacy_id` INT(11) NULL COMMENT "ID da tabela original para migração",
            `migrated_from` VARCHAR(50) NULL COMMENT "Nome da tabela de origem",
            `legacy_data` JSON NULL COMMENT "Dados originais para referência",
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id_mp_transaction`),
            INDEX `idx_order` (`id_order`),
            INDEX `idx_cart` (`id_cart`),
            INDEX `idx_customer` (`id_customer`),
            INDEX `idx_mp_payment_id` (`mp_payment_id`),
            INDEX `idx_mp_preference_id` (`mp_preference_id`),
            INDEX `idx_status` (`status`),
            INDEX `idx_payment_method` (`payment_method`),
            INDEX `idx_legacy_id` (`legacy_id`),
            INDEX `idx_migrated_from` (`migrated_from`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT="Transações MercadoPago com compatibilidade legacy"');

        // Migrar dados de possíveis tabelas legacy
        $this->migrateLegacyData();
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'mp_transaction`');
    }

    /**
     * Migra dados de tabelas legacy conhecidas
     */
    private function migrateLegacyData(): void
    {
        // Lista de possíveis tabelas legacy
        $legacyTables = [
            'ps_mercadopago_transactions',
            'ps_mercadopago_payments',
            'ps_mp_payments',
            'ps_mercadopago',
            'ps_mercadopago_data'
        ];

        foreach ($legacyTables as $tableName) {
            if ($this->tableExists($tableName)) {
                $this->migrateLegacyTable($tableName);
            }
        }
    }

    /**
     * Verifica se uma tabela existe
     */
    private function tableExists(string $tableName): bool
    {
        $result = $this->connection->executeQuery(
            "SELECT COUNT(*) FROM information_schema.tables 
             WHERE table_schema = DATABASE() AND table_name = ?",
            [$tableName]
        );

        return $result->fetchOne() > 0;
    }

    /**
     * Migra dados de uma tabela legacy específica
     */
    private function migrateLegacyTable(string $tableName): void
    {
        $this->write("Migrando dados da tabela legacy: {$tableName}");
        
        // Mapear campos comuns entre versões
        $fieldMapping = $this->getFieldMapping($tableName);
        
        if (empty($fieldMapping)) {
            $this->write("Nenhum mapeamento encontrado para: {$tableName}");
            return;
        }

        // Construir query de migração
        $selectFields = [];
        $insertFields = [];
        $insertValues = [];

        foreach ($fieldMapping as $newField => $oldField) {
            if ($this->columnExists($tableName, $oldField)) {
                $selectFields[] = $oldField;
                $insertFields[] = $newField;
                $insertValues[] = "?";
            }
        }

        if (empty($selectFields)) {
            $this->write("Nenhum campo compatível encontrado em: {$tableName}");
            return;
        }

        // Adicionar campos fixos de migração
        $insertFields[] = 'migrated_from';
        $insertFields[] = 'legacy_id';
        $insertFields[] = 'legacy_data';
        $insertValues[] = '?'; // migrated_from
        $insertValues[] = '?'; // legacy_id
        $insertValues[] = '?'; // legacy_data

        // Selecionar dados da tabela legacy
        $legacyData = $this->connection->executeQuery(
            "SELECT *, JSON_OBJECT() as full_data FROM {$tableName}"
        )->fetchAllAssociative();

        $migratedCount = 0;
        foreach ($legacyData as $row) {
            // Verificar se já foi migrado
            $legacyId = $row['id'] ?? $row['id_transaction'] ?? null;
            
            if (!$legacyId || $this->isAlreadyMigrated($legacyId, $tableName)) {
                continue;
            }

            // Preparar dados para inserção
            $insertData = [];
            foreach ($fieldMapping as $newField => $oldField) {
                if (isset($row[$oldField])) {
                    $insertData[] = $this->transformValue($newField, $row[$oldField]);
                }
            }

            // Adicionar dados de migração
            $insertData[] = $tableName; // migrated_from
            $insertData[] = $legacyId; // legacy_id
            $insertData[] = json_encode($row); // legacy_data (dados completos)

            try {
                $this->connection->executeStatement(
                    "INSERT INTO " . _DB_PREFIX_ . "mp_transaction (" . implode(', ', $insertFields) . ") 
                     VALUES (" . implode(', ', $insertValues) . ")",
                    $insertData
                );
                $migratedCount++;
            } catch (\Exception $e) {
                $this->write("Erro ao migrar registro {$legacyId}: " . $e->getMessage());
            }
        }

        $this->write("Migrados {$migratedCount} registros de {$tableName}");
    }

    /**
     * Verifica se uma coluna existe em uma tabela
     */
    private function columnExists(string $tableName, string $columnName): bool
    {
        $result = $this->connection->executeQuery(
            "SELECT COUNT(*) FROM information_schema.columns 
             WHERE table_schema = DATABASE() 
             AND table_name = ? 
             AND column_name = ?",
            [$tableName, $columnName]
        );

        return $result->fetchOne() > 0;
    }

    /**
     * Verifica se um registro já foi migrado
     */
    private function isAlreadyMigrated(int $legacyId, string $tableName): bool
    {
        $result = $this->connection->executeQuery(
            "SELECT COUNT(*) FROM " . _DB_PREFIX_ . "mp_transaction 
             WHERE legacy_id = ? AND migrated_from = ?",
            [$legacyId, $tableName]
        );

        return $result->fetchOne() > 0;
    }

    /**
     * Mapeamento de campos entre versões
     */
    private function getFieldMapping(string $tableName): array
    {
        $commonMappings = [
            'id_order' => 'id_order',
            'id_cart' => 'id_cart',
            'id_customer' => 'id_customer',
            'amount' => 'amount',
            'status' => 'status',
            'created_at' => 'date_add',
            'updated_at' => 'date_upd'
        ];

        // Mapeamentos específicos por tabela
        $specificMappings = [
            'ps_mercadopago_transactions' => [
                'mp_payment_id' => 'payment_id',
                'mp_preference_id' => 'preference_id',
                'payment_method' => 'payment_method_id',
                'payment_type' => 'payment_type_id',
                'external_reference' => 'external_reference',
                'status_detail' => 'status_detail'
            ],
            'ps_mercadopago_payments' => [
                'mp_payment_id' => 'mp_payment_id',
                'payment_method' => 'payment_method',
                'payment_type' => 'payment_type',
                'installments' => 'installments'
            ],
            'ps_mp_payments' => [
                'mp_payment_id' => 'payment_id',
                'mp_preference_id' => 'preference_id',
                'payment_method' => 'method',
                'payment_type' => 'type'
            ]
        ];

        return array_merge(
            $commonMappings,
            $specificMappings[$tableName] ?? []
        );
    }

    /**
     * Transforma valores durante a migração
     */
    private function transformValue(string $field, $value)
    {
        switch ($field) {
            case 'payment_type':
                // Normalizar tipos de pagamento
                if (empty($value)) {
                    return 'unknown';
                }
                return $value;

            case 'status':
                // Normalizar status
                $statusMap = [
                    'approved' => 'approved',
                    'pending' => 'pending',
                    'rejected' => 'rejected',
                    'cancelled' => 'cancelled',
                    'refunded' => 'refunded',
                    'charged_back' => 'charged_back'
                ];
                return $statusMap[$value] ?? 'pending';

            case 'amount':
                // Garantir formato decimal
                return is_numeric($value) ? (float) $value : 0.00;

            case 'created_at':
            case 'updated_at':
                // Normalizar datas
                if (empty($value) || $value === '0000-00-00 00:00:00') {
                    return $field === 'created_at' ? date('Y-m-d H:i:s') : null;
                }
                return $value;

            default:
                return $value;
        }
    }
}
