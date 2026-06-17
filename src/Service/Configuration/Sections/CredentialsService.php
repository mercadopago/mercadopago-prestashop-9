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

use MercadoPago\Client\MPRestCli;
use MercadoPago\Client\MPSdk;
use MercadoPago\Service\Configuration\ConfigurationDataService;
use MercadoPago\Service\Configuration\TemplateRenderer;
use Configuration;
use Tools;
use Context;
use Exception;
use Module;

if (!defined('_PS_VERSION_')) {
    exit;
}

class CredentialsService
{
    const INVALID_CREDENTIAL_ERROR = 'invalid_credential_error';
    const INVALID_CREDENTIALS_ONBOARDING_STATUS = self::INVALID_CREDENTIAL_ERROR;
    const GET_INTEGRATION_ERROR_ONBOARDING_STATUS = 'get_integration_error';
    const PENDING_ONBOARDING_STATUS = 'pending';
    const SUCCESS_ONBOARDING_STATUS = 'success';
    const DATE_FORMAT = 'Y-m-d';
    const TEMPORARY_REQUEST_ERROR = 'temporary_request_error';

    private array $credentialsWrapperCache = [];

    private $module;

    private $configDataService;

    private $templateRenderer;

    public function __construct(\mercadopago $module)
    {
        $this->module = $module;
        $this->configDataService = new ConfigurationDataService();
        $this->templateRenderer = new TemplateRenderer($module);
    }

    public function processForm(): bool
    {
        if (Tools::isSubmit('submitCredentials')) {
            $publicKey = Tools::getValue('MERCADOPAGO_PUBLIC_KEY', '');
            $accessToken = Tools::getValue('MERCADOPAGO_ACCESS_TOKEN', '');
            $sandboxPublicKey = Tools::getValue('MERCADOPAGO_SANDBOX_PUBLIC_KEY', '');
            $sandboxAccessToken = Tools::getValue('MERCADOPAGO_SANDBOX_ACCESS_TOKEN', '');

            $publicKeySet = $this->configDataService->set('MERCADOPAGO_PUBLIC_KEY', $publicKey);
            $accessTokenSet = $this->configDataService->set('MERCADOPAGO_ACCESS_TOKEN', $accessToken);
            $sandboxPublicKeySet = $this->configDataService->set('MERCADOPAGO_SANDBOX_PUBLIC_KEY', $sandboxPublicKey);
            $sandboxAccessTokenSet = $this->configDataService->set('MERCADOPAGO_SANDBOX_ACCESS_TOKEN', $sandboxAccessToken);
            
            if ($publicKeySet && $accessTokenSet && $sandboxPublicKeySet && $sandboxAccessTokenSet) {
                $this->module->confirmations[] = $this->module->l('Credentials updated successfully!');
                return true;
            } else {
                $this->module->errors[] = $this->module->l('Error updating credentials. Please try again.');
                return false;
            }
        }

        $this->processOnboarding();

        return true;
    }

    private function processOnboarding(): void
    {
        $countryLink = $this->configDataService->get('MERCADOPAGO_COUNTRY_LINK', '');
        $oldCountryLink = $this->configDataService->get('MERCADOPAGO_OLD_COUNTRY_LINK', '');
        
        if ($this->shouldStartOnboarding($oldCountryLink !== $countryLink)) {
            $storeAdminUrl = $this->getStoreAdminURL();
            $this->startPluginOnboarding($storeAdminUrl);
        }

        $integrationId = $this->getIntegrationId();
        
        if (!empty($integrationId)) {
            if ($this->shouldCompleteOnboarding()) {
                $storeAdminUrl = $this->getStoreAdminURL();
                $storeCredentialsError = $this->getToStoreCredentials($storeAdminUrl);
                if ($storeCredentialsError) {
                    $this->setOnboardingStatus(self::GET_INTEGRATION_ERROR_ONBOARDING_STATUS);
                }
            }
        }

        $publicKey = $this->getPublicKey();
        $accessToken = $this->getAccessToken();

        if ($publicKey) {
            $credentialsResponse = $this->getCredentialsWrapper($publicKey, null);
            $credentialError = $credentialsResponse['error'] ?? null;
            $isValidCredential = $credentialError !== self::TEMPORARY_REQUEST_ERROR
                && $credentialError !== self::INVALID_CREDENTIAL_ERROR;

            if (!$isValidCredential && !$this->alreadyStartedOnboarding()) {
                $this->setOnboardingStatus(self::INVALID_CREDENTIALS_ONBOARDING_STATUS);
            }
        }

        if ($accessToken) {
            $credentialsResponse = $this->getCredentialsWrapper(null, $accessToken);
            $credentialError = $credentialsResponse['error'] ?? null;
            $isValidCredential = $credentialError !== self::TEMPORARY_REQUEST_ERROR
                && $credentialError !== self::INVALID_CREDENTIAL_ERROR;

            if (!$isValidCredential && !$this->alreadyStartedOnboarding()) {
                $this->setOnboardingStatus(self::INVALID_CREDENTIALS_ONBOARDING_STATUS);
            }
        }

        if ($oldCountryLink !== $countryLink && $countryLink) {
            $this->configDataService->set('MERCADOPAGO_OLD_COUNTRY_LINK', $countryLink);
        }
    }

