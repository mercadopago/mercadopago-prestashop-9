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
 * Ticket Checkout Service
 * Handles Ticket payment method display in checkout (all countries)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class TicketCheckoutService extends AbstractCheckoutService
{
    const ALLOWED_TYPES = ['ticket', 'atm'];
    const EXCLUDED_IDS  = ['MELIPLACE', 'PAYPAL', 'PSE'];

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
        return 'MERCADOPAGO_TICKET_ENABLED';
    }

    public function isAvailableForCountry(): bool
    {
        return true;
    }

    public function createPaymentOption(\Cart $cart): ?PaymentOption
    {
        if (!$this->isEnabled() || !$this->isAvailableForCountry()) {
            return null;
        }

        $actionUrl = Context::getContext()->link->getModuleLink($this->module->name, 'ticket', [], true);
        $siteId = strtoupper((string) \Configuration::get('MERCADOPAGO_COUNTRY_LINK'));
        $customer = Context::getContext()->customer;
        $address = new \Address((int) $cart->id_address_invoice);
        $state = new \State((int) $address->id_state);

        $option = new PaymentOption();
        $option->setModuleName($this->module->name);
        $option->setCallToActionText($this->module->l('Ticket'));
        $option->setAction($actionUrl);
        $option->setAdditionalInformation(
            $this->templateRenderer->render('client/templates/checkout/ticket-checkout.twig', [
                'action_url'      => $actionUrl,
                'payment_methods' => $this->getTicketPaymentMethods(),
                'site_id'         => $siteId,
                'terms_url'       => $this->getTermsUrl($siteId),
                'module_dir'      => $this->module->getPathUri(),
                'firstname'       => $customer->firstname,
                'lastname'        => $customer->lastname,
                'address'         => $address->address1,
                'city'            => $address->city,
                'state'           => $state->iso_code ?? '',
                'zipcode'         => $address->postcode,
            ])
        );

        return $option;
    }

    /**
     * Override of the parent normalize that preserves `payment_places` so the
     * checkout can render sub-options (e.g. paycash networks: 7-Eleven, Soriana).
     */
    public static function normalizePaymentMethod(array $value): array
    {
        $places = array_values(array_filter(
            $value['payment_places'] ?? [],
            static fn ($place) => is_array($place) && !empty($place['payment_option_id'])
        ));

        return array_merge(parent::normalizePaymentMethod($value), [
            'payment_places' => $places,
        ]);
    }

    /**
     * Filters raw API response keeping only ticket/atm methods, excluding EXCLUDED_IDS.
     * Shared between checkout and admin settings to avoid filter divergence.
     *
     * @param array $response Raw API response
     * @return array Filtered raw methods
     */
    public static function filterRawPaymentMethods(array $response): array
    {
        $methods = [];

        foreach ($response as $raw) {
            if (!in_array($raw['payment_type_id'] ?? '', self::ALLOWED_TYPES, true)) {
                continue;
            }

            $id = strtoupper($raw['id'] ?? '');

            if (in_array($id, self::EXCLUDED_IDS, true)) {
                continue;
            }

            $methods[] = $raw;
        }

        return $methods;
    }

    /**
     * Fetches ticket-related payment methods from the API.
     * Only includes types 'ticket' and 'atm', respecting admin per-method config.
     * Excludes meliplace, PAYPAL and PSE.
     *
     * @return array
     */
    private function getTicketPaymentMethods(): array
    {
        $result = $this->mpApi->getPaymentMethods();

        if (!$result || ($result['status'] ?? 0) >= 400) {
            return [];
        }

        $methods = [];

        foreach (self::filterRawPaymentMethods($result['response']) as $raw) {
            $id = strtoupper($raw['id'] ?? '');

            if (empty(\Configuration::get('MERCADOPAGO_TICKET_PAYMENT_' . $id))) {
                continue;
            }

            $methods[] = self::normalizePaymentMethod($raw);
        }

        usort($methods, fn ($a, $b) => (int) $a['sort'] <=> (int) $b['sort']);

        self::markDefaultMethod($methods);

        return $methods;
    }

    /**
     * Flags the first renderable entry (or first payment_place of it) as the
     * default, so the template can pre-select it without depending on JS.
     */
    private static function markDefaultMethod(array &$methods): void
    {
        if (empty($methods)) {
            return;
        }

        if (!empty($methods[0]['payment_places'])) {
            $methods[0]['payment_places'][0]['is_default'] = true;
            return;
        }

        $methods[0]['is_default'] = true;
    }
}
