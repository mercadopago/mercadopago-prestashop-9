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
use Tools;
use HelperForm;
use Context;

/**
 * Standard Settings Service
 * Manages CHECKOUT PRO (Standard) payment method configuration
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class PreferenceSettingsService
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
     * Payment types considered "online" (credit/debit/prepaid cards)
     */
    private const ONLINE_TYPES = ['credit_card', 'debit_card', 'prepaid_card'];

    /**
     * Payment method IDs always excluded from the configuration form
     */
    private const ALWAYS_EXCLUDED_IDS = ['account_money', 'meliplace'];


    /**
     * @param \mercadopago $module
     */
    public function __construct(\mercadopago $module)
    {
        $this->module = $module;
        $this->configDataService = new ConfigurationDataService();
        $this->mpApi = new MPApi();
    }

    /**
     * Process form submission
     *
     * @return bool
     */
    public function processForm(): bool
    {
        if (!Tools::isSubmit('submitStandardSettings')) {
            return true;
        }

        $results = [
            $this->configDataService->set('MERCADOPAGO_STANDARD_ENABLED', (bool) Tools::getValue('MERCADOPAGO_STANDARD_ENABLED', false)),
            $this->configDataService->set('MERCADOPAGO_STANDARD_MODAL', (bool) Tools::getValue('MERCADOPAGO_STANDARD_MODAL', false)),
            $this->configDataService->set('MERCADOPAGO_STANDARD_AUTO_RETURN', (bool) Tools::getValue('MERCADOPAGO_STANDARD_AUTO_RETURN', false)),
            $this->configDataService->set('MERCADOPAGO_STANDARD_BINARY_MODE', (bool) Tools::getValue('MERCADOPAGO_STANDARD_BINARY_MODE', false)),
            $this->configDataService->set('MERCADOPAGO_MAX_INSTALLMENTS', (int) Tools::getValue('MERCADOPAGO_MAX_INSTALLMENTS', 12)),
            $this->configDataService->set('MERCADOPAGO_EXPIRATION_DATE_TO', (string) Tools::getValue('MERCADOPAGO_EXPIRATION_DATE_TO', '')),
        ];

        // account_money is always enabled
        $this->configDataService->set('MERCADOPAGO_PAYMENT_account_money', 'on');

        // Save each payment method toggle: 'on' when checked, '' when unchecked
        foreach ($this->fetchPaymentMethodIds() as $id) {
            $postValue = Tools::getValue('MERCADOPAGO_PAYMENT_' . $id, '');
            $value = ($postValue === '1' || $postValue === 'on') ? 'on' : '';
            $results[] = $this->configDataService->set('MERCADOPAGO_PAYMENT_' . $id, $value);
        }

        if (!in_array(false, $results, true)) {
            $this->module->confirmations[] = $this->module->l('Standard Checkout settings updated successfully!');
            return true;
        }

        $this->module->errors[] = $this->module->l('Error updating Standard Checkout settings. Please try again.');
        return false;
    }

    /**
     * Get form data for rendering
     *
     * @return array
     */
    public function getFormData(): array
    {
        $enabledValue    = $this->configDataService->get('MERCADOPAGO_STANDARD_ENABLED');
        $enabled         = !($enabledValue === null || $enabledValue === false || $enabledValue === '0');

        $modalValue      = $this->configDataService->get('MERCADOPAGO_STANDARD_MODAL');
        $modal           = !($modalValue === null || $modalValue === false || $modalValue === '0');

        $autoReturnValue = $this->configDataService->get('MERCADOPAGO_STANDARD_AUTO_RETURN');
        $autoReturn      = !($autoReturnValue === null || $autoReturnValue === false || $autoReturnValue === '0');

        $binaryModeValue = $this->configDataService->get('MERCADOPAGO_STANDARD_BINARY_MODE');
        $binaryMode      = !($binaryModeValue === null || $binaryModeValue === false || $binaryModeValue === '0');

        $maxInstallments  = (int) $this->configDataService->get('MERCADOPAGO_MAX_INSTALLMENTS', 12);
        $expirationDateTo = (string) $this->configDataService->get('MERCADOPAGO_EXPIRATION_DATE_TO', '');

        // Installments options (1–24)
        $installmentsOptions = [];
        for ($i = 1; $i <= 24; $i++) {
            $installmentsOptions[] = [
                'id'   => $i,
                'name' => $i . ' ' . ($i === 1 ? $this->module->l('installment') : $this->module->l('installments')),
            ];
        }

        // Dynamic payment methods from API
        [$onlineQuery, $offlineQuery, $pmValues] = $this->buildPaymentMethodsData();

        $fields = [
            [
                'type'    => 'switch',
                'label'   => $this->module->l('Activate checkout'),
                'name'    => 'MERCADOPAGO_STANDARD_ENABLED',
                'is_bool' => true,
                'values'  => [
                    ['id' => 'active_on',  'value' => 1, 'label' => $this->module->l('Active')],
                    ['id' => 'active_off', 'value' => 0, 'label' => $this->module->l('Inactive')],
                ],
                'desc' => $this->module->l('Activate the Mercado Pago experience at your store checkout.'),
            ],
        ];

        if (!empty($onlineQuery)) {
            $fields[] = [
                'col'    => 4,
                'type'   => 'checkbox',
                'label'  => $this->module->l('Payment methods'),
                'name'   => 'MERCADOPAGO_PAYMENT',
                'class'  => 'payment-online-checkbox',
                'desc'   => ' ',
                'values' => ['query' => $onlineQuery, 'id' => 'id', 'name' => 'name'],
            ];
        }

        if (!empty($offlineQuery)) {
            $fields[] = [
                'col'    => 4,
                'type'   => 'checkbox',
                'label'  => empty($onlineQuery) ? $this->module->l('Payment methods') : '',
                'name'   => 'MERCADOPAGO_PAYMENT',
                'class'  => 'payment-offline-checkbox',
                'desc'   => $this->module->l('Enable your preferred payment methods for your customers.'),
                'values' => ['query' => $offlineQuery, 'id' => 'id', 'name' => 'name'],
            ];
        }

        $fields[] = [
            'col'     => 4,
            'type'    => 'select',
            'label'   => $this->module->l('Maximum installments'),
            'name'    => 'MERCADOPAGO_MAX_INSTALLMENTS',
            'options' => ['query' => $installmentsOptions, 'id' => 'id', 'name' => 'name'],
            'desc'    => $this->module->l('What is the maximum number of installments a customer can pay in?'),
        ];

        $fields[] = [
            'type'    => 'switch',
            'label'   => $this->module->l('Return to store'),
            'name'    => 'MERCADOPAGO_STANDARD_AUTO_RETURN',
            'is_bool' => true,
            'values'  => [
                ['id' => 'active_on',  'value' => 1, 'label' => $this->module->l('Active')],
                ['id' => 'active_off', 'value' => 0, 'label' => $this->module->l('Inactive')],
            ],
            'desc' => $this->module->l('Do you want your customer to return to the store after completing the purchase?'),
        ];

        $fields[] = [
            'type'    => 'switch',
            'label'   => $this->module->l('Checkout modal'),
            'name'    => 'MERCADOPAGO_STANDARD_MODAL',
            'is_bool' => true,
            'values'  => [
                ['id' => 'active_on',  'value' => 1, 'label' => $this->module->l('Active')],
                ['id' => 'active_off', 'value' => 0, 'label' => $this->module->l('Inactive')],
            ],
            'desc' => $this->module->l('Your customers will access the Mercado Pago payment form without leaving your store.'),
        ];

        $fields[] = [
            'type'    => 'switch',
            'label'   => $this->module->l('Binary mode'),
            'name'    => 'MERCADOPAGO_STANDARD_BINARY_MODE',
            'is_bool' => true,
            'values'  => [
                ['id' => 'active_on',  'value' => 1, 'label' => $this->module->l('Active')],
                ['id' => 'active_off', 'value' => 0, 'label' => $this->module->l('Inactive')],
            ],
            'desc' => $this->module->l('Approve or reject payments instantly and automatically, without pending or under-review status.'),
        ];

        $fields[] = [
            'col'    => 3,
            'suffix' => $this->module->l('hours without activity'),
            'type'   => 'text',
            'label'  => $this->module->l('Cancel payment preferences after'),
            'name'   => 'MERCADOPAGO_EXPIRATION_DATE_TO',
            'desc'   => ' ',
            'hint'   => $this->module->l('Leave blank to disable automatic cancellation.'),
        ];

        $baseValues = [
            'MERCADOPAGO_STANDARD_ENABLED'      => $enabled ? '1' : '0',
            'MERCADOPAGO_STANDARD_MODAL'        => $modal ? '1' : '0',
            'MERCADOPAGO_STANDARD_AUTO_RETURN'  => $autoReturn ? '1' : '0',
            'MERCADOPAGO_STANDARD_BINARY_MODE'  => $binaryMode ? '1' : '0',
            'MERCADOPAGO_MAX_INSTALLMENTS'      => (string) $maxInstallments,
            'MERCADOPAGO_EXPIRATION_DATE_TO'    => $expirationDateTo,
        ];

        return [
            'title'         => $this->module->l('CHECKOUT PRO'),
            'icon'          => 'icon-credit-card',
            'description'   => $this->module->l('Offer all payment methods. Payment experience on the Mercado Pago site.'),
            'fields'        => $fields,
            'submit_action' => 'submitStandardSettings',
            'values'        => array_merge($baseValues, $pmValues),
        ];
    }

    /**
     * Render standard settings form
     *
     * @return string
     */
    public function render(): string
    {
        $formData = $this->getFormData();

        $fieldsForm = [
            'form' => [
                'legend'      => ['title' => $formData['title'], 'icon' => $formData['icon']],
                'description' => $formData['description'],
                'input'       => $formData['fields'],
                'submit'      => ['title' => $this->module->l('Save'), 'class' => 'btn btn-default pull-right'],
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
        $helper->tpl_vars = ['fields_value' => $formData['values']];

        return $helper->generateForm([$fieldsForm]);
    }

    /**
     * Fetches all payment method IDs from the API (excluding always-excluded ones).
     * Returns empty array if API is unavailable.
     *
     * @return string[]
     */
    private function fetchPaymentMethodIds(): array
    {
        try {
            $result = $this->mpApi->getPaymentMethods();
            if (!$result || ($result['status'] ?? 0) > 202) {
                return [];
            }
        } catch (\Exception $e) {
            return [];
        }

        $ids = [];
        foreach ($result['response'] as $pm) {
            $id = $pm['id'] ?? '';
            if ($id && !in_array(strtolower($id), self::ALWAYS_EXCLUDED_IDS, true)) {
                $ids[] = $id;
            }
        }
        return array_unique($ids);
    }

    /**
     * Builds the online/offline checkbox query arrays and the fields_value map.
     *
     * @return array [onlineQuery, offlineQuery, pmValues]
     */
    private function buildPaymentMethodsData(): array
    {
        try {
            $result = $this->mpApi->getPaymentMethods();
            if (!$result || ($result['status'] ?? 0) > 202) {
                return [[], [], []];
            }
        } catch (\Exception $e) {
            return [[], [], []];
        }

        $methods = $result['response'];

        $onlineQuery  = [];
        $offlineQuery = [];
        $pmValues     = [];
        $seenIds      = [];

        foreach ($methods as $pm) {
            $id   = $pm['id'] ?? '';
            $type = $pm['payment_type_id'] ?? '';

            if (!$id || in_array(strtolower($id), self::ALWAYS_EXCLUDED_IDS, true) || in_array($id, $seenIds, true)) {
                continue;
            }
            $seenIds[] = $id;

            $name = $pm['name'] ?? $id;
            $key  = 'MERCADOPAGO_PAYMENT_' . $id;

            // Default to enabled ('on') if not yet configured
            $stored = $this->configDataService->get($key);
            $pmValues[$key] = ($stored === null || $stored === false || $stored === 'on') ? 'on' : '';

            $entry = ['id' => $id, 'name' => $name];

            if (in_array($type, self::ONLINE_TYPES, true)) {
                $onlineQuery[] = $entry;
            } else {
                $offlineQuery[] = $entry;
            }
        }

        return [$onlineQuery, $offlineQuery, $pmValues];
    }
}
