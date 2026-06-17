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

if (!defined('_PS_VERSION_')) {
    exit;
}

class YapeSettingsService
{
    /**
     * @var \mercadopago
     */
    private $module;

    /**
     * @var ConfigurationDataService
     */
    private $configDataService;

    public function __construct(\mercadopago $module)
    {
        $this->module = $module;
        $this->configDataService = new ConfigurationDataService();
    }

    public function processForm(): bool
    {
        if (Tools::isSubmit('submitYapeSettings')) {
            $enabled = (bool) Tools::getValue('MERCADOPAGO_YAPE_ENABLED', false);
            $discount = (float) Tools::getValue('MERCADOPAGO_YAPE_DISCOUNT', 0);

            if ($discount < 0 || $discount > 99) {
                $this->module->errors[] = $this->module->l('Discount must be between 0 and 99.');
                return false;
            }

            $results = [
                $this->configDataService->set('MERCADOPAGO_YAPE_ENABLED', $enabled),
                $this->configDataService->set('MERCADOPAGO_YAPE_DISCOUNT', $discount),
            ];

            if (!in_array(false, $results, true)) {
                $this->module->confirmations[] = $this->module->l('Yape settings updated successfully!');
                return true;
            } else {
                $this->module->errors[] = $this->module->l('Error updating Yape settings. Please try again.');
                return false;
            }
        }
        return true;
    }

    public function getFormData(): array
    {
        $enabled = (bool) $this->configDataService->get('MERCADOPAGO_YAPE_ENABLED');
        $discount = (float) $this->configDataService->get('MERCADOPAGO_YAPE_DISCOUNT', 0);

        return [
            'title' => $this->module->l('YAPE'),
            'icon' => 'icon-mobile',
            'description' => $this->module->l('Configure Yape payment method settings.'),
            'fields' => [
                [
                    'type' => 'switch',
                    'label' => $this->module->l('Enable Yape'),
                    'name' => 'MERCADOPAGO_YAPE_ENABLED',
                    'is_bool' => true,
                    'values' => [
                        [
                            'id' => 'yape_enabled_on',
                            'value' => 1,
                            'label' => $this->module->l('Enabled'),
                        ],
                        [
                            'id' => 'yape_enabled_off',
                            'value' => 0,
                            'label' => $this->module->l('Disabled'),
                        ],
                    ],
                    'desc' => $this->module->l('Enable or disable Yape payments.'),
                ],
                [
                    'type' => 'text',
                    'label' => $this->module->l('Discount (%)'),
                    'name' => 'MERCADOPAGO_YAPE_DISCOUNT',
                    'class' => 'fixed-width-sm',
                    'desc' => $this->module->l('Discount percentage for Yape payments (0-99).'),
                ],
            ],
            'submit_action' => 'submitYapeSettings',
            'values' => [
                'MERCADOPAGO_YAPE_ENABLED'  => $enabled ? '1' : '0',
                'MERCADOPAGO_YAPE_DISCOUNT' => (string) $discount,
            ],
        ];
    }

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
        $helper->currentIndex = $context->link->getAdminLink('AdminModules', false)
            . '&configure=' . $this->module->name
            . '&tab_module=' . $this->module->tab
            . '&module_name=' . $this->module->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = [
            'fields_value' => $formData['values'],
        ];

        return $helper->generateForm([$fieldsForm]);
    }
}
