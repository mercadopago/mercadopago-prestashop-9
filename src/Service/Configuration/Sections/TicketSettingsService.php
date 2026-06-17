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

namespace MercadoPago\Service\Configuration\Sections;

use MercadoPago\Client\MPApi;
use MercadoPago\Service\Checkout\Methods\TicketCheckoutService;
use MercadoPago\Service\Configuration\ConfigurationDataService;
use MercadoPago\Service\Configuration\TemplateRenderer;
use Tools;
use HelperForm;
use Context;

/**
 * Ticket Settings Service
 * Manages Ticket payment method configuration
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class TicketSettingsService
{

    /**
     * @var \mercadopago
     */
    private $module;

    /**
     * @var ConfigurationDataService
     */
    private $configDataService;

    /**
     * @var MPApi
     */
    private $mpApi;

    /**
     * @var TemplateRenderer
     */
    private $templateRenderer;

    /**
     * @var array|null
     */
    private $paymentMethods;

    /**
     * Constructor
     *
     * @param \mercadopago $module
     * @param ConfigurationDataService|null $configDataService
     * @param MPApi|null $mpApi
     * @param TemplateRenderer|null $templateRenderer
     */
    public function __construct(
        \mercadopago $module,
        ?ConfigurationDataService $configDataService = null,
        ?MPApi $mpApi = null,
        ?TemplateRenderer $templateRenderer = null
    ) {
        $this->module = $module;
        $this->configDataService = $configDataService ?? new ConfigurationDataService();
        $this->mpApi = $mpApi ?? new MPApi();
        $this->templateRenderer = $templateRenderer ?? new TemplateRenderer($module);
    }

    /**
     * Process form submission
     *
     * @return bool True on success, false on error
     */
    public function processForm(): bool
    {
        if (Tools::isSubmit('submitTicketSettings')) {
            if (!$this->validatePaymentMethods()) {
                return false;
            }

            $enabled = (int) Tools::getValue('MERCADOPAGO_TICKET_ENABLED', 0);
            $expiration = trim((string) Tools::getValue('MERCADOPAGO_TICKET_EXPIRATION', ''));
            $discount = trim((string) Tools::getValue('MERCADOPAGO_TICKET_DISCOUNT', ''));

            if (!$this->validateExpiration($expiration)) {
                return false;
            }

            if (!$this->validateDiscount($discount)) {
                return false;
            }

            $expiration = $expiration === '' ? '3' : $expiration;
            $discount = $discount === '' ? 0 : (int) $discount;

            $results = [
                $this->configDataService->set('MERCADOPAGO_TICKET_ENABLED', $enabled),
                $this->configDataService->set('MERCADOPAGO_TICKET_EXPIRATION', $expiration),
                $this->configDataService->set('MERCADOPAGO_TICKET_DISCOUNT', $discount),
            ];

            foreach ($this->getTicketPaymentMethods() as $method) {
                $key = 'MERCADOPAGO_TICKET_PAYMENT_' . $method['id'];
                $results[] = $this->configDataService->set($key, Tools::getValue($key, ''));
            }

            if (!in_array(false, $results, true)) {
                $this->module->confirmations[] = $this->module->l('Settings saved successfully.');
                return true;
            } else {
                $this->module->errors[] = $this->module->l('Error updating Ticket/Boleto settings. Please try again.');
                return false;
            }
        }
        return true;
    }

    /**
     * Validates that at least one payment method is enabled
     *
     * @return bool
     */
    private function validatePaymentMethods(): bool
    {
        $methods = $this->getTicketPaymentMethods();

        if (empty($methods)) {
            $this->module->errors[] = $this->module->l(
                'Could not retrieve payment methods. Please check your credentials and try again.'
            );
            return false;
        }

        foreach ($methods as $method) {
            if (Tools::getValue('MERCADOPAGO_TICKET_PAYMENT_' . $method['id'], '') !== '') {
                return true;
            }
        }

        $this->module->errors[] = $this->module->l(
            'It is not possible to remove all payment methods for ticket checkout.'
        );
        return false;
    }

    /**
     * Validates the ticket expiration value.
     * Accepts empty string (no expiration set) or an integer between 1 and 29.
     *
     * @param string $expiration
     * @return bool
     */
    private function validateExpiration(string $expiration): bool
    {
        if ($expiration === '') {
            return true;
        }

        if (!ctype_digit($expiration) || (int) $expiration < 1 || (int) $expiration > 29) {
            $this->module->errors[] = $this->module->l(
                'Ticket expiration must be an integer between 1 and 29 days.'
            );
            return false;
        }

        return true;
    }

    /**
     * Validates the ticket discount value.
     * Accepts empty string (defaults to 0) or an integer between 0 and 99.
     *
     * @param string $discount
     * @return bool
     */
    private function validateDiscount(string $discount): bool
    {
        if ($discount === '') {
            return true;
        }

        if (!ctype_digit($discount) || (int) $discount > 99) {
            $this->module->errors[] = $this->module->l(
                'Discount must be an integer between 0 and 99.'
            );
            return false;
        }

        return true;
    }

    /**
     * Returns ticket payment methods from the API, filtered and normalized for admin use.
     * Applies the same filters as the checkout: types ticket/atm, excludes meliplace/PAYPAL/PSE.
     * Result is cached in $this->paymentMethods to avoid multiple API calls per request.
     *
     * @return array Each entry has 'id' (uppercase) and 'name' (with payment_places appended if any)
     */
    private function getTicketPaymentMethods(): array
    {
        if ($this->paymentMethods !== null) {
            return $this->paymentMethods;
        }

        $result = $this->mpApi->getPaymentMethods();

        if (!$result || ($result['status'] ?? 0) >= 400) {
            return $this->paymentMethods = [];
        }

        $methods = [];

        foreach (TicketCheckoutService::filterRawPaymentMethods($result['response']) as $raw) {
            $id   = strtoupper($raw['id'] ?? '');
            $name = $raw['name'] ?? $id;

            if (!empty($raw['payment_places']) && is_array($raw['payment_places'])) {
                $places = array_column($raw['payment_places'], 'name');
                $name .= ' (' . implode(', ', $places) . ')';
            }

            $methods[] = ['id' => $id, 'name' => $name];
        }

        return $this->paymentMethods = $methods;
    }

    /**
     * Get form data for rendering
     *
     * @return array
     */
    public function getFormData(): array
    {
        $enabledValue = $this->configDataService->get('MERCADOPAGO_TICKET_ENABLED');
        $enabled = ($enabledValue === null || $enabledValue === false || $enabledValue === '0') ? false : (bool) $enabledValue;

        $expiration = (string) $this->configDataService->get('MERCADOPAGO_TICKET_EXPIRATION', '');
        $discount = (int) $this->configDataService->get('MERCADOPAGO_TICKET_DISCOUNT', 0);

        $ticketMethods = $this->getTicketPaymentMethods();

        $values = [
            'MERCADOPAGO_TICKET_ENABLED'    => $enabled ? '1' : '0',
            'MERCADOPAGO_TICKET_EXPIRATION' => $expiration,
            'MERCADOPAGO_TICKET_DISCOUNT'   => (string) $discount,
        ];

        foreach ($ticketMethods as $method) {
            $values['MERCADOPAGO_TICKET_PAYMENT_' . $method['id']] =
                $this->configDataService->get('MERCADOPAGO_TICKET_PAYMENT_' . $method['id'], '');
        }

        return [
            'title'       => $this->module->l('Basic Configuration'),
            'icon'        => 'icon-cogs',
            'description' => $this->module->l('Your customer pays quickly, easily and securely with these options:'),
            'fields'      => [
                [
                    'type'    => 'switch',
                    'label'   => $this->module->l('Activate checkout for in-person payments'),
                    'name'    => 'MERCADOPAGO_TICKET_ENABLED',
                    'is_bool' => true,
                    'values'  => [
                        ['id' => 'active_on',  'value' => 1, 'label' => $this->module->l('Active')],
                        ['id' => 'active_off', 'value' => 0, 'label' => $this->module->l('Inactive')],
                    ],
                    'desc' => $this->module->l('Activate the in-person payment option in your store.'),
                ],
                [
                    'col'    => 4,
                    'type'   => 'checkbox',
                    'label'  => $this->module->l('In-person payment methods'),
                    'name'   => 'MERCADOPAGO_TICKET_PAYMENT',
                    'values' => [
                        'query' => $ticketMethods,
                        'id'    => 'id',
                        'name'  => 'name',
                    ],
                ],
                [
                    'type'        => 'text',
                    'label'       => $this->module->l('Payment expiration'),
                    'name'        => 'MERCADOPAGO_TICKET_EXPIRATION',
                    'class'  => 'fixed-width-sm',
                    'suffix' => $this->module->l('days'),
                    'desc'   => $this->module->l('In how many days will payments via boleto and lottery expire.'),
                ],
                [
                    'type'   => 'text',
                    'label'  => $this->module->l('Discount per purchase'),
                    'name'   => 'MERCADOPAGO_TICKET_DISCOUNT',
                    'class'  => 'fixed-width-sm',
                    'suffix' => '%',
                    'desc'   => $this->module->l('Offer a special discount to motivate your customers to make the purchase with Mercado Pago.'),
                ],
            ],
            'submit_action' => 'submitTicketSettings',
            'values'        => $values,
        ];
    }

    /**
     * Render Ticket settings form
     *
     * @return string
     */
    public function render(): string
    {
        $formData = $this->getFormData();

        $fieldsForm = [
            'form' => [
                'legend' => [
                    'title' => $formData['title'],
                    'icon' => $formData['icon'],
                ],
                'description' => $formData['description'],
                'input' => $formData['fields'],
                'submit' => [
                    'title' => $this->module->l('Save'),
                    'class' => 'btn btn-default pull-right',
                ],
            ],
        ];

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->module = $this->module;
        $helper->default_form_language = (int) \Configuration::get('PS_LANG_DEFAULT');
        $helper->allow_employee_form_lang = \Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ?: 0;
        $helper->submit_action = $formData['submit_action'];
        $context = Context::getContext();
        $helper->currentIndex = $context->link->getAdminLink('AdminModules', false) .
            '&configure=' . $this->module->name . '&tab_module=' . $this->module->tab . '&module_name=' . $this->module->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = [
            'fields_value' => $formData['values'],
        ];

        return $this->renderMasterCheckbox()
            . $this->renderAdvancedConfig()
            . $this->renderBullets()
            . $helper->generateForm([$fieldsForm]);
    }

    private function renderMasterCheckbox(): string
    {
        return $this->templateRenderer->render('admin/templates/ticket-master-checkbox.twig', [
            'select_payments' => $this->module->l('Select in-person payments'),
        ]);
    }

    private function renderAdvancedConfig(): string
    {
        return $this->templateRenderer->render('admin/templates/advanced-config.twig', [
            'header_id'      => 'mp-ticket-advanced-header',
            'desc_id'        => 'mp-ticket-advanced-desc',
            'plus_id'        => 'mp-header-plus-ticket',
            'less_id'        => 'mp-header-less-ticket',
            'advanced_title' => $this->module->l('Advanced Configuration'),
            'advanced_desc'  => $this->module->l('Activate other tools in our module ready to use.'),
        ]);
    }

    private function renderBullets(): string
    {
        return $this->templateRenderer->render('admin/templates/payment-method-benefits.twig', [
            'items' => [
                $this->module->l('Offer payments in cash.'),
                $this->module->l('Payment experience in your store.'),
                $this->module->l('Your customers pay as guests without leaving your store.'),
            ],
        ]);
    }
}
