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
 * PSE Settings Service
 * Manages PSE payment method configuration
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class PseSettingsService
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
     * @param ConfigurationDataService|null $configDataService
     * @param TemplateRenderer|null $templateRenderer
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
     * Process form submission
     *
     * @return bool True on success, false on error
     */
    public function processForm(): bool
    {
        if (Tools::isSubmit('submitPseSettings')) {
            $enabled = (int) Tools::getValue('MERCADOPAGO_PSE_ENABLED', 0);
            $discount = trim((string) Tools::getValue('MERCADOPAGO_PSE_DISCOUNT_PERCENT', ''));

            if (!$this->validateDiscount($discount)) {
                return false;
            }

            $discount = $discount === '' ? 0 : (int) $discount;

            $enabledSet = $this->configDataService->set('MERCADOPAGO_PSE_ENABLED', $enabled);
            $discountPercentSet = $this->configDataService->set('MERCADOPAGO_PSE_DISCOUNT_PERCENT', $discount);
            
            if ($enabledSet && $discountPercentSet) {
                $this->module->confirmations[] = $this->module->l('Settings saved successfully.');
                return true;
            } else {
                $this->module->errors[] = $this->module->l('Error updating PSE settings. Please try again.');
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
     * Get form data for rendering
     *
     * @return array
     */
    public function getFormData(): array
    {
        $enabledValue = $this->configDataService->get('MERCADOPAGO_PSE_ENABLED');
        $enabled = ($enabledValue === null || $enabledValue === false || $enabledValue === '0') ? false : (bool) $enabledValue;

        $discountPercent = (int) $this->configDataService->get('MERCADOPAGO_PSE_DISCOUNT_PERCENT', 0);

        return [
            'title' => $this->module->l('Basic Configuration'),
            'icon' => 'icon-cogs',
            'description' => $this->module->l('Your customer will make their purchase quickly, easily and safely with these settings:'),
            'fields' => [
                [
                    'type' => 'switch',
                    'label' => $this->module->l('Payments via PSE'),
                    'name' => 'MERCADOPAGO_PSE_ENABLED',
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
                    'desc' => $this->module->l('By deactivating it, you will disable PSE payments from Mercado Pago Transparent Checkout.'),
                ],
                [
                    'type' => 'text',
                    'label' => $this->module->l('Discount for purchase'),
                    'name' => 'MERCADOPAGO_PSE_DISCOUNT_PERCENT',
                    'class' => 'fixed-width-sm',
                    'suffix' => '%',
                    'desc' => $this->module->l('Offer a special discount to encourage your customers to make the purchase with Mercado Pago.'),
                ],
            ],
            'submit_action' => 'submitPseSettings',
            'values' => [
                'MERCADOPAGO_PSE_ENABLED' => $enabled ? '1' : '0',
                'MERCADOPAGO_PSE_DISCOUNT_PERCENT' => (string) $discountPercent,
            ],
        ];
    }

    /**
     * Render PSE settings form
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
            . $this->renderBullets()
            . $helper->generateForm([$fieldsForm]);
    }

    private function renderAdvancedConfig(): string
    {
        return $this->templateRenderer->render('admin/templates/advanced-config.twig', [
            'header_id'      => 'mp-pse-advanced-header',
            'desc_id'        => 'mp-pse-advanced-desc',
            'plus_id'        => 'mp-header-plus-pse',
            'less_id'        => 'mp-header-less-pse',
            'advanced_title' => $this->module->l('Advanced Configuration'),
            'advanced_desc'  => $this->module->l('Activate other tools in our module ready to use.'),
        ]);
    }

    private function renderBullets(): string
    {
        return $this->templateRenderer->render('admin/templates/payment-method-benefits.twig', [
            'items' => [
                $this->module->l('Payment experience within your store.'),
                $this->module->l('Your customers pay as guests without leaving your store.'),
            ],
        ]);
    }
}
