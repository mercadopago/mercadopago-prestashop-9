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
use MercadoPago\Service\Configuration\ConfigurationDataService;
use MercadoPago\Service\Configuration\TemplateRenderer;
use Tools;
use HelperForm;
use Context;

/**
 * PIX Settings Service
 * Manages PIX payment method configuration
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class PixSettingsService
{
    private const VALID_EXPIRATIONS = [30, 60, 360, 720, 1440, 10080];
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
     * @var bool|null
     */
    private $pixEnabledForAccount;

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
        if (Tools::isSubmit('submitPixSettings')) {
            if (!$this->isPixEnabledForAccount()) {
                return true;
            }

            $enabled = (int) Tools::getValue('MERCADOPAGO_PIX_ENABLED', 0);
            $expiration = (int) Tools::getValue('MERCADOPAGO_PIX_EXPIRATION', 30);
            if (!in_array($expiration, self::VALID_EXPIRATIONS, true)) {
                $expiration = 30;
            }

            $discount = $this->validateDiscount();
            if ($discount === null) {
                return false;
            }

            $results = [
                $this->configDataService->set('MERCADOPAGO_PIX_ENABLED', $enabled),
                $this->configDataService->set('MERCADOPAGO_PIX_EXPIRATION', $expiration),
                $this->configDataService->set('MERCADOPAGO_PIX_DISCOUNT', $discount),
            ];
            
            if (!in_array(false, $results, true)) {
                $this->module->confirmations[] = $this->module->l('Settings saved successfully.');
                return true;
            } else {
                $this->module->errors[] = $this->module->l('Error updating PIX settings. Please try again.');
                return false;
            }
        }
        return true;
    }

    /**
     * Get form data for rendering
     *
     * @return array
     */
    public function getFormData(): array
    {
        $enabledValue = $this->configDataService->get('MERCADOPAGO_PIX_ENABLED');
        $enabled = ($enabledValue === null || $enabledValue === false || $enabledValue === '0') ? false : (bool) $enabledValue;

        $expiration = (int) $this->configDataService->get('MERCADOPAGO_PIX_EXPIRATION', 30);
        $discount = (int) $this->configDataService->get('MERCADOPAGO_PIX_DISCOUNT', 0);

        return [
            'title' => $this->module->l('Basic Configuration'),
            'icon' => 'icon-cogs',
            'description' => $this->module->l('Activate or disable Pix in your store and set the deadline to the purchase payment after the code is sent.'),
            'fields' => [
                [
                    'type' => 'switch',
                    'label' => $this->module->l('Pix payments'),
                    'name' => 'MERCADOPAGO_PIX_ENABLED',
                    'is_bool' => true,
                    'values' => [
                        [
                            'id' => 'active_on',
                            'value' => 1,
                            'label' => $this->module->l('Active'),
                        ],
                        [
                            'id' => 'active_off',
                            'value' => 0,
                            'label' => $this->module->l('Inactive'),
                        ],
                    ],
                    'desc' => $this->module->l('Allow clients to pay via Pix in the store checkout.'),
                ],
                [
                    'type' => 'select',
                    'label' => $this->module->l('Expiry date'),
                    'name' => 'MERCADOPAGO_PIX_EXPIRATION',
                    'options' => [
                        'query' => $this->getExpirationOptions(),
                        'id' => 'id',
                        'name' => 'name',
                    ],
                    'desc' => $this->module->l('Adjust the deadline that your clients will have to make the transfer via Pix.'),
                ],
                [
                    'type' => 'text',
                    'label' => $this->module->l('Discount per purchase via Pix (%)'),
                    'name' => 'MERCADOPAGO_PIX_DISCOUNT',
                    'class' => 'fixed-width-sm',
                    'desc' => $this->module->l('Enter the percentage of the discount to encourage your clients to pay via Pix.'),
                    'suffix' => '%',
                ],
            ],
            'submit_action' => 'submitPixSettings',
            'values' => [
                'MERCADOPAGO_PIX_ENABLED' => $enabled ? '1' : '0',
                'MERCADOPAGO_PIX_EXPIRATION' => (string) $expiration,
                'MERCADOPAGO_PIX_DISCOUNT' => (string) $discount,
            ],
        ];
    }

    /**
     * Render PIX settings form
     *
     * @return string
     */
    public function render(): string
    {
        if (!$this->isPixEnabledForAccount()) {
            return $this->renderBullets() . $this->renderRegistrationGuide();
        }

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

        $jsTranslations = '<script>var mpPixTranslations = {' .
            'advancedConfig: ' . json_encode($this->module->l('Advanced Configuration')) . ',' .
            'advancedDesc: ' . json_encode($this->module->l('Offer discounts on payments with Pix. The defined percentage will be deducted from the total purchase value.')) . ',' .
            'importantNote: ' . json_encode($this->module->l('Important: You can manage the Pix key(s) registered in your account through the Mercado Pago app.')) .
            '};</script>';

        return $jsTranslations . $this->renderBullets() . $helper->generateForm([$fieldsForm]);
    }

    /**
     * Returns false if the seller has no Pix key registered in their MP account.
     * Falls back to false on API errors so the form degrades gracefully.
     */
    private function isPixEnabledForAccount(): bool
    {
        if ($this->pixEnabledForAccount !== null) {
            return $this->pixEnabledForAccount;
        }

        try {
            $result = $this->mpApi->getPaymentMethods();
        } catch (\Exception $e) {
            return $this->pixEnabledForAccount = false;
        }

        if (!$result || ($result['status'] ?? 0) > 202 || empty($result['response'])) {
            return $this->pixEnabledForAccount = false;
        }

        foreach ($result['response'] as $method) {
            if (strtolower((string) ($method['id'] ?? '')) === 'pix') {
                return $this->pixEnabledForAccount = true;
            }
        }

        return $this->pixEnabledForAccount = false;
    }

    private function renderBullets(): string
    {
        return '<ul>' .
            '<li>' . $this->module->l('Offer an instant payment method, available 24 hours a day.') . '</li>' .
            '<li>' . $this->module->l('Receive the money from your sales in up to 10 seconds.') . '</li>' .
            '<li>' . $this->module->l('Have at your disposal fees lower than those of boleto and cards.') . '</li>' .
            '</ul>';
    }

    private function renderRegistrationGuide(): string
    {
        return $this->templateRenderer->render('admin/templates/pix-registration-guide.twig', [
            'title'           => $this->module->l('Basic Configuration'),
            'intro'           => $this->module->l('To receive payments via Pix you should have one or more keys registered in Mercado Pago.'),
            'steps_label'     => $this->module->l('Follow the steps below:'),
            'step1'           => $this->module->l('Download the Mercado Pago app in your mobile phone.'),
            'step2_pre'       => $this->module->l('On the left side menu, go to'),
            'step2_profile'   => $this->module->l('Your Profile'),
            'step2_mid'       => $this->module->l('and then, to'),
            'step2_keys'      => $this->module->l('Your Pix Keys.'),
            'step3'           => $this->module->l('Enter the details of the Pix Keys you would like to register and complete the process.'),
            'step4_pre'       => $this->module->l('Come back to your PrestaShop store admin and go to the'),
            'step4_tab'       => $this->module->l('Pix tab'),
            'step4_post'      => $this->module->l('to continue with the payment method configuration.'),
            'important_label' => $this->module->l('Important:'),
            'important_text'  => $this->module->l('Through Mercado Pago app you can manage the Pix keys you have registered in your account whenever you want.'),
            'bank_note'       => $this->module->l("At the moment, Brazil's Central Bank works from Monday to Friday, from 9 AM to 6 PM. Registrations made outside this period will be confirmed in the next business day."),
            'support_pre'     => $this->module->l('If you have already registered keys in Mercado Pago but you are not able to activate Pix in the Checkout,'),
            'support_link'    => $this->module->l('click here.'),
        ]);
    }

    /**
     * Get expiration options for select
     *
     * @return array
     */
    private function getExpirationOptions(): array
    {
        return [
            ['id' => '30', 'name' => $this->module->l('30 minutes')],
            ['id' => '60', 'name' => $this->module->l('1 hour')],
            ['id' => '360', 'name' => $this->module->l('6 hours')],
            ['id' => '720', 'name' => $this->module->l('12 hours')],
            ['id' => '1440', 'name' => $this->module->l('1 day')],
            ['id' => '10080', 'name' => $this->module->l('7 days')],
        ];
    }

    /** Accepts only integers between 0 and 99; returns null and adds error if invalid. */
    private function validateDiscount(): ?int
    {
        $discount = trim((string) Tools::getValue('MERCADOPAGO_PIX_DISCOUNT', ''));

        if ($discount === '') {
            return 0;
        }

        if (!ctype_digit($discount) || (int) $discount > 99) {
            $this->module->errors[] = $this->module->l('Discount must be an integer between 0 and 99.');
            return null;
        }

        return (int) $discount;
    }
}
