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

use MercadoPago\Client\MPApi;
use MercadoPago\Service\Configuration\ConfigurationDataService;
use MercadoPago\Service\Configuration\TemplateRenderer;
use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

/**
 * Preference Checkout Service (Checkout Pro)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class PreferenceCheckoutService extends AbstractCheckoutService
{
    /**
     * @var MPApi
     */
    private $mpApi;

    /**
     * @param \mercadopago $module
     * @param ConfigurationDataService|null $configDataService
     * @param TemplateRenderer|null $templateRenderer
     */
    public function __construct(
        \mercadopago $module,
        ?ConfigurationDataService $configDataService = null,
        ?TemplateRenderer $templateRenderer = null
    ) {
        parent::__construct($module, $configDataService, $templateRenderer);
        $this->mpApi = new MPApi();
    }

    /**
     * Returns the configuration key used to check if Checkout Pro is enabled.
     *
     * @return string
     */
    protected function getEnabledConfigKey(): string
    {
        return 'MERCADOPAGO_STANDARD_ENABLED';
    }

    /**
     * Creates the Checkout Pro payment option with icons, accepted methods and redirect info.
     *
     * @param \Cart $cart
     * @return PaymentOption|null
     */
    public function createPaymentOption(\Cart $cart): ?PaymentOption
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $country = \Configuration::get('MERCADOPAGO_COUNTRY_LINK');
        $isModal = (bool) $this->configDataService->get('MERCADOPAGO_STANDARD_MODAL');
        $actionUrl = \Context::getContext()->link->getModuleLink($this->module->name, 'preference', [], true);
        $failureUrl = \Context::getContext()->link->getPageLink('order', true, null, ['step' => 3, 'typeReturn' => 'failure']);

        $prodStatus = $this->configDataService->get('MERCADOPAGO_PROD_STATUS');
        $publicKeyConfig = $prodStatus ? 'MERCADOPAGO_PUBLIC_KEY' : 'MERCADOPAGO_SANDBOX_PUBLIC_KEY';
        $publicKey = $this->configDataService->get($publicKeyConfig, '');

        $option = new PaymentOption();
        $option->setModuleName($this->module->name);
        $option->setCallToActionText($this->module->l('Pay with Mercado Pago'));
        $option->setLogo('https://http2.mlstatic.com/storage/cpp/static-files/306698cd-ff92-4cc0-801c-1ca35d06ed5a.png');
        $option->setAction($actionUrl);
        $option->setAdditionalInformation(
            $this->templateRenderer->render('client/templates/checkout/preference-checkout.twig', [
                'preferenceIcons' => $this->getIconsDetails($country),
                'tarjetas' => $this->getPreferencePaymentMethods(),
                'module_dir' => $this->module->getPathUri(),
                'is_modal' => $isModal,
                'public_key' => $publicKey,
                'action_url' => $actionUrl,
                'failure_url' => $failureUrl,
            ])
        );

        return $option;
    }

    /**
     * Get icon details by country for the standard checkout display.
     *
     * @param string
     * @return array Array of icon objects with 'name' and 'link' properties
     */
    private function getIconsDetails(string $country): array
    {
        $iconDetails = [
            'shield' => 'https://http2.mlstatic.com/storage/cpp/static-files/65ff41dd-41fd-4a16-8c80-eaf1ee6a247b.png',
            'wallet' => 'https://http2.mlstatic.com/storage/cpp/static-files/11291e4a-d090-4904-b439-80afa83d473c.png',
            'dollar_sign' => 'https://http2.mlstatic.com/storage/cpp/static-files/93224a93-ad4e-4b93-84cf-5e1e74df7d7c.png',
        ];

        $iconMap = [
            'MLB' => ['shield', 'dollar_sign'],
            'MCO' => ['wallet', 'dollar_sign'],
            'MLA' => ['wallet', 'dollar_sign'],
            'MLC' => ['wallet', 'shield'],
            'MLM' => ['shield', 'dollar_sign'],
            'MLU' => ['wallet', 'shield'],
            'MLV' => ['wallet', 'shield'],
            'MPE' => ['wallet', 'shield'],
        ];

        $defaultIcons = ['wallet', 'shield'];
        $iconsToReturn = $iconMap[strtoupper($country)] ?? $defaultIcons;

        $icons = [];
        foreach ($iconsToReturn as $iconName) {
            if (isset($iconDetails[$iconName])) {
                $icon = new \stdClass();
                $icon->name = $iconName;
                $icon->link = $iconDetails[$iconName];
                $icons[] = $icon;
            }
        }

        return $icons;
    }

    /**
     * Fetches payment methods from the API, deduplicates, filters by sort, orders and limits to 7.
     *
     * @return array Array of payment methods with 'id', 'name', 'type', 'image' and 'sort' propertie
     */
    private function getPreferencePaymentMethods(): array
    {
        $result = $this->mpApi->getPaymentMethods();

        if (!$result || $result['status'] > 202) {
            return [];
        }

        $uniqueIds = [];
        $methods = [];

        foreach ($result['response'] as $raw) {
            $method = self::normalizePaymentMethod($raw);

            if (in_array($method['id'], $uniqueIds)) {
                continue;
            }
            $uniqueIds[] = $method['id'];

            if ((int) $method['sort'] === 999) {
                continue;
            }

            $methods[] = $method;
        }

        usort($methods, fn($a, $b) => (int) $a['sort'] <=> (int) $b['sort']);

        return array_slice($methods, 0, 7);
    }
}