    public function startPluginOnboarding(string $storeAdminUrl, string $onboardingStatus = self::PENDING_ONBOARDING_STATUS): ?string
    {
        $siteId = $this->configDataService->get('MERCADOPAGO_COUNTRY_LINK', '');

        if (!$siteId) {
            return null;
        }

        $onboardingData = $this->startOnboarding($storeAdminUrl, $siteId);

        if (isset($onboardingData['error'])) {
            return $onboardingData['error'];
        }

        $onboardingUrl = $onboardingData['onboarding_url'] ?? '';
        $codeVerifier = $onboardingData['code_verifier'] ?? '';
        $integrationId = $onboardingData['integration_id'] ?? '';

        $this->configDataService->set('MERCADOPAGO_ONBOARDING_URL', $onboardingUrl);
        $this->configDataService->set('MERCADOPAGO_ONBOARDING_ID', $integrationId);
        $this->configDataService->set('MERCADOPAGO_ONBOARDING_CODE_VERIFIER', $codeVerifier);
        $this->configDataService->set('MERCADOPAGO_ONBOARDING_STATUS', $onboardingStatus);
        $this->configDataService->set('MERCADOPAGO_ONBOARDING_START_DATE', date(self::DATE_FORMAT, time()));

        return null;
    }

    public function getIntegrationId(): string
    {
        return (string) Tools::getValue('integration_id', '');
    }

    public function getCodeVerifier(): string
    {
        return (string) $this->configDataService->get('MERCADOPAGO_ONBOARDING_CODE_VERIFIER', '');
    }

    public function getOnboardingURL(): string
    {
        return (string) $this->configDataService->get('MERCADOPAGO_ONBOARDING_URL', '');
    }

    public function getOnboardingId(): string
    {
        return (string) $this->configDataService->get('MERCADOPAGO_ONBOARDING_ID', '');
    }

    public function getOnboardingStatus(): string
    {
        return (string) $this->configDataService->get('MERCADOPAGO_ONBOARDING_STATUS', '');
    }

    public function getSellerExperienceStatus(): string
    {
        $status = $this->configDataService->get('MERCADOPAGO_SELLER_EXPERIENCE_STATUS', '');
        return $status ?: 'old';
    }

    public function setSellerExperienceStatus(): void
    {
        $hasSuccessIntegrationId = (bool) $this->configDataService->get('MERCADOPAGO_SUCCESS_INTEGRATION_ID', '');
        $accessToken = $this->getAccessToken();
        $sellerExperienceStatus = '';

        if (!$accessToken) {
            $sellerExperienceStatus = 'new';
        } elseif ($accessToken && !$hasSuccessIntegrationId) {
            $sellerExperienceStatus = 'old';
        } elseif ($accessToken && $hasSuccessIntegrationId) {
            $sellerExperienceStatus = 'updated';
        }

        $this->configDataService->set('MERCADOPAGO_SELLER_EXPERIENCE_STATUS', $sellerExperienceStatus);
    }

    public function getOnboardingStartDate(): string
    {
        return (string) $this->configDataService->get('MERCADOPAGO_ONBOARDING_START_DATE', '');
    }

