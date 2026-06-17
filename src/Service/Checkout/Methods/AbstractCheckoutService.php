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

namespace MercadoPago\Service\Checkout\Methods;

use MercadoPago\Service\Configuration\ConfigurationDataService;
use MercadoPago\Service\Configuration\TemplateRenderer;
use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

if (!defined('_PS_VERSION_')) {
    exit;
}

abstract class AbstractCheckoutService
{
    /**
     * @var \mercadopago
     */
    protected $module;

    /**
     * @var ConfigurationDataService
     */
    protected $configDataService;

    /**
     * @var TemplateRenderer
     */
    protected $templateRenderer;

    /**
     * @param \mercadopago $module
     * @param ConfigurationDataService|null
     * @param TemplateRenderer|null
     */
    public function __construct(
        \mercadopago $module,
        ?ConfigurationDataService $configDataService = null,
        ?TemplateRenderer $templateRenderer = null
    ) {
        $this->module = $module;
        $this->configDataService = $configDataService ?? new ConfigurationDataService();
        $this->templateRenderer = $templateRenderer ?? new TemplateRenderer($module);
    }

    /**
     * Returns the configuration key used to check if the checkout service is enabled
     *
     * @return string
     */
    abstract protected function getEnabledConfigKey(): string;

    /**
     * Checks if the checkout service is enabled based on the configuration key
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        $enabled = $this->configDataService->get($this->getEnabledConfigKey());
        return ($enabled === '1' || $enabled === 1 || $enabled === true);
    }

    /**
     * Normalizes the payment method data from the API response
     *
     * @param array $value Raw payment method from the API
     * @return array Normalized payment method
     */
    public static function normalizePaymentMethod(array $value): array
    {
        return [
            'id'    => $value['id'],
            'name'  => $value['name'],
            'type'  => $value['payment_type_id'],
            'image' => $value['secure_thumbnail'],
            'sort'  => $value['sort'] ?? 999,
        ];
    }

    /**
     * Returns the Terms and Conditions URL for the given country.
     *
     * @param string $siteId
     * @return string
     */
    protected function getTermsUrl(string $siteId): string
    {
        $siteId = strtoupper($siteId);

        $urls = [
            'MLA' => 'https://www.mercadopago.com.ar/ayuda/terminos-y-politicas_194',
            'MLB' => 'https://www.mercadopago.com.br/ajuda/termos-e-politicas_194',
            'MLC' => 'https://www.mercadopago.cl/ayuda/terminos-y-politicas_194',
            'MLM' => 'https://www.mercadopago.com.mx/ayuda/terminos-y-politicas_194',
            'MLU' => 'https://www.mercadopago.com.uy/ayuda/terminos-y-politicas_194',
            'MLV' => 'https://www.mercadopago.com.ve/ayuda/terminos-y-politicas_194',
            'MCO' => 'https://www.mercadopago.com.co/ayuda/terminos-y-politicas_194',
            'MPE' => 'https://www.mercadopago.com.pe/ayuda/terminos-y-politicas_194',
        ];

        return $urls[$siteId] ?? $urls['MLA'];
    }

    /**
     * Creates the payment option for the checkout service
     *
     * @param \Cart $cart
     * @return PaymentOption|null
     */
    abstract public function createPaymentOption(\Cart $cart): ?PaymentOption;
}
