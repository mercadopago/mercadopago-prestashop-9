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

namespace MercadoPago\Service\Configuration;

use MercadoPago\Service\Configuration\Sections\LocalizationService;
use MercadoPago\Service\Configuration\Sections\CredentialsService;
use MercadoPago\Service\Configuration\Sections\PaymentModeService;
use MercadoPago\Service\Configuration\Sections\StoreInformationService;
use MercadoPago\Service\Configuration\Sections\PreferenceSettingsService;
use MercadoPago\Service\Configuration\Sections\CardSettingsService;
use MercadoPago\Service\Configuration\Sections\PixSettingsService;
use MercadoPago\Service\Configuration\Sections\PseSettingsService;
use MercadoPago\Service\Configuration\Sections\TicketSettingsService;
use MercadoPago\Service\Configuration\Sections\YapeSettingsService;
use MercadoPago\Service\Configuration\ConfigurationDataService;
use MercadoPago\Service\Configuration\TemplateRenderer;

if (!defined('_PS_VERSION_')) {
    exit;
}

class ConfigurationService
{
    private \mercadopago $module;

    private TemplateRenderer $templateRenderer;

    private CredentialsService $credentialsService;

    private ConfigurationDataService $configDataService;

    private LocalizationService $localizationService;

    private PaymentModeService $paymentModeService;

    private StoreInformationService $storeInformationService;

    private PreferenceSettingsService $preferenceSettingsService;

    private CardSettingsService $cardSettingsService;

    private PixSettingsService $pixSettingsService;

    private PseSettingsService $pseSettingsService;

    private YapeSettingsService $yapeSettingsService;

    private TicketSettingsService $ticketSettingsService;

    public function __construct(\mercadopago $module)
    {
        $this->module = $module;
        $this->templateRenderer = new TemplateRenderer($module);
        $this->credentialsService = new CredentialsService($module);
        $this->configDataService = new ConfigurationDataService();
        $this->localizationService = new LocalizationService($module);
        $this->paymentModeService = new PaymentModeService($module);
        $this->storeInformationService = new StoreInformationService($module);
        $this->preferenceSettingsService = new PreferenceSettingsService($module);
        $this->cardSettingsService = new CardSettingsService($module);
        $this->pixSettingsService = new PixSettingsService($module);
        $this->pseSettingsService = new PseSettingsService($module);
        $this->yapeSettingsService = new YapeSettingsService($module);
        $this->ticketSettingsService = new TicketSettingsService($module);
    }

    public function render(): string
    {
        $this->module->confirmations = [];
        $this->module->errors = [];

        $this->processAllForms();

        $output = $this->renderMessages();
        $output .= $this->renderNavigationTabs();
        $output .= $this->renderHeader();
        $output .= $this->renderInfoMessage();
        $output .= $this->renderConfigurationPage();
        $output .= $this->closeConfigTab();
        $output .= $this->renderAboutTab();
        $output .= $this->closeTabContent();
        $output .= $this->renderDeviceFingerprint();
        $output .= $this->renderBindingDataModal();

        return $output;
    }

    private function processAllForms(): void
    {
        $this->processDeviceFingerprintForm();

        $this->localizationService->processForm();
        $this->credentialsService->processForm();
        $this->paymentModeService->processForm();
        $this->storeInformationService->processForm();

        $countryCode = $this->configDataService->get('MERCADOPAGO_COUNTRY_LINK');

        if (empty($countryCode)) {
            $this->module->errors[] = $this->module->l('Please select a country in the Localization section before configuring payment methods.');
            $countryCode = 'mlb';
        }

        $this->preferenceSettingsService->processForm();
        $this->cardSettingsService->processForm();

        if ($countryCode === 'mlb') {
            $this->pixSettingsService->processForm();
        }

        if ($countryCode === 'mco') {
            $this->pseSettingsService->processForm();
        }

        if ($countryCode === 'mpe') {
            $this->yapeSettingsService->processForm();
        }

        $this->ticketSettingsService->processForm();
    }

    private function renderMessages(): string
    {
        $submitActions = [
            'submitLocalization',
            'submitCredentials',
            'submitPaymentMode',
            'submitStoreInformation',
            'submitStandardSettings',
            'submitCustomSettings',
            'submitPixSettings',
            'submitPseSettings',
            'submitYapeSettings',
            'submitTicketSettings',
        ];
        
        $formSubmitted = false;
        foreach ($submitActions as $action) {
            if (\Tools::isSubmit($action)) {
                $formSubmitted = true;
                break;
            }
        }

        if (!$formSubmitted) {
            $this->module->confirmations = [];
            $this->module->errors = [];
            return '';
        }
        
        $confirmations = [];
        $errors = [];
        
        if (isset($this->module->confirmations) && is_array($this->module->confirmations) && !empty($this->module->confirmations)) {
            $confirmations = $this->module->confirmations;
        }
        
        if (isset($this->module->errors) && is_array($this->module->errors) && !empty($this->module->errors)) {
            $errors = $this->module->errors;
        }
        
        $this->module->confirmations = [];
        $this->module->errors = [];
        
        if (empty($confirmations) && empty($errors)) {
            return '';
        }
        
        return $this->templateRenderer->render('admin/templates/messages.twig', [
            'confirmations' => $confirmations,
            'errors' => $errors,
        ]);
    }

