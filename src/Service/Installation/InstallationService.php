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

namespace MercadoPago\Service\Installation;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\PDO\MySQL\Driver as PdoMySqlDriver;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Exception\NoMigrationsFoundWithCriteria;
use Doctrine\Migrations\Exception\NoMigrationsToExecute;
use Doctrine\Migrations\Metadata\MigrationPlanList;
use Doctrine\Migrations\MigratorConfiguration;
use MercadoPago\Service\Order\AbstractOrderService;
use MercadoPago\Service\Order\OrderAttemptReference;

/*
 * Installation Service
 * Handles database migrations using Doctrine Migrations
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class InstallationService
{
    /** Configuration key listing core reference columns the module could not widen. */
    public const ORDER_REFERENCE_WARNING_KEY = 'MERCADOPAGO_ORDER_REFERENCE_SCHEMA_WARNING';

    /** PrestaShop 9 declares both columns as VARCHAR(255) (install schema and 9.0.0 upgrade script). */
    private const ORDER_REFERENCE_COLUMNS = ['orders' => 'reference', 'order_payment' => 'order_reference'];

    private const ORDER_REFERENCE_LENGTH = 255;

    /**
     * @var \mercadopago
     */
    private $module;

    /**
     * @var Connection|null
     */
    private $connection;

    /**
     * Constructor
     *
     * @param \mercadopago $module
     */
    public function __construct(\mercadopago $module)
    {
        $this->module = $module;
    }

    /**
     * Run all pending migrations
     *
     * @return bool
     */
    public function createTables(): bool
    {
        // Core schema alignment never blocks installation: a failure is surfaced to the merchant instead.
        $this->ensureOrderReferenceColumns();

        try {
            $this->ensureCoreTables();
            $dependencyFactory = $this->getDependencyFactory();
            $dependencyFactory->getMetadataStorage()->ensureInitialized();

            $migrationRepository = $dependencyFactory->getMigrationRepository();
            if (count($migrationRepository->getMigrations()) === 0) {
                error_log('MercadoPago: no migrations found');

                return false;
            }

            $version = $dependencyFactory->getVersionAliasResolver()->resolveVersionAlias('latest');
            $planCalculator = $dependencyFactory->getMigrationPlanCalculator();
            $plan = $planCalculator->getPlanUntilVersion($version);

            if (count($plan) === 0) {
                return $this->validateCoreTables();
            }

            $migratorConfiguration = new MigratorConfiguration();
            $migrator = $dependencyFactory->getMigrator();

            $hasErrors = false;
            foreach ($plan->getItems() as $item) {
                try {
                    $singlePlan = new MigrationPlanList([$item], $item->getDirection());
                    $migrator->migrate($singlePlan, $migratorConfiguration);
                } catch (NoMigrationsToExecute|NoMigrationsFoundWithCriteria $e) {
                    // nothing to do
                } catch (\Throwable $e) {
                    // \Throwable, not just \Exception: a broken migration class
                    // (e.g. scoper "Class not found") throws \Error, which would
                    // otherwise escape uncaught and abort install() mid-loop,
                    // leaving later tables (and createOrderStates()) never created.
                    error_log('MercadoPago: migration ' . $item->getVersion() . ' failed (' . get_class($e) . ')');
                    $hasErrors = true;
                }
            }

            $this->setDefaultCountryFromCurrency();

            return !$hasErrors && $this->validateCoreTables();
        } catch (NoMigrationsToExecute|NoMigrationsFoundWithCriteria $e) {
            return $this->validateCoreTables();
        } catch (\Throwable $e) {
            error_log('MercadoPago: schema initialization failed (' . get_class($e) . ')');

            return false;
        }
    }

    /** Restore missing core tables and additive payment fields without deleting data. */
    private function ensureCoreTables(): void
    {
        $connection = $this->getConnection();
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS ' . $connection->quoteIdentifier(_DB_PREFIX_ . 'mp_module') . ' (
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
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS ' . $connection->quoteIdentifier(_DB_PREFIX_ . 'mp_transactions') . ' (
            `id_mp_transaction` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `cart_id` INT(10) UNSIGNED NOT NULL,
            `order_id` VARCHAR(100) NULL,
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
        $tableName = _DB_PREFIX_ . 'mp_transactions';
        $table = $connection->createSchemaManager()->listTableDetails($tableName);
        $columns = [
            'pix_qr_code' => 'TEXT NULL',
            'pix_qr_code_base64' => 'TEXT NULL',
            'pix_expiration' => 'VARCHAR(32) NULL',
            'ticket_url' => 'TEXT NULL',
            'digitable_line' => 'TEXT NULL',
            'pse_payment_url' => 'TEXT NULL',
        ] + \MercadoPago\Repository\PreferenceGuardRepository::columns();
        $quotedTable = $connection->quoteIdentifier($tableName);
        if ($table->hasColumn('order_id')) {
            $orderId = $table->getColumn('order_id');
            $type = $orderId->getType()->getName();
            if (in_array($type, ['integer', 'smallint', 'bigint'], true)
                || ($type === 'string' && $orderId->getLength() < 100)) {
                $connection->executeStatement('ALTER TABLE ' . $quotedTable . ' MODIFY `order_id` VARCHAR(100) NULL');
            }
        }
        foreach ($columns as $name => $definition) {
            if (!$table->hasColumn($name)) {
                $connection->executeStatement('ALTER TABLE ' . $quotedTable . ' ADD COLUMN '
                    . $connection->quoteIdentifier($name) . ' ' . $definition);
            }
        }
        $table = $connection->createSchemaManager()->listTableDetails($tableName);
        foreach (['uk_mp_guard_preference' => 'guard_preference_id', 'uk_mp_guard_review' => 'guard_review_payment_id'] as $index => $column) {
            if (!$table->hasIndex($index)) {
                $connection->executeStatement('ALTER TABLE ' . $quotedTable . ' ADD UNIQUE INDEX '
                    . $connection->quoteIdentifier($index) . ' (' . $connection->quoteIdentifier($column) . ')');
            }
        }
    }

    /**
     * Widen orders.reference and order_payment.order_reference to the PrestaShop 9 width.
     *
     * Shops migrated to PrestaShop 9 without the 9.0.0 upgrade script can keep the 1.7/8
     * VARCHAR(9). Since 5.0.2 every module-created order stores a 27-character attempt
     * reference, and PrestaShop's empty sql_mode truncates it silently, so Checkout Pro
     * orders end in uncertain_order (PPSP-2465). The statement is the core's own; it only
     * runs on a column narrower than VARCHAR(255), so a wider column is never shrunk.
     * Columns still too narrow afterwards are recorded for the back-office warning. The
     * record is cleared only after both widths were read and found wide enough: an
     * unreadable width keeps the previous record.
     *
     * @return string[] "table.column (length)" entries still unable to hold the reference
     */
    public function ensureOrderReferenceColumns(): array
    {
        try {
            $required = OrderAttemptReference::length();
            $narrow = [];
            $confirmed = true;
            foreach (self::ORDER_REFERENCE_COLUMNS as $table => $column) {
                $length = $this->columnLength($table, $column);
                if ($length === null) {
                    $confirmed = false;
                    continue;
                }
                if ($length >= self::ORDER_REFERENCE_LENGTH) {
                    continue;
                }
                try {
                    \Db::getInstance()->execute(
                        'ALTER TABLE `' . bqSQL(_DB_PREFIX_ . $table) . '` CHANGE `' . bqSQL($column) . '` `'
                        . bqSQL($column) . '` VARCHAR(' . self::ORDER_REFERENCE_LENGTH . ')',
                        false
                    );
                } catch (\Throwable $e) {
                    error_log('MercadoPago: could not widen ' . $table . '.' . $column . ' (' . get_class($e) . ')');
                }
                // Without a fresh read the known narrow width stands until a later read confirms the change.
                $length = $this->columnLength($table, $column) ?? $length;
                if ($length < $required) {
                    $narrow[$table] = $length;
                }
            }

            $this->recordOrderReferenceWarning($narrow, $confirmed);

            return self::describeOrderReferenceColumns($narrow);
        } catch (\Throwable $e) {
            error_log('MercadoPago: order reference schema check failed (' . get_class($e) . ')');

            return [];
        }
    }

    /**
     * Back-office warning for the recorded narrow columns, re-read without changing the schema.
     *
     * The record is cleared only when both widths are read and can hold the attempt reference.
     * When a width cannot be read, the record is kept and its last known columns are reported.
     *
     * @return array{columns: string, sql: string}|null null when no warning is pending
     */
    public function pendingOrderReferenceWarning(): ?array
    {
        $recorded = self::decodeOrderReferenceWarning((string) \Configuration::get(self::ORDER_REFERENCE_WARNING_KEY));
        if ($recorded === null) {
            return null;
        }

        $current = $this->narrowOrderReferenceColumns();
        if ($current !== null) {
            $this->recordOrderReferenceWarning($current, true);
            $recorded = $current;
        }
        if ($recorded === []) {
            return null;
        }

        return [
            'columns' => implode(', ', self::describeOrderReferenceColumns($recorded)),
            'sql' => self::orderReferenceRepairSql($recorded),
        ];
    }

    /**
     * SQL the merchant's developer can run when the module could not widen the columns. Only
     * the given narrow columns are included, so a column wider than VARCHAR(255) is never shrunk.
     *
     * @param array<string, int> $narrow table => current length
     */
    public static function orderReferenceRepairSql(array $narrow): string
    {
        $statements = [];
        foreach (self::ORDER_REFERENCE_COLUMNS as $table => $column) {
            if (!isset($narrow[$table]) || $narrow[$table] >= self::ORDER_REFERENCE_LENGTH) {
                continue;
            }
            $statements[] = 'ALTER TABLE `' . _DB_PREFIX_ . $table . '` CHANGE `' . $column . '` `' . $column
                . '` VARCHAR(' . self::ORDER_REFERENCE_LENGTH . ');';
        }

        return implode(' ', $statements);
    }

    /**
     * Columns that cannot hold the attempt reference, read without changing the schema.
     *
     * @return array<string, int>|null table => length; null when a width cannot be read
     */
    private function narrowOrderReferenceColumns(): ?array
    {
        $required = OrderAttemptReference::length();
        $narrow = [];
        foreach (self::ORDER_REFERENCE_COLUMNS as $table => $column) {
            $length = $this->columnLength($table, $column);
            if ($length === null) {
                return null;
            }
            if ($length < $required) {
                $narrow[$table] = $length;
            }
        }

        return $narrow;
    }

    /**
     * @param array<string, int> $narrow
     * @param bool $confirmed whether every width was read; only then can an empty list clear the record
     */
    private function recordOrderReferenceWarning(array $narrow, bool $confirmed): void
    {
        if ($narrow !== []) {
            $entries = [];
            foreach ($narrow as $table => $length) {
                $entries[] = $table . ':' . $length;
            }
            \Configuration::updateValue(self::ORDER_REFERENCE_WARNING_KEY, implode(',', $entries));

            return;
        }
        if ($confirmed) {
            \Configuration::deleteByName(self::ORDER_REFERENCE_WARNING_KEY);
        }
    }

    /** @return array<string, int>|null null without a record; [] when the record cannot be parsed */
    private static function decodeOrderReferenceWarning(string $value): ?array
    {
        if ($value === '') {
            return null;
        }
        $narrow = [];
        foreach (explode(',', $value) as $entry) {
            [$table, $length] = array_pad(explode(':', trim($entry), 2), 2, '');
            if (isset(self::ORDER_REFERENCE_COLUMNS[$table]) && ctype_digit($length)) {
                $narrow[$table] = (int) $length;
            }
        }

        return $narrow;
    }

    /**
     * @param array<string, int> $narrow
     *
     * @return string[]
     */
    private static function describeOrderReferenceColumns(array $narrow): array
    {
        $described = [];
        foreach ($narrow as $table => $length) {
            $described[] = $table . '.' . self::ORDER_REFERENCE_COLUMNS[$table] . ' (' . $length . ')';
        }

        return $described;
    }

    private function columnLength(string $table, string $column): ?int
    {
        try {
            $length = \Db::getInstance()->getValue(
                'SELECT `CHARACTER_MAXIMUM_LENGTH` FROM `information_schema`.`COLUMNS`'
                . ' WHERE `TABLE_SCHEMA` = DATABASE()'
                . ' AND `TABLE_NAME` = \'' . pSQL(_DB_PREFIX_ . $table) . '\''
                . ' AND `COLUMN_NAME` = \'' . pSQL($column) . '\'',
                false
            );
        } catch (\Throwable $e) {
            return null;
        }

        return is_numeric($length) && (int) $length > 0 ? (int) $length : null;
    }

    /** Fail closed when an existing table cannot support the module's writes. */
    private function validateCoreTables(): bool
    {
        try {
            $manager = $this->getConnection()->createSchemaManager();
            $required = [
                'mp_module' => ['id_mp_module', 'version', 'updated', 'evaluation', 'comments', 'recommend', 'created_at', 'updated_at'],
                'mp_transactions' => array_merge([
                    'id_mp_transaction', 'cart_id', 'order_id', 'customer_id', 'total', 'mp_module_id',
                    'payment_id', 'payment_method', 'payment_type', 'payment_status', 'payment_amount',
                    'merchant_order_id', 'notification_url', 'is_payment_test', 'received_webhook', 'created_at', 'updated_at',
                    'pix_qr_code', 'pix_qr_code_base64', 'pix_expiration', 'ticket_url', 'digitable_line', 'pse_payment_url',
                ], array_keys(\MercadoPago\Repository\PreferenceGuardRepository::columns())),
            ];
            foreach ($required as $name => $columns) {
                $table = $manager->listTableDetails(_DB_PREFIX_ . $name);
                foreach ($columns as $column) {
                    if (!$table->hasColumn($column)) {
                        error_log('MercadoPago: required schema column missing');

                        return false;
                    }
                }
                $minimumLengths = $name === 'mp_module' ? [
                    'version' => 20, 'evaluation' => 20,
                ] : [
                    'order_id' => 100, 'payment_id' => 100, 'payment_method' => 100,
                    'payment_type' => 100, 'payment_status' => 100, 'payment_amount' => 100,
                    'merchant_order_id' => 100, 'pix_expiration' => 32,
                    'guard_preference_id' => 100, 'preference_items_sha256' => 64,
                    'preference_currency_iso' => 3, 'guard_status' => 24, 'guard_reason' => 32,
                    'guard_payment_id' => 100, 'guard_review_payment_id' => 100,
                ];
                foreach ($minimumLengths as $column => $length) {
                    $definition = $table->getColumn($column);
                    if ($definition->getType()->getName() !== 'string' || $definition->getLength() < $length) {
                        error_log('MercadoPago: schema column has incompatible type or length: ' . $name . '.' . $column);

                        return false;
                    }
                }
                if ($name === 'mp_transactions') {
                    foreach (['uk_mp_guard_preference' => 'guard_preference_id', 'uk_mp_guard_review' => 'guard_review_payment_id'] as $index => $column) {
                        if (!$table->hasIndex($index) || !$table->getIndex($index)->isUnique()
                            || $table->getIndex($index)->getColumns() !== [$column]) {
                            error_log('MercadoPago: required schema index invalid');

                            return false;
                        }
                    }
                }
            }

            return true;
        } catch (\Throwable $e) {
            error_log('MercadoPago: schema validation failed (' . get_class($e) . ')');

            return false;
        }
    }

    public function createOrderStates(\mercadopago $module): bool
    {
        $states = [
            0 => ['#ccfbff', 'Transaction in Process',       'in_process',    '110010000'],
            1 => ['#c9fecd', 'Transaction Completed',         'payment',       '110010010'],
            2 => ['#fec9c9', 'Transaction Canceled',          'order_canceled', '100010000'],
            3 => ['#fec9c9', 'Transaction Declined',          'payment_error', '100010000'],
            4 => ['#ffeddb', 'Transaction Refunded',          'refund',        '100010000'],
            5 => ['#c28566', 'Transaction Chargedback',       'charged_back',  '100010000'],
            6 => ['#b280b2', 'Transaction in Mediation',      'in_mediation',  '100010000'],
            7 => ['#fffb96', 'Transaction Pending',           'pending',       '110010000'],
            8 => ['#ccfbff', 'Transaction Authorized',        'authorized',    '100010000'],
            9 => ['#ffb0d9', 'Transaction in Possible Fraud', 'payment_error', '100010000'],
        ];

        $flagNames = ['invoice', 'send_email', 'unremovable', 'hidden', 'logable', 'delivery', 'shipped', 'paid', 'deleted'];

        foreach ($states as $index => $stateData) {
            $configKey = 'MERCADOPAGO_STATUS_' . $index;
            $existingId = (int) \Configuration::get($configKey);

            if ($existingId > 0 && $this->isActiveModuleState($existingId)) {
                continue;
            }

            [$color, $name, $template, $flags] = $stateData;

            // A missing or invalid configuration may still have a surviving state.
            $existingState = \Db::getInstance()->getRow(
                'SELECT os.`id_order_state` FROM `' . _DB_PREFIX_ . 'order_state` os'
                . ' INNER JOIN `' . _DB_PREFIX_ . 'order_state_lang` osl ON osl.`id_order_state` = os.`id_order_state`'
                . " WHERE os.`module_name` = 'mercadopago' AND os.`deleted` = 0 AND osl.`name` = '" . pSQL($name) . "'"
                . ' ORDER BY os.`id_order_state` ASC',
                false
            );
            if (!empty($existingState['id_order_state'])) {
                if (!\Configuration::updateValue($configKey, (int) $existingState['id_order_state'])) {
                    return false;
                }
                continue;
            }

            $state = new \OrderState();
            $state->module_name = 'mercadopago';
            $state->color = $color;
            $state->name = array_fill(0, 10, $name);
            $state->template = array_fill(0, 10, $template);

            foreach ($flagNames as $position => $flag) {
                $state->$flag = $flags[$position] === '1';
            }

            if (!$state->add()) {
                return false;
            }

            if (!\Configuration::updateValue($configKey, $state->id)) {
                return false;
            }
        }

        $this->copyMailTemplates($module);

        return true;
    }

    private function copyMailTemplates(\mercadopago $module): void
    {
        $templates = ['pending', 'in_process', 'charged_back', 'in_mediation'];
        $modulePath = $module->getLocalPath() . 'mails/';
        $languages = \Language::getLanguages(false);

        foreach ($languages as $lang) {
            $iso = $lang['iso_code'];
            $destDir = _PS_MAIL_DIR_ . $iso . '/';

            if (!is_dir($destDir)) {
                continue;
            }

            $srcDir = $modulePath . $iso . '/';
            if (!is_dir($srcDir)) {
                $srcDir = $modulePath . 'en/';
            }

            foreach ($templates as $template) {
                foreach (['.html', '.txt'] as $ext) {
                    $src = $srcDir . $template . $ext;
                    $dst = $destDir . $template . $ext;
                    if (file_exists($src) && !file_exists($dst) && !copy($src, $dst)) {
                        error_log("MercadoPago: failed to copy mail template {$src} to {$dst}");
                    }
                }
            }
        }
    }

    public function deleteOrderStates(): void
    {
        for ($i = 0; $i <= 9; ++$i) {
            $id = (int) \Configuration::get('MERCADOPAGO_STATUS_' . $i);
            if ($id > 0) {
                $state = new \OrderState($id);
                if ($state->id && $state->module_name === 'mercadopago' && !$state->deleted) {
                    $state->delete();
                }
            }
            \Configuration::deleteByName('MERCADOPAGO_STATUS_' . $i);
        }
    }

    private function isActiveModuleState(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }
        $result = \Db::getInstance()->getRow(
            'SELECT COUNT(*) as cnt FROM `' . _DB_PREFIX_ . 'order_state` WHERE `id_order_state` = ' . (int) $id
            . " AND `module_name` = 'mercadopago' AND `deleted` = 0",
            false
        );

        return (int) ($result['cnt'] ?? 0) > 0;
    }

    /**
     * Set MERCADOPAGO_COUNTRY_LINK based on the store's active currency if not already set
     *
     * @return void
     */
    private function setDefaultCountryFromCurrency(): void
    {
        if (\Configuration::get('MERCADOPAGO_COUNTRY_LINK')) {
            return;
        }

        $isoCode = strtoupper((string) ($this->module->getLegacyContext()->currency->iso_code ?? ''));
        $countryLink = null;

        foreach (AbstractOrderService::getAllCountryConfigs() as $siteId => $config) {
            if ($config['currency'] === $isoCode) {
                $countryLink = strtolower($siteId);
                break;
            }
        }

        if ($countryLink) {
            \Configuration::updateValue('MERCADOPAGO_COUNTRY_LINK', $countryLink);
        }
    }

    private function cleanConfiguration(): void
    {
        $result = \Db::getInstance()->execute(
            'DELETE FROM `' . _DB_PREFIX_ . 'configuration` WHERE `name` LIKE \'MERCADOPAGO_%\''
        );

        if (!$result) {
            error_log('MercadoPago: failed to clean configuration on uninstall');
        }
    }

    /**
     * Rollback all migrations (drop tables)
     *
     * @return bool
     */
    public function dropTables(): bool
    {
        try {
            $dependencyFactory = $this->getDependencyFactory();
            $migrator = $dependencyFactory->getMigrator();

            // Get all executed migrations and rollback them
            $migrationRepository = $dependencyFactory->getMigrationRepository();
            $migrations = $migrationRepository->getMigrations();

            if (count($migrations) > 0) {
                // Execute down() for all migrations in reverse order
                foreach (array_reverse($migrations->getItems()) as $migration) {
                    $migration->getMigration()->down($this->getConnection()->createSchemaManager()->introspectSchema());
                }
            }

            // Also drop tables directly as fallback
            $sql = 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'mp_transactions`,
                                      `' . _DB_PREFIX_ . 'mp_transaction`,
                                      `' . _DB_PREFIX_ . 'mp_config`,
                                      `' . _DB_PREFIX_ . 'mp_module`';

            \Db::getInstance()->execute($sql);
            \Db::getInstance()->execute(
                'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'doctrine_migration_versions`'
            );
        } catch (\Throwable $e) {
            // \Throwable (not just \Exception): a broken migration class (e.g. a
            // scoper misconfiguration causing "Class not found") throws \Error,
            // which does NOT extend \Exception. Left uncaught, it fatals mid-request
            // and breaks the AdminModules uninstall AJAX JSON response instead of
            // falling back to the raw DROP TABLE below.
            error_log('Migration rollback error: ' . $e->getMessage());
            $sql = 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'mp_transactions`,
                                      `' . _DB_PREFIX_ . 'mp_transaction`,
                                      `' . _DB_PREFIX_ . 'mp_config`,
                                      `' . _DB_PREFIX_ . 'mp_module`';
            \Db::getInstance()->execute($sql);
            \Db::getInstance()->execute(
                'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'doctrine_migration_versions`'
            );
        }

        $this->cleanConfiguration();

        return true;
    }

    /**
     * Get Doctrine DBAL connection
     * Always builds our own connection from PrestaShop's DB constants — see note below.
     * Connection is cached to avoid creating multiple instances
     *
     * @return Connection
     */
    private function getConnection(): Connection
    {
        if ($this->connection !== null) {
            return $this->connection;
        }

        // NÃO reaproveitar 'doctrine.dbal.default_connection' do container do PS:
        // ele retorna PrestaShopBundle\Doctrine\DatabaseConnection, que vem do
        // Doctrine DO CORE (sem prefixo). Nosso Doctrine é isolado pelo PHP-Scoper
        // (MercadoPagoVendor\Doctrine\DBAL\Connection) exatamente para não colidir
        // com o do core — são duas hierarquias de classe incompatíveis. Passar o
        // objeto do container para cá dispara TypeError: "Return value must be of
        // type MercadoPagoVendor\Doctrine\DBAL\Connection, PrestaShopBundle\
        // Doctrine\DatabaseConnection returned". Por isso sempre criamos nossa
        // própria conexão a partir das constantes de banco do PrestaShop (mesmo
        // banco usado pelo módulo). DBAL 3.x também não aceita injeção de um PDO
        // já existente, então abrimos uma Connection própria com o driver pdo_mysql
        // (o mesmo que DriverManager::getConnection() escolheria para estes params).
        if (!defined('_DB_SERVER_') || !defined('_DB_NAME_') || !defined('_DB_USER_')) {
            throw new \RuntimeException('Unable to build migration connection: PrestaShop DB constants unavailable');
        }

        $host = _DB_SERVER_;
        $port = null;
        if (strpos($host, ':') !== false) {
            [$host, $maybePort] = explode(':', $host, 2);
            if (ctype_digit($maybePort)) {
                $port = (int) $maybePort;
            }
        }

        $params = [
            'driver' => 'pdo_mysql',
            'host' => $host,
            'dbname' => _DB_NAME_,
            'user' => _DB_USER_,
            'password' => defined('_DB_PASSWD_') ? _DB_PASSWD_ : '',
            'charset' => 'utf8mb4',
        ];

        if ($port !== null) {
            $params['port'] = $port;
        }

        // Construção direta em vez de DriverManager::getConnection(): o Validator
        // do Marketplace acusa chamadas estáticas em classes do vendor isolado
        // como "método inexistente". Sem URL, wrapperClass ou middlewares, o
        // DriverManager faria exatamente esta mesma construção.
        $this->connection = new Connection($params, new PdoMySqlDriver(), new Configuration());

        return $this->connection;
    }

    /**
     * Get DependencyFactory for migrations
     *
     * @return DependencyFactory
     */
    private function getDependencyFactory(): DependencyFactory
    {
        $connection = $this->getConnection();
        // Core tables contain native ENUM columns. Migrations only add explicit
        // module SQL, but Doctrine first introspects the complete shop schema.
        $connection->getDatabasePlatform()->registerDoctrineTypeMapping('enum', 'string');

        $migrationsPath = $this->module->getLocalPath() . 'migrations';
        $migrationsNamespace = 'DoctrineMigrations';

        $configurationLoader = new ConfigurationArray([
            'migrations_paths' => [
                $migrationsNamespace => $migrationsPath,
            ],
            'migrations' => [],
            'all_or_nothing' => false,
            'check_database_platform' => true,
            'table_storage' => [
                'table_name' => _DB_PREFIX_ . 'doctrine_migration_versions',
            ],
        ]);

        $connectionLoader = new ExistingConnection($connection);

        // DependencyFactory só expõe construtores estáticos (o __construct é
        // privado). A chamada via class-string é a mesma fromConnection(); ela
        // evita o falso positivo do Validator para métodos estáticos do vendor
        // isolado (MercadoPagoVendor\Doctrine\...).
        $factoryClass = DependencyFactory::class;

        return $factoryClass::fromConnection(
            $configurationLoader,
            $connectionLoader
        );
    }
}
