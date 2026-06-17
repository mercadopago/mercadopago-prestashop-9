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

use MercadoPago\Service\Configuration\ConfigurationDataService;
use MercadoPago\Service\Configuration\TemplateRenderer;
use Tools;
use HelperForm;
use Context;

/**
 * Custom Settings Service
 * Manages CUSTOM CHECKOUT payment method configuration
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class CardSettingsService
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
     * @var TemplateRenderer
     */
    private $templateRenderer;

    /**
     * Constructor
     *
     * @param \mercadopago $module
     * @param TemplateRenderer|null $templateRenderer
     */
    public function __construct(\mercadopago $module, ?TemplateRenderer $templateRenderer = null)
    {
        $this->module = $module;
        $this->configDataService = new ConfigurationDataService();
        $this->templateRenderer = $templateRenderer ?? new TemplateRenderer($module);
    }

    /**
     * Process form submission
     *
     * @return bool True on success, false on error
     */
    public function processForm(): bool
    {
        if (Tools::isSubmit('submitCustomSettings')) {
            $enabled      = (int) Tools::getValue('MERCADOPAGO_CUSTOM_ENABLED', 0);
            $walletButton = (int) Tools::getValue('MERCADOPAGO_CUSTOM_WALLET_BUTTON', 0);
            $binaryMode   = (int) Tools::getValue('MERCADOPAGO_CUSTOM_BINARY_MODE', 0);
            $discount     = trim((string) Tools::getValue('MERCADOPAGO_CUSTOM_DISCOUNT_PERCENT', ''));

            if (!$this->validateDiscount($discount)) {
                return false;
            }

            $discountPercent = $discount === '' ? 0 : (int) $discount;

            $results = [
                $this->configDataService->set('MERCADOPAGO_CUSTOM_ENABLED', $enabled),
                $this->configDataService->set('MERCADOPAGO_CUSTOM_WALLET_BUTTON', $walletButton),
                $this->configDataService->set('MERCADOPAGO_CUSTOM_BINARY_MODE', $binaryMode),
                $this->configDataService->set('MERCADOPAGO_CUSTOM_DISCOUNT_PERCENT', $discountPercent),
            ];

            if (!\in_array(false, $results, true)) {
                $this->module->confirmations[] = $this->module->l('Settings saved successfully.');
                return true;
            } else {
                $this->module->errors[] = $this->module->l('Error updating Custom Checkout settings. Please try again.');
                return false;
            }
        }
        return true;
    }

    /**
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
     * Maps country codes to their MercadoPago costs-section URLs.
     *
     * @param string $country
     * @return string
     */
    private function getCostsSectionUrl(string $country): string
    {
        $urls = [
            'mla' => 'https://www.mercadopago.com.ar/costs-section#from-section=menu',
            'mlb' => 'https://www.mercadopago.com.br/costs-section#from-section=menu',
            'mlc' => 'https://www.mercadopago.cl/costs-section#from-section=menu',
            'mco' => 'https://www.mercadopago.com.co/costs-section#from-section=menu',
            'mlm' => 'https://www.mercadopago.com.mx/costs-section#from-section=menu',
            'mpe' => 'https://www.mercadopago.com.pe/costs-section#from-section=menu',
            'mlu' => 'https://www.mercadopago.com.uy/costs-section#from-section=menu',
        ];
        return $urls[$country] ?? 'https://www.mercadopago.com/costs-section#from-section=menu';
    }

    /**
     * Get form data for rendering
     *
     * @return array
     */
    public function getFormData(): array
    {
        $enabledValue = $this->configDataService->get('MERCADOPAGO_CUSTOM_ENABLED');
        $enabled = ($enabledValue === null || $enabledValue === false || $enabledValue === '0') ? false : (bool) $enabledValue;

        $walletButtonValue = $this->configDataService->get('MERCADOPAGO_CUSTOM_WALLET_BUTTON');
        $walletButton = true;
        if ($walletButtonValue !== null) {
            $walletButton = $walletButtonValue !== false && $walletButtonValue !== '0' && $walletButtonValue !== 0;
        }

        $binaryModeValue = $this->configDataService->get('MERCADOPAGO_CUSTOM_BINARY_MODE');
        $binaryMode = ($binaryModeValue === null || $binaryModeValue === false || $binaryModeValue === '0') ? false : (bool) $binaryModeValue;

        $discountPercent = (int) $this->configDataService->get('MERCADOPAGO_CUSTOM_DISCOUNT_PERCENT', 0);

        return [
            'title'       => $this->module->l('Basic Configuration'),
            'icon'        => 'icon-cogs',
            'description' => $this->module->l('Your customer pays quickly, easily and securely with these options:'),
            'fields' => [
                [
                    'type' => 'switch',
                    'label' => $this->module->l('Activate checkout'),
                    'name' => 'MERCADOPAGO_CUSTOM_ENABLED',
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
                    'desc' => $this->module->l('Activate the Mercado Pago experience at your store checkout.'),
                ],
                [
                    'type' => 'switch',
                    'label' => $this->module->l('Activate payments with cards saved in Mercado Pago'),
                    'name' => 'MERCADOPAGO_CUSTOM_WALLET_BUTTON',
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
                    'desc' => $this->module->l('Customers pay faster and you increase conversion using this feature'),
                ],
                [
                    'type' => 'switch',
                    'label' => $this->module->l('Binary mode'),
                    'name' => 'MERCADOPAGO_CUSTOM_BINARY_MODE',
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
                    'desc' => $this->module->l('Approve or reject payments instantly and automatically, without pending or review status. Do you want to enable this feature?'),
                ],
                [
                    'type' => 'text',
                    'label' => $this->module->l('Discount per purchase'),
                    'name' => 'MERCADOPAGO_CUSTOM_DISCOUNT_PERCENT',
                    'class' => 'fixed-width-sm',
                    'suffix' => '%',
                    'desc' => $this->module->l('Offer a special discount to motivate your customers to make the purchase with Mercado Pago.'),
                ],
            ],
            'submit_action' => 'submitCustomSettings',
            'values' => [
                'MERCADOPAGO_CUSTOM_ENABLED'          => $enabled ? '1' : '0',
                'MERCADOPAGO_CUSTOM_WALLET_BUTTON'    => $walletButton ? '1' : '0',
                'MERCADOPAGO_CUSTOM_BINARY_MODE'      => $binaryMode ? '1' : '0',
                'MERCADOPAGO_CUSTOM_DISCOUNT_PERCENT' => (string) $discountPercent,
            ],
        ];
    }

    /**
     * Render custom settings form
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

        return $this->renderAdvancedConfig()
            . $this->renderConfigureFees()
            . $this->renderBullets()
            . $helper->generateForm([$fieldsForm]);
    }

    private function renderConfigureFees(): string
    {
        $country = strtolower((string) ($this->configDataService->get('MERCADOPAGO_COUNTRY_LINK') ?? 'mlb'));

        return $this->templateRenderer->render('admin/templates/configure-costs-section.twig', [
            'title'       => $this->module->l('Configure your installments and interest'),
            'description' => $this->module->l('In Mercado Pago you can choose the fee you will pay on each purchase and also offer interest-free installments to your customer.'),
            'button_text' => $this->module->l('Set up installments and interest'),
            'url'         => $this->getCostsSectionUrl($country),
        ]);
    }

    private function renderAdvancedConfig(): string
    {
        return $this->templateRenderer->render('admin/templates/advanced-config.twig', [
            'header_id'      => 'mp-card-advanced-header',
            'desc_id'        => 'mp-card-advanced-desc',
            'plus_id'        => 'mp-header-plus-card',
            'less_id'        => 'mp-header-less-card',
            'advanced_title' => $this->module->l('Advanced Configuration'),
            'advanced_desc'  => $this->module->l('Activate other tools in our module ready to use.'),
        ]);
    }

    private function renderBullets(): string
    {
        return $this->templateRenderer->render('admin/templates/payment-method-benefits.twig', [
            'items' => [
                $this->module->l('Offer credit, debit card and Mercado Pago balance payments.'),
                $this->module->l('Payment experience within your store.'),
                $this->module->l('Your customers pay as guests without leaving your store.'),
            ],
        ]);
    }
}