    public function getAccessToken(): string
    {
        if ($this->configDataService->isProductionMode()) {
            return (string) $this->configDataService->get('MERCADOPAGO_ACCESS_TOKEN', '');
        }

        return (string) $this->configDataService->get('MERCADOPAGO_SANDBOX_ACCESS_TOKEN', '');
    }

    public function getPublicKey(): string
    {
        if ($this->configDataService->isProductionMode()) {
            return (string) $this->configDataService->get('MERCADOPAGO_PUBLIC_KEY', '');
        }

        return (string) $this->configDataService->get('MERCADOPAGO_SANDBOX_PUBLIC_KEY', '');
    }
    
    public function getSandboxPublicKey(): string
    {
        return (string) $this->configDataService->get('MERCADOPAGO_SANDBOX_PUBLIC_KEY', '');
    }
    
    public function getSandboxAccessToken(): string
    {
        return (string) $this->configDataService->get('MERCADOPAGO_SANDBOX_ACCESS_TOKEN', '');
    }

    public function getCredentialsWrapper(?string $publicKey, ?string $accessToken): array
    {
        $cacheKey = md5(($publicKey ?? '') . ($accessToken ?? ''));
        if (isset($this->credentialsWrapperCache[$cacheKey])) {
            return $this->credentialsWrapperCache[$cacheKey];
        }

        $uri = '/plugins-credentials-wrapper/credentials';
        $headers = [];

        if ($publicKey) {
            $uri = $uri . '?public_key=' . urlencode($publicKey);
        }

        if ($accessToken) {
            $headers = ["Authorization: Bearer " . $accessToken];
        }

        try {
            $response = self::onboardingHttpRequest('GET', $uri, null, $headers);

            if ($response['status'] === 401 || $response['status'] === 403) {
                $result = ["error" => self::INVALID_CREDENTIAL_ERROR];
                $this->credentialsWrapperCache[$cacheKey] = $result;
                return $result;
            }

            if ($response['status'] >= 500) {
                $result = ["error" => self::TEMPORARY_REQUEST_ERROR];
                $this->credentialsWrapperCache[$cacheKey] = $result;
                return $result;
            }

            $result = $response['response'] ?? [];
            $this->credentialsWrapperCache[$cacheKey] = $result;
            return $result;
        } catch (Exception $e) {
            $result = ["error" => self::TEMPORARY_REQUEST_ERROR];
            $this->credentialsWrapperCache[$cacheKey] = $result;
            return $result;
        }
    }

    public function startOnboarding(string $storeAdminUrl, string $siteId): array
    {
        try {
            $codeVerifier = rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
            $codeChallenge = base64_encode(hash('sha256', $codeVerifier));

            $data = random_bytes(16);
            $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
            $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10
            $identifier = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));

            $response = self::onboardingHttpRequest(
                'POST',
                '/ppcore/prod/configurations-api/onboarding/v1/integration',
                [
                    'external_reference' => $identifier,
                    'code_challenge' => $codeChallenge,
                    'callback_url' => $storeAdminUrl,
                    'store_url' => Tools::getShopDomainSsl(true, true),
                    'site_id' => strtoupper($siteId),
                ],
                ["X-Platform-Id: " . MPRestCli::PLATFORM_ID]
            );

            if ($response['status'] >= 400 && $response['status'] < 500) {
                return ["error" => "bad_request_error"];
            }

            if ($response['status'] >= 500) {
                return ["error" => self::TEMPORARY_REQUEST_ERROR];
            }

