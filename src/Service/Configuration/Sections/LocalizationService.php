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
 * Localization Service
 * Manages store location/country configuration
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class LocalizationService
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
     */
    public function __construct(\mercadopago $module)
    {
        $this->module = $module;
        $this->configDataService = new ConfigurationDataService();
        $this->templateRenderer = new TemplateRenderer($module);
    }

    /**
     * Process form submission
     *
     * @return bool True on success, false on error
     */
    public function processForm(): bool
    {
        if (Tools::isSubmit('submitLocalization')) {
            $countryLink = Tools::getValue('MERCADOPAGO_COUNTRY_LINK', 'mlb');
            
            if ($this->isValidCountryCode($countryLink)) {
                $result = $this->configDataService->set('MERCADOPAGO_COUNTRY_LINK', $countryLink);
                if ($result) {
                    $this->module->confirmations[] = $this->module->l('Country updated successfully!');
                    return true;
                } else {
                    $this->module->errors[] = $this->module->l('Error updating country. Please try again.');
                    return false;
                }
            } else {
                $this->module->errors[] = $this->module->l('Invalid country code.');
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
        $currentCountry = $this->configDataService->get('MERCADOPAGO_COUNTRY_LINK', 'mlb');

        $banner = $this->templateRenderer->render('admin/templates/localization-form.twig', [
            'countries'      => $this->getCountryOptions(),
            'currentCountry' => $currentCountry,
            'imgAlt'         => $this->module->l('Select your store location'),
            'title'          => $this->module->l('Before linking, select the location of your store'),
            'description'    => $this->module->l('In which country is your Mercado Pago account registered?'),
        ]);

        return [
            'title'         => $this->module->l('Localization'),
            'icon'          => 'icon-globe',
            'fields'        => [
                [
                    'type'             => 'html',
                    'name'             => 'mp_localization_banner',
                    'form_group_class' => 'mp-localization-form-group',
                    'html_content'     => $banner,
                ],
            ],
            'submit_action' => 'submitLocalization',
            'values'        => [],
        ];
    }

    /**
     * Render localization form
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
                'description' => $formData['description'] ?? '',
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

    /**
     * Get country options for select
     *
     * @return array
     */
    private function getCountryOptions(): array
    {
        return [
            ['id' => 'mla', 'name' => $this->module->l('Argentina')],
            ['id' => 'mlb', 'name' => $this->module->l('Brasil')],
            ['id' => 'mlc', 'name' => $this->module->l('Chile')],
            ['id' => 'mco', 'name' => $this->module->l('Colômbia')],
            ['id' => 'mlm', 'name' => $this->module->l('México')],
            ['id' => 'mpe', 'name' => $this->module->l('Peru')],
            ['id' => 'mlu', 'name' => $this->module->l('Uruguai')],
        ];
    }

    /**
     * Validate country code
     *
     * @param string $countryCode
     * @return bool
     */
    private function isValidCountryCode(string $countryCode): bool
    {
        $validCodes = ['mla', 'mlb', 'mlc', 'mco', 'mlm', 'mpe', 'mlu'];
        return in_array($countryCode, $validCodes, true);
    }
}