    private function renderConfigurationPage(): string
    {
        $output = $this->localizationService->render();
        $output .= $this->credentialsService->render();
        $output .= $this->paymentModeService->render();
        $output .= $this->storeInformationService->render();

        $countryCode = $this->configDataService->get('MERCADOPAGO_COUNTRY_LINK');

        if (empty($countryCode)) {
            $this->module->errors[] = $this->module->l('Please select a country in the Localization section before configuring payment methods.');
            $countryCode = 'mlb';
        }

        $output .= $this->renderPaymentMethodsTabs();

        $output .= $this->openPaymentMethodTab('tab-checkout-pro', true);
        $output .= $this->preferenceSettingsService->render();
        $output .= $this->closePaymentMethodTab();

        $output .= $this->openPaymentMethodTab('tab-custom-checkout');
        $output .= $this->cardSettingsService->render();
        $output .= $this->closePaymentMethodTab();

        if ($countryCode === 'mlb') {
            $output .= $this->openPaymentMethodTab('tab-pix-checkout');
            $output .= $this->pixSettingsService->render();
            $output .= $this->closePaymentMethodTab();
        }

        if ($countryCode === 'mco') {
            $output .= $this->openPaymentMethodTab('tab-pse-checkout');
            $output .= $this->pseSettingsService->render();
            $output .= $this->closePaymentMethodTab();
        }

        if ($countryCode === 'mpe') {
            $output .= $this->openPaymentMethodTab('tab-yape-checkout');
            $output .= $this->yapeSettingsService->render();
            $output .= $this->closePaymentMethodTab();
        }

        $output .= $this->openPaymentMethodTab('tab-ticket-boleto');
        $output .= $this->ticketSettingsService->render();
        $output .= $this->closePaymentMethodTab();

        $output .= $this->closePaymentMethodsTabs();

        $output .= $this->renderStartSellingSection();

        $output .= $this->renderOnboardingModal();

        return $output;
    }

    public function renderNavigationTabs(): string
    {
        return $this->templateRenderer->render('admin/templates/navigation-tabs.twig', [
            'version' => $this->module->version,
        ]);
    }

    public function renderHeader(): string
    {
        return $this->templateRenderer->render('admin/templates/header.twig', [
            'logoUrl' => $this->module->getPathUri() . 'views/admin/img/mpinfo_logo.png',
            'version' => $this->module->version,
        ]);
    }

    public function renderInfoMessage(): string
    {
        return $this->templateRenderer->render('admin/templates/info-message.twig');
    }

    public function renderAboutTab(): string
    {
        return $this->templateRenderer->render('admin/templates/about-tab.twig');
    }

    public function closeConfigTab(): string
    {
        return '</div>';
    }

    public function closeTabContent(): string
    {
        return '</div>';
    }

    public function renderPaymentMethodsTabs(): string
    {
        $countryCode = $this->configDataService->get('MERCADOPAGO_COUNTRY_LINK');

        if (empty($countryCode)) {
            $this->module->errors[] = $this->module->l('Please select a country in the Localization section before configuring payment methods.');
            $countryCode = 'mlb';
        }

        return $this->templateRenderer->render('admin/templates/payment-methods-tabs.twig', [
            'countryCode' => $countryCode,
        ]);
    }

    public function openPaymentMethodTab(string $tabId, bool $active = false): string
    {
        return $this->templateRenderer->render('admin/templates/payment-method-tab-open.twig', [
            'tabId' => $tabId,
            'active' => $active,
        ]);
    }

    public function closePaymentMethodTab(): string
    {
        return '</div>';
    }

    public function closePaymentMethodsTabs(): string
    {
        return '</div></div>';
    }

    public function renderStartSellingSection(): string
    {
        $context = \Context::getContext();
        $storeUrl = $context->link->getBaseLink();
        
        return $this->templateRenderer->render('admin/templates/start-selling.twig', [
            'storeUrl' => $storeUrl,
        ]);
    }

    private function processDeviceFingerprintForm(): void
    {
        if (\Tools::isSubmit('submitMercadopagoDevice')) {
            $deviceFingerprint = \Tools::getValue('device_fingerprint', '');
            if (!empty($deviceFingerprint)) {
                $this->configDataService->set('MERCADOPAGO_DEVICE_ID', $deviceFingerprint);
            }
        }
    }

    public function renderOnboardingModal(): string
    {
        $credentialsService = $this->credentialsService;
        $iframeUrl = $credentialsService->getOnboardingURL();

        return $this->templateRenderer->render('admin/templates/onboarding_modal.twig', [
            'iframe_url' => $iframeUrl ?: '',
        ]);
    }

    private function renderDeviceFingerprint(): string
    {
        if ($this->configDataService->get('MERCADOPAGO_DEVICE_ID')) {
            return '';
        }
        return $this->templateRenderer->render('admin/templates/device_fingerprint.twig', []);
    }

    private function renderBindingDataModal(): string
    {
        $credentialsService = $this->credentialsService;
        return $credentialsService->renderBindingDataModal();
    }
}