            return [
                'integration_id' => $response['response']['integration_id'] ?? '',
                'onboarding_url' => $response['response']['onboarding_url'] ?? '',
                'code_verifier' => $codeVerifier
            ];
        } catch (Exception $e) {
            return ["error" => "bad_request_error"];
        }
    }

    public function getIntegrationData(string $integrationId, string $codeVerifier): array
    {
        try {
            $deviceFingerprint = $this->configDataService->get('MERCADOPAGO_DEVICE_ID', '');

            if (empty($deviceFingerprint)) {
                $deviceFingerprint = Tools::getValue('device_fingerprint', '');

                if (!empty($deviceFingerprint)) {
                    $this->configDataService->set('MERCADOPAGO_DEVICE_ID', $deviceFingerprint);
                }
            }

            if (empty($deviceFingerprint)) {
                return ["error" => "bad_request_error"];
            }
            
            $headers = [
                "X-Platform-Id: " . MPRestCli::PLATFORM_ID,
                "X-Device-Fingerprint: " . $deviceFingerprint
            ];
            
            $url = '/ppcore/prod/configurations-api/onboarding/v1/integration/' . $integrationId . '?code_verifier=' . urlencode($codeVerifier);
            
            $response = self::onboardingHttpRequest('GET', $url, null, $headers);

            if ($response['status'] > 399 && $response['status'] < 500) {
                return ["error" => "bad_request_error"];
            }

            if ($response['status'] >= 500) {
                return ["error" => self::TEMPORARY_REQUEST_ERROR];
            }

            return $response['response']['credentials'] ?? [];
        } catch (Exception $e) {
            return ["error" => "bad_request_error"];
        }
    }

    public function getOnboardingData(string $applicationId): array
    {
        try {
            $sdk = MPSdk::getInstance();
            $onboardingInstance = $sdk->getOnboardingInstance();
            
            $result = $onboardingInstance->getOnboardingData($applicationId);
            
            return $result;
        } catch (\Throwable $th) {
            return ["error" => "bad_request_error"];
        }
    }

    public function setOnboardingStatus(string $status): void
    {
        $this->configDataService->set('MERCADOPAGO_ONBOARDING_STATUS', $status);
    }

    public function shouldStartOnboarding(bool $forceUpdate = false): bool
    {
        $status = $this->getOnboardingStatus();
        $startDate = $this->getOnboardingStartDate();
        
        return !$status
            || $status == self::GET_INTEGRATION_ERROR_ONBOARDING_STATUS
            || $status == self::INVALID_CREDENTIALS_ONBOARDING_STATUS
            || $forceUpdate
            || ($status !== self::SUCCESS_ONBOARDING_STATUS
                && strtotime($startDate) < strtotime('-1 days'));
    }

    public function shouldCompleteOnboarding(): bool
    {
        if ($this->getOnboardingStatus() === self::SUCCESS_ONBOARDING_STATUS) {
            return false;
        }

        $integrationId = $this->getIntegrationId();
        $onboardingId = $this->getOnboardingId();

        return !empty($integrationId) && $onboardingId === $integrationId;
    }

    public function alreadyStartedOnboarding(): bool
    {
        return $this->getOnboardingStatus() === self::PENDING_ONBOARDING_STATUS;
    }

    public function isNewIntegration(string $accessToken): bool
    {
        return empty($accessToken) && $this->alreadyStartedOnboarding();
    }

    public function getAccountData(string $applicationId): array
    {
        $onboardingData = $this->getOnboardingData($applicationId);

        if (isset($onboardingData['error'])) {
            return $onboardingData;
        }

        if (isset($onboardingData['user']) && isset($onboardingData['application'])) {
            $user = $onboardingData['user'];
            $application = $onboardingData['application'];

            $firstName = is_object($user) ? ($user->first_name ?? '') : ($user['first_name'] ?? '');
            $lastName = is_object($user) ? ($user->last_name ?? '') : ($user['last_name'] ?? '');

            $result = [
                "application_name" => is_object($application) ? ($application->name ?? '') : ($application['name'] ?? ''),
                "user_email" => is_object($user) ? ($user->email ?? '') : ($user['email'] ?? ''),
                "user_id" => is_object($user) ? ($user->id ?? '') : ($user['id'] ?? ''),
                "site_id" => is_object($application) ? ($application->site_id ?? '') : ($application['site_id'] ?? ''),
                "name" => trim("$firstName $lastName"),
            ];
            
            return $result;
        }

        return ["error" => "bad_request_error"];
    }

    public function getToStoreCredentials(string $storeAdminUrl): ?string
    {
        $integrationId = $this->getIntegrationId();
        $successIntegrationId = $this->configDataService->get('MERCADOPAGO_SUCCESS_INTEGRATION_ID', '');

        if ($successIntegrationId == $integrationId) {
            return null;
        }

        $codeVerifier = $this->getCodeVerifier();
        $onboardingId = $this->getOnboardingId();
        
        if ($integrationId !== $onboardingId) {
            return "integration_id_mismatch";
        }

        $integrationData = $this->getIntegrationData($integrationId, $codeVerifier);

        if (isset($integrationData['error'])) {
            return $integrationData['error'];
        }

        $publicKey = $integrationData['production']['public_key'] ?? '';
        $accessToken = $integrationData['production']['access_token'] ?? '';
        $sandboxPublicKey = $integrationData['test']['public_key'] ?? '';
        $sandboxAccessToken = $integrationData['test']['access_token'] ?? '';

        $this->setSellerExperienceStatus();

        $this->configDataService->set('MERCADOPAGO_PUBLIC_KEY', $publicKey);
        $this->configDataService->set('MERCADOPAGO_ACCESS_TOKEN', $accessToken);
        $this->configDataService->set('MERCADOPAGO_SANDBOX_PUBLIC_KEY', $sandboxPublicKey);
        $this->configDataService->set('MERCADOPAGO_SANDBOX_ACCESS_TOKEN', $sandboxAccessToken);
        $this->configDataService->set('MERCADOPAGO_SUCCESS_INTEGRATION_ID', $integrationId);
        
        $clientId = '';
        if ($accessToken) {
            $credentialsWrapperResponse = $this->getCredentialsWrapper(null, $accessToken);
            
            if (!isset($credentialsWrapperResponse['error']) && isset($credentialsWrapperResponse['client_id'])) {
                $clientId = $credentialsWrapperResponse['client_id'];
                $this->configDataService->set('MERCADOPAGO_APPLICATION_ID', $clientId);
                $this->configDataService->set('MERCADOPAGO_SELLER_ID', $credentialsWrapperResponse['user_id'] ?? '');
                $this->configDataService->set('MERCADOPAGO_SITE_ID', $credentialsWrapperResponse['site_id'] ?? '');
            }
        }
        
        $this->configDataService->delete('MERCADOPAGO_ONBOARDING_URL');
        $this->configDataService->delete('MERCADOPAGO_ONBOARDING_CODE_VERIFIER');
        $this->configDataService->delete('MERCADOPAGO_DEVICE_ID');

        $this->setOnboardingStatus(self::SUCCESS_ONBOARDING_STATUS);

        \MercadoPago\Client\MPSdk::reset();

        $this->startPluginOnboarding($storeAdminUrl, self::SUCCESS_ONBOARDING_STATUS);

        return null;
    }

    private function getStoreAdminURL(): string
    {
        $context = Context::getContext();
        return $context->link->getAdminLink('AdminModules', false)
            . '&configure=' . $this->module->name
            . '&tab_module=' . $this->module->tab
            . '&module_name=' . $this->module->name
            . '&token=' . Tools::getAdminTokenLite('AdminModules');
    }

    public function render(): string
    {
        $formData = $this->getFormData();
        $onboardingStatus = $this->getOnboardingStatus();
        $iframeUrl = $this->getOnboardingURL();
        $accessToken = $this->getAccessToken();
        $publicKey = $this->getPublicKey();

        $hasOnboardingError = $onboardingStatus === self::INVALID_CREDENTIALS_ONBOARDING_STATUS
            || $onboardingStatus === self::GET_INTEGRATION_ERROR_ONBOARDING_STATUS;

        $hasCredentialsWrapperError = false;
        $isValidCredential = false;
        $credentialsPublicKeyResponse = [];
        $credentialsWrapperResponse = [];

        if ($publicKey) {
            $credentialsPublicKeyResponse = $this->getCredentialsWrapper($publicKey, null);
            $credentialPublicError = $credentialsPublicKeyResponse['error'] ?? null;
            $hasCredentialsWrapperError = $credentialPublicError === self::TEMPORARY_REQUEST_ERROR;
            $isValidCredential = !$hasCredentialsWrapperError && $credentialPublicError !== self::INVALID_CREDENTIAL_ERROR;
        }
        
        if ($accessToken && isset($credentialsPublicKeyResponse['client_id'])) {
            $credentialsWrapperResponse = $this->getCredentialsWrapper(null, $accessToken);
            $credentialError = $credentialsWrapperResponse['error'] ?? null;
            $hasCredentialsWrapperError = $credentialError === self::TEMPORARY_REQUEST_ERROR;
            $isValidCredential = !$hasCredentialsWrapperError && $credentialError !== self::INVALID_CREDENTIAL_ERROR;
        }
        
        $isNewIntegration = $this->isNewIntegration($accessToken);
        
        $hasGetOnboardingDataError = false;
        $accountData = [];
        $clientId = '';

        if ($isValidCredential && !$hasCredentialsWrapperError && isset($credentialsWrapperResponse['homologated'])) {
            $clientId = $credentialsWrapperResponse['client_id'] ?? '';
            
            if ($clientId) {
                $accountData = $this->getAccountData((string) $clientId);
                $hasGetOnboardingDataError = isset($accountData['error']);
            }
        }
        
        if (empty($clientId)) {
            $clientId = $this->configDataService->get('MERCADOPAGO_APPLICATION_ID', '');
            if ($clientId && $accessToken && empty($accountData) && $isValidCredential && !$hasCredentialsWrapperError) {
                $accountData = $this->getAccountData((string) $clientId);
                $hasGetOnboardingDataError = isset($accountData['error']);
            }
        }

        if ($hasOnboardingError) {
            if ($onboardingStatus === self::GET_INTEGRATION_ERROR_ONBOARDING_STATUS) {
                return $this->renderGetIntegrationError($formData, $iframeUrl);
            }
            return $this->renderIntegrationError($formData, $iframeUrl);
        }

        if ($hasCredentialsWrapperError) {
            return $this->renderIntegrationWrapperError($formData, $iframeUrl);
        }

        if ($hasGetOnboardingDataError) {
            return $this->renderGetIntegrationError($formData, $iframeUrl);
        }

        if ($isNewIntegration && !$hasOnboardingError) {
            return $this->renderStartOnboarding($formData, $iframeUrl);
        }

        if (!$isValidCredential && !empty($accessToken) && !$hasCredentialsWrapperError) {
            return $this->renderIntegrationExpired($formData, $iframeUrl);
        }

        if ($isValidCredential && !$hasOnboardingError && !$hasCredentialsWrapperError) {
            return $this->renderSuccessfulIntegration($formData, $iframeUrl, $accountData);
        }

        return $this->renderStartOnboarding($formData, $iframeUrl);
    }

    private function renderStartOnboarding(array $formData, string $iframeUrl): string
    {
        $needsFingerprint = empty($this->configDataService->get('MERCADOPAGO_DEVICE_ID'));

        return $this->templateRenderer->render('admin/templates/start_onboarding.twig', [
            'title' => $formData['title'],
            'icon' => $formData['icon'],
            'description' => $formData['description'],
            'iframe_url' => $iframeUrl,
            'needs_fingerprint' => $needsFingerprint,
        ]);
    }

    private function renderSuccessfulIntegration(array $formData, string $iframeUrl, array $accountData = []): string
    {
        $sellerExperienceStatus = $this->getSellerExperienceStatus();

        $mpIntegrationUserName = $accountData['name'] ?? '';
        $mpAccountEmail = $accountData['user_email'] ?? '';
        $mpIntegrationName = $accountData['application_name'] ?? '';

        return $this->templateRenderer->render('admin/templates/successful_integration.twig', [
            'title' => $formData['title'],
            'icon' => $formData['icon'],
            'description' => $formData['description'],
            'iframe_url' => $iframeUrl,
            'seller_experience_status' => $sellerExperienceStatus,
            'mp_integration_user_name' => $mpIntegrationUserName,
            'mp_account_email' => $mpAccountEmail,
        ]);
    }
    
    public function renderBindingDataModal(): string
    {
        $publicKey = (string) $this->configDataService->get('MERCADOPAGO_PUBLIC_KEY', '');
        $accessToken = (string) $this->configDataService->get('MERCADOPAGO_ACCESS_TOKEN', '');
        $sandboxPublicKey = $this->getSandboxPublicKey();
        $sandboxAccessToken = $this->getSandboxAccessToken();
        $clientId = $this->configDataService->get('MERCADOPAGO_APPLICATION_ID', '');
        $countryLink = (string) $this->configDataService->get('MERCADOPAGO_COUNTRY_LINK', '');
        $countryDomain = $this->siteIdToCountryDomain($countryLink);
        
        $accountData = [];
        if ($clientId && $accessToken) {
            $accountData = $this->getAccountData((string) $clientId);
        }
        $mpIntegrationName = $accountData['application_name'] ?? '';

        return $this->templateRenderer->render('admin/templates/binding_data_modal.twig', [
            'public_key' => $publicKey,
            'sandbox_public_key' => $sandboxPublicKey,
            'client_id' => $clientId,
            'country_domain' => $countryDomain,
            'mp_integration_name' => $mpIntegrationName,
        ]);
    }
    
    private function siteIdToCountryDomain(string $siteId): string
    {
        $normalizedSiteId = strtolower($siteId);
        $countryDomains = [
            'mla' => '.com.ar',
            'mlb' => '.com.br',
            'mlc' => '.cl',
            'mco' => '.com.co',
            'mlm' => '.com.mx',
            'mlu' => '.com.uy',
            'mpe' => '.com.pe',
            'mlv' => '.com.ve',
        ];
        
        return $countryDomains[$normalizedSiteId] ?? '.com';
    }

    private function renderGetIntegrationError(array $formData, string $iframeUrl): string
    {
        $sellerExperienceStatus = $this->getSellerExperienceStatus();
        $adminErrorRedirectUrl = $this->getStoreAdminURL();

        return $this->templateRenderer->render('admin/templates/get_integration_error.twig', [
            'title' => $formData['title'],
            'icon' => $formData['icon'],
            'description' => $formData['description'],
            'iframe_url' => $iframeUrl,
            'seller_experience_status' => $sellerExperienceStatus,
            'admin_error_redirect_url' => $adminErrorRedirectUrl,
        ]);
    }

    private function renderIntegrationError(array $formData, string $iframeUrl): string
    {
        return $this->templateRenderer->render('admin/templates/integration_error.twig', [
            'title' => $formData['title'],
            'icon' => $formData['icon'],
            'description' => $formData['description'],
            'iframe_url' => $iframeUrl,
        ]);
    }

    private function renderIntegrationExpired(array $formData, string $iframeUrl): string
    {
        return $this->templateRenderer->render('admin/templates/integration_expired.twig', [
            'title' => $formData['title'],
            'icon' => $formData['icon'],
            'description' => $formData['description'],
            'iframe_url' => $iframeUrl,
        ]);
    }

    private function renderIntegrationWrapperError(array $formData, string $iframeUrl): string
    {
        return $this->templateRenderer->render('admin/templates/integration_wrapper_error.twig', [
            'title' => $formData['title'],
            'icon' => $formData['icon'],
            'description' => $formData['description'],
            'iframe_url' => $iframeUrl,
        ]);
    }

    public function getFormData(): array
    {
        return [
            'title' => $this->module->l('Credentials'),
            'icon' => 'icon-key',
            'description' => '',
        ];
    }

    private static function onboardingHttpRequest(string $method, string $uri, ?array $data, array $customHeaders): array
    {
        $productIdHeader = ($method === 'POST') ? 'x-product-id: ' . MPRestCli::PRODUCT_ID : '';

        $headersDefault = array_filter([
            $productIdHeader,
            'Accept: application/json',
            'Content-Type: application/json',
            'x-platform-id: ' . MPRestCli::PLATFORM_ID,
            'x-integrator-id:' . (string) Configuration::get('MERCADOPAGO_INTEGRATOR_ID', ''),
        ]);

        $headers = array_merge($headersDefault, $customHeaders);

        $module = Module::getInstanceByName('mercadopago');

        $connect = curl_init(MPRestCli::API_BASE_MP_URL . $uri);
        curl_setopt($connect, CURLOPT_USERAGENT, 'MercadoPago Prestashop v' . $module->version);
        curl_setopt($connect, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($connect, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($connect, CURLOPT_HTTPHEADER, $headers);

        if ($data !== null) {
            $encoded = json_encode($data);
            if (json_last_error() !== JSON_ERROR_NONE) {
                curl_close($connect);
                throw new Exception('JSON Error [' . json_last_error() . '] - Data: ' . print_r($data, true));
            }
            curl_setopt($connect, CURLOPT_POSTFIELDS, $encoded);
        }

        $body = curl_exec($connect);
        $status = curl_getinfo($connect, CURLINFO_HTTP_CODE);
        curl_close($connect);

        return [
            'status' => $status,
            'response' => json_decode((string) $body, true),
        ];
    }
}
