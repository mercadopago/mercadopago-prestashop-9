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
use Context;

/**
 * PSE Checkout Service
 * Handles PSE payment method display in checkout (Colombia only)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class PseCheckoutService extends AbstractCheckoutService
{
    /**
     * @var MPApi
     */
    private $mpApi;

    public function __construct(
        \mercadopago $module,
        ?ConfigurationDataService $configDataService = null,
        ?TemplateRenderer $templateRenderer = null,
        ?MPApi $mpApi = null
    ) {
        parent::__construct($module, $configDataService, $templateRenderer);
        $this->mpApi = $mpApi ?? new MPApi();
    }

    protected function getEnabledConfigKey(): string
    {
        return 'MERCADOPAGO_PSE_ENABLED';
    }

    public function isAvailableForCountry(): bool
    {
        $siteId = $this->configDataService->get('MERCADOPAGO_COUNTRY_LINK');
        return strtolower((string) $siteId) === 'mco';
    }

    /**
     * Returns the data needed to populate the PSE checkout selects:
     * person types (hardcoded), allowed identification types and financial
     * institutions (both fetched from the Mercado Pago API).
     *
     * @return array
     */
    public function getPaymentMethodInfo(): array
    {
        $pseMethod = $this->getPsePaymentMethod();

        return [
            'person_types' => [
                ['id' => 'individual',  'name' => $this->module->l('Individual PSE')],
                ['id' => 'association', 'name' => $this->module->l('Association')],
            ],
            'allowed_identification_types' => $pseMethod['allowed_identification_types'] ?? [],
            'financial_institutions'       => $pseMethod['financial_institutions'] ?? [],
        ];
    }

    /**
     * Fetches the PSE payment method object from the Mercado Pago API.
     * Fails soft to an empty array so the checkout never breaks.
     *
     * @return array
     */
    private function getPsePaymentMethod(): array
    {
        try {
            $result = $this->mpApi->getPaymentMethods();
        } catch (\Exception $e) {
            \PrestaShopLogger::addLog(
                '[MercadoPago PSE] Failed to fetch payment methods: ' . $e->getMessage(),
                3
            );
            return [];
        }

        $httpStatus = $result['status'] ?? 0;
        $response = $result['response'] ?? [];

        if ($httpStatus < 200 || $httpStatus >= 300 || empty($response)) {
            return [];
        }

        foreach ($response as $method) {
            if (isset($method['id']) && strtolower((string) $method['id']) === 'pse') {
                return $method;
            }
        }

        return [];
    }

    public function createPaymentOption(\Cart $cart): ?PaymentOption
    {
        if (!$this->isEnabled() || !$this->isAvailableForCountry()) {
            return null;
        }

        $actionUrl = Context::getContext()->link->getModuleLink($this->module->name, 'pse', [], true);

        $option = new PaymentOption();
        $option->setModuleName($this->module->name);
        $option->setCallToActionText($this->module->l('PSE'));
        $option->setAction($actionUrl);
        $option->setAdditionalInformation(
            $this->templateRenderer->render('client/templates/checkout/pse-checkout.twig', [
                'action_url'          => $actionUrl,
                'terms_url'           => $this->getTermsUrl('MCO'),
                'payment_method_info' => $this->getPaymentMethodInfo(),
            ])
        );

        return $option;
    }
}
