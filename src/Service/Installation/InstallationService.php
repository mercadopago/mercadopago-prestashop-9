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

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Exception\NoMigrationsFoundWithCriteria;
use Doctrine\Migrations\Exception\NoMigrationsToExecute;
use Doctrine\Migrations\Metadata\MigrationPlanList;
use Doctrine\Migrations\MigratorConfiguration;
use MercadoPago\Service\Order\AbstractOrderService;

/**
 * Installation Service
 * Handles database migrations using Doctrine Migrations
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class InstallationService
{
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
        try {
            $dependencyFactory = $this->getDependencyFactory();
            $dependencyFactory->getMetadataStorage()->ensureInitialized();

            $migrationRepository = $dependencyFactory->getMigrationRepository();
            if (count($migrationRepository->getMigrations()) === 0) {
                return true;
            }

            $version = $dependencyFactory->getVersionAliasResolver()->resolveVersionAlias('latest');
            $planCalculator = $dependencyFactory->getMigrationPlanCalculator();
            $plan = $planCalculator->getPlanUntilVersion($version);

            if (count($plan) === 0) {
                return true;
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
                } catch (\Exception $e) {
                    error_log('Migration ' . $item->getVersion() . ' failed: ' . $e->getMessage());
                    $hasErrors = true;
                }
            }

            $this->setDefaultCountryFromCurrency();

            return !$hasErrors;
        } catch (NoMigrationsToExecute|NoMigrationsFoundWithCriteria $e) {
            return true;
        } catch (\Exception $e) {
            error_log('Migration setup error: ' . $e->getMessage());
            return false;
        }
    }

    public function createOrderStates(\mercadopago $module): bool
    {
        $states = [
            0 => ['#ccfbff', 'Transaction in Process',       'in_process',    '110010000'],
            1 => ['#c9fecd', 'Transaction Completed',         'payment',       '110010010'],
            2 => ['#fec9c9', 'Transaction Canceled',          'order_canceled','100010000'],
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

            if ($existingId > 0 && $this->orderStateExists($existingId)) {
                continue;
            }

            [$color, $name, $template, $flags] = $stateData;

            $state = new \OrderState();
            $state->module_name = 'mercadopago';
            $state->color = $color;
            $state->name = array_fill(0, 10, $name);
            $state->template = array_fill(0, 10, $template);

            foreach ($flagNames as $position => $flag) {
                $state->$flag = (int) $flags[$position];
            }

            if (!$state->add()) {
                return false;
            }

            \Configuration::updateValue($configKey, $state->id);
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
                    if (file_exists($src) && !file_exists($dst)) {
                        copy($src, $dst);
                    }
                }
            }
        }
    }

    public function deleteOrderStates(): void
    {
        for ($i = 0; $i <= 9; $i++) {
            $id = (int) \Configuration::get('MERCADOPAGO_STATUS_' . $i);
            if ($id > 0) {
                $state = new \OrderState($id);
                if ($state->id) {
                    $state->delete();
                }
            }
            \Configuration::deleteByName('MERCADOPAGO_STATUS_' . $i);
        }
    }

    private function orderStateExists(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }
        $result = \Db::getInstance()->getRow(
            'SELECT COUNT(*) as cnt FROM `' . _DB_PREFIX_ . 'order_state` WHERE `id_order_state` = ' . (int) $id,
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

        $isoCode = strtoupper((string) (\Context::getContext()->currency->iso_code ?? ''));
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
        } catch (\Exception $e) {
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
     * Tries to get from PrestaShop container first, falls back to creating from PrestaShop's DB connection
     * Connection is cached to avoid creating multiple instances
     *
     * @return Connection
     */
    private function getConnection(): Connection
    {
        if ($this->connection !== null) {
            return $this->connection;
        }

        // Try to get connection from PrestaShop container
        try {
            $context = \Context::getContext();
            if ($context && isset($context->controller)) {
                $controller = $context->controller;
                if (method_exists($controller, 'getContainer')) {
                    $container = $controller->getContainer();
                    if ($container && $container->has('doctrine.dbal.default_connection')) {
                        $this->connection = $container->get('doctrine.dbal.default_connection');
                        return $this->connection;
                    }
                }
            }
        } catch (\Exception $e) {
            // Container not available, continue to fallback
        }

        // Fora do contexto web (ex.: CLI) o container não está disponível. O DBAL 3.x
        // não aceita injeção de um PDO existente, então criamos a conexão a partir dos
        // parâmetros de banco do PrestaShop (mesmo banco usado pelo módulo).
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
            'driver'   => 'pdo_mysql',
            'host'     => $host,
            'dbname'   => _DB_NAME_,
            'user'     => _DB_USER_,
            'password' => defined('_DB_PASSWD_') ? _DB_PASSWD_ : '',
            'charset'  => 'utf8mb4',
        ];

        if ($port !== null) {
            $params['port'] = $port;
        }

        $this->connection = DriverManager::getConnection($params);
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

        return DependencyFactory::fromConnection(
            $configurationLoader,
            $connectionLoader
        );
    }
}
