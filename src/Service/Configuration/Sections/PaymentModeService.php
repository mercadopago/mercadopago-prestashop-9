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
use Tools;
use HelperForm;
use Context;

/**
 * Payment Mode Service
 * Manages payment mode settings (Production or Sandbox)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class PaymentModeService
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
     * Constructor
     *
     * @param \mercadopago $module
     */
    public function __construct(\mercadopago $module)
    {
        $this->module = $module;
        $this->configDataService = new ConfigurationDataService();
    }

    /**
     * Process form submission
     *
     * @return bool True on success, false on error
     */
    public function processForm(): bool
    {
        if (Tools::isSubmit('submitPaymentMode')) {
            $prodStatus = (bool) Tools::getValue('MERCADOPAGO_PROD_STATUS', false);
            $result = $this->configDataService->set('MERCADOPAGO_PROD_STATUS', $prodStatus);
            if ($result) {
                $this->module->confirmations[] = $this->module->l('Payment mode updated successfully!');
                return true;
            } else {
                $this->module->errors[] = $this->module->l('Error updating payment mode. Please try again.');
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
        $prodStatusValue = $this->configDataService->get('MERCADOPAGO_PROD_STATUS');
        $prodStatus = ($prodStatusValue === null || $prodStatusValue === false || $prodStatusValue === '0') ? false : (bool) $prodStatusValue;

        return [
            'title' => $this->module->l('Payment Mode'),
            'icon' => 'icon-credit-card',
            'description' => $this->module->l('Select the payment mode for your store.'),
            'fields' => [
                [
                    'type' => 'select',
                    'label' => $this->module->l('Payment Mode'),
                    'name' => 'MERCADOPAGO_PROD_STATUS',
                    'required' => true,
                    'options' => [
                        'query' => $this->getPaymentModeOptions(),
                        'id' => 'id',
                        'name' => 'name',
                    ],
                    'desc' => $this->module->l('Choose between Sandbox (test) or Production mode.'),
                ],
            ],
            'submit_action' => 'submitPaymentMode',
            'values' => [
                'MERCADOPAGO_PROD_STATUS' => $prodStatus ? '1' : '0',
            ],
        ];
    }

    /**
     * Render payment mode form
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

        return $helper->generateForm([$fieldsForm]);
    }

    /**
     * Get payment mode options for select
     *
     * @return array
     */
    private function getPaymentModeOptions(): array
    {
        return [
            ['id' => '0', 'name' => $this->module->l('Sandbox (Test)')],
            ['id' => '1', 'name' => $this->module->l('Production')],
        ];
    }
}
