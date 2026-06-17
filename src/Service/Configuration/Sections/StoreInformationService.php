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
use Configuration;

/**
 * Store Information Service
 * Manages store information configuration (invoice name, category, integrator ID)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class StoreInformationService
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
        if (Tools::isSubmit('submitStoreInformation')) {
            $invoiceName = Tools::getValue('MERCADOPAGO_INVOICE_NAME', '');
            $storeCategory = Tools::getValue('MERCADOPAGO_STORE_CATEGORY', '');
            $integratorId = Tools::getValue('MERCADOPAGO_INTEGRATOR_ID', '');

            $invoiceNameSet = $this->configDataService->set('MERCADOPAGO_INVOICE_NAME', $invoiceName);
            $storeCategorySet = $this->configDataService->set('MERCADOPAGO_STORE_CATEGORY', $storeCategory);
            $integratorIdSet = $this->configDataService->set('MERCADOPAGO_INTEGRATOR_ID', $integratorId);
            
            if ($invoiceNameSet && $storeCategorySet && $integratorIdSet) {
                $this->module->confirmations[] = $this->module->l('Store information updated successfully!');
                return true;
            } else {
                $this->module->errors[] = $this->module->l('Error updating store information. Please try again.');
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
        $invoiceName = $this->configDataService->get('MERCADOPAGO_INVOICE_NAME', '');
        if (empty($invoiceName)) {
            $invoiceName = Configuration::get('PS_SHOP_NAME', '');
        }

        $storeCategory = $this->configDataService->get('MERCADOPAGO_STORE_CATEGORY', '');
        $integratorId = $this->configDataService->get('MERCADOPAGO_INTEGRATOR_ID', '');

        return [
            'title' => $this->module->l('Store Information'),
            'icon' => 'icon-building',
            'description' => $this->module->l('Configure your store information for Mercado Pago.'),
            'fields' => [
                [
                    'type' => 'text',
                    'label' => $this->module->l('Invoice Name'),
                    'name' => 'MERCADOPAGO_INVOICE_NAME',
                    'required' => false,
                    'desc' => $this->module->l('Company name that appears on invoices. If empty, the shop name will be used.'),
                ],
                [
                    'type' => 'select',
                    'label' => $this->module->l('Store Category'),
                    'name' => 'MERCADOPAGO_STORE_CATEGORY',
                    'required' => false,
                    'options' => [
                        'query' => $this->getStoreCategoryOptions(),
                        'id' => 'id',
                        'name' => 'name',
                    ],
                    'desc' => $this->module->l('Select the category that best describes your store.'),
                ],
                [
                    'type' => 'text',
                    'label' => $this->module->l('Integrator ID'),
                    'name' => 'MERCADOPAGO_INTEGRATOR_ID',
                    'required' => false,
                    'desc' => $this->module->l('Integrator ID (optional). Provided by Mercado Pago for partners.'),
                ],
            ],
            'submit_action' => 'submitStoreInformation',
            'values' => [
                'MERCADOPAGO_INVOICE_NAME' => $invoiceName,
                'MERCADOPAGO_STORE_CATEGORY' => $storeCategory,
                'MERCADOPAGO_INTEGRATOR_ID' => $integratorId,
            ],
        ];
    }

    /**
     * Render store information form
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
     * Get store category options for select
     *
     * @return array
     */
    private function getStoreCategoryOptions(): array
    {
        return [
            ['id' => '', 'name' => $this->module->l('-- Select --')],
            ['id' => 'art', 'name' => $this->module->l('Art & Handcrafts')],
            ['id' => 'art_and_crafts', 'name' => $this->module->l('Art & Handcrafts')],
            ['id' => 'baby', 'name' => $this->module->l('Baby')],
            ['id' => 'coupons', 'name' => $this->module->l('Coupons')],
            ['id' => 'donations', 'name' => $this->module->l('Donations')],
            ['id' => 'computing', 'name' => $this->module->l('Computing')],
            ['id' => 'cameras', 'name' => $this->module->l('Cameras')],
            ['id' => 'video_games', 'name' => $this->module->l('Video Games')],
            ['id' => 'television', 'name' => $this->module->l('Television')],
            ['id' => 'car_electronics', 'name' => $this->module->l('Car Electronics')],
            ['id' => 'electronics', 'name' => $this->module->l('Electronics')],
            ['id' => 'automotive', 'name' => $this->module->l('Automotive')],
            ['id' => 'entertainment', 'name' => $this->module->l('Entertainment')],
            ['id' => 'fashion', 'name' => $this->module->l('Fashion')],
            ['id' => 'games', 'name' => $this->module->l('Games')],
            ['id' => 'home', 'name' => $this->module->l('Home')],
            ['id' => 'musical', 'name' => $this->module->l('Musical')],
            ['id' => 'phones', 'name' => $this->module->l('Phones')],
            ['id' => 'services', 'name' => $this->module->l('Services')],
            ['id' => 'software', 'name' => $this->module->l('Software')],
            ['id' => 'sports', 'name' => $this->module->l('Sports')],
            ['id' => 'stationery', 'name' => $this->module->l('Stationery')],
            ['id' => 'tickets', 'name' => $this->module->l('Tickets')],
            ['id' => 'travels', 'name' => $this->module->l('Travels')],
            ['id' => 'virtual_goods', 'name' => $this->module->l('Virtual Goods')],
            ['id' => 'others', 'name' => $this->module->l('Others')],
        ];
    }
}
