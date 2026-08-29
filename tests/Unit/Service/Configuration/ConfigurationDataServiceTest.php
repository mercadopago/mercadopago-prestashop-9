<?php

declare(strict_types=1);

namespace {
    if (!defined('_PS_VERSION_')) {
        define('_PS_VERSION_', '9.1.5');
    }

    class Configuration
    {
        public static array $global = [];
        public static array $shop = [];
        public static array $lastUpdate = [];
        public static array $lastDelete = [];

        public static function get($key, $idLang = null, $idShopGroup = null, $idShop = null, $default = false)
        {
            return self::$shop[$idShop][$key] ?? self::$global[$key] ?? $default;
        }

        public static function getGlobalValue($key)
        {
            return self::$global[$key] ?? false;
        }

        public static function hasKey($key, $idLang = null, $idShopGroup = null, $idShop = null): bool
        {
            return array_key_exists($key, self::$shop[$idShop] ?? []);
        }

        public static function updateValue($key, $value, $html = false, $idShopGroup = null, $idShop = null): bool
        {
            self::$lastUpdate = [$key, $value, $html, $idShopGroup, $idShop];

            return true;
        }

        public static function deleteFromGivenContext($key, $idShopGroup, $idShop): void
        {
            self::$lastDelete = [$key, $idShopGroup, $idShop];
        }

        public static function deleteByName($key): bool
        {
            self::$lastDelete = [$key, null, null];

            return true;
        }
    }

    class Context
    {
        public object $shop;
        private static ?self $instance = null;

        public static function getContext(): self
        {
            return self::$instance ??= new self();
        }
    }

    class Shop
    {
        public static function getContextShopID($nullValueWithoutMultishop = false): int
        {
            return (int) Context::getContext()->shop->id;
        }

        public static function getContextShopGroupID($nullValueWithoutMultishop = false): int
        {
            return 10;
        }

        public static function getGroupFromShop($idShop, $asId = false): int
        {
            return 10;
        }
    }
}

namespace MercadoPago\Tests\Unit\Service\Configuration {
    use Configuration;
    use Context;
    use MercadoPago\Service\Configuration\ConfigurationDataService;
    use PHPUnit\Framework\TestCase;

    final class ConfigurationDataServiceTest extends TestCase
    {
        protected function setUp(): void
        {
            Configuration::$global = [
                'PS_SHOP_DEFAULT' => 1,
                'MERCADOPAGO_ACCESS_TOKEN' => 'legacy-token',
            ];
            Configuration::$shop = [];
            Configuration::$lastUpdate = [];
            Configuration::$lastDelete = [];
            Context::getContext()->shop = (object) ['id' => 1];
        }

        public function testDefaultShopKeepsLegacyGlobalCredentials(): void
        {
            self::assertSame('legacy-token', (new ConfigurationDataService())->get('MERCADOPAGO_ACCESS_TOKEN', ''));
        }

        public function testSecondaryShopDoesNotInheritGlobalCredentials(): void
        {
            Context::getContext()->shop = (object) ['id' => 2];

            self::assertSame('', (new ConfigurationDataService())->get('MERCADOPAGO_ACCESS_TOKEN', ''));
        }

        public function testSecondaryShopReadsItsOwnCredentials(): void
        {
            Context::getContext()->shop = (object) ['id' => 2];
            Configuration::$shop[2]['MERCADOPAGO_ACCESS_TOKEN'] = 'shop-2-token';

            self::assertSame('shop-2-token', (new ConfigurationDataService())->get('MERCADOPAGO_ACCESS_TOKEN', ''));
        }

        public function testWritesAndDeletesUseCurrentShopContext(): void
        {
            Context::getContext()->shop = (object) ['id' => 2];
            $service = new ConfigurationDataService();

            self::assertTrue($service->set('MERCADOPAGO_ACCESS_TOKEN', 'new-token'));
            self::assertSame(['MERCADOPAGO_ACCESS_TOKEN', 'new-token', false, 10, 2], Configuration::$lastUpdate);

            self::assertTrue($service->delete('MERCADOPAGO_ACCESS_TOKEN'));
            self::assertSame(['MERCADOPAGO_ACCESS_TOKEN', 10, 2], Configuration::$lastDelete);
        }

        public function testDefaultValueUsesConfigurationDefaultArgument(): void
        {
            unset(Configuration::$global['UNKNOWN_KEY']);

            self::assertSame('fallback', (new ConfigurationDataService())->get('UNKNOWN_KEY', 'fallback'));
        }
    }
}
