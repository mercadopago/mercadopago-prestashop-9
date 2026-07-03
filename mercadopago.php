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

define('MP_VERSION', '5.0.1');
if (!defined('_PS_VERSION_')) {
    exit;
}

// Load Composer autoloader if available
$composerAutoload = dirname(__FILE__) . '/vendor/autoload.php';
if (file_exists($composerAutoload)) {
    require_once $composerAutoload;
}

class mercadopago extends PaymentModule
{
    public $name;
    public $tab;
    public $version;
    public $author;
    public $need_instance;
    public $bootstrap;
    public $ps_versions_compliancy;
    public $displayName;
    public $description;
    public $module_key;

    public function __construct()
    {
        $this->name = 'mercadopago';
        $this->tab = 'payments_gateways';
        // Always update, because prestashop doesn't accept version coming from another variable (MP_VERSION)
        $this->version = '5.0.1';
        $this->author = 'MercadoPago';
        $this->need_instance = 1;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = ['min' => '9.0', 'max' => _PS_VERSION_];

        parent::__construct();

        $this->displayName = 'Mercado Pago';
        $this->description = $this->l('Customize the payment experience of your customers in your online store.');
        $this->module_key = '4380f33bbe84e7899aacb0b7a601376f';
    }

    /**
     * Install the module
     */
    public function install(): bool
    {
        $installationService = new \MercadoPago\Service\Installation\InstallationService($this);
        
        return parent::install() &&
            $this->registerHook('paymentOptions') &&
            $this->registerHook('displayHeader') &&
            $this->registerHook('displayWrapperTop') &&
            $this->registerHook('displayPaymentReturn') &&
            $installationService->createTables() &&
            $installationService->createOrderStates($this);
    }

    /**
     * Uninstall the module
     */
    public function uninstall(): bool
    {
        $installationService = new \MercadoPago\Service\Installation\InstallationService($this);
        
        $installationService->deleteOrderStates();

        return $installationService->dropTables() &&
            parent::uninstall();
    }

    /**
     * Load the configuration form
     */
    public function getContent(): string
    {
        if (\Tools::isSubmit('ajax') && \Tools::getValue('action') === 'getCredentials') {
            $this->ajaxProcessGetCredentials();
        }

        $this->registerAdminAssets();

        $configurationService = new \MercadoPago\Service\Configuration\ConfigurationService($this);

        return $configurationService->render();
    }

    private function ajaxProcessGetCredentials(): void
    {
        $config = new \MercadoPago\Service\Configuration\ConfigurationDataService();
        $isProd = $config->isProductionMode();

        header('Content-Type: application/json');
        exit(json_encode([
            'access_token'         => $isProd
                ? (string) $config->get('MERCADOPAGO_ACCESS_TOKEN', '')
                : (string) $config->get('MERCADOPAGO_SANDBOX_ACCESS_TOKEN', ''),
            'sandbox_access_token' => (string) $config->get('MERCADOPAGO_SANDBOX_ACCESS_TOKEN', ''),
        ]));
    }

    /**
     * Register CSS and JavaScript assets for admin panel
     *
     * @return void
     */
    private function registerAdminAssets(): void
    {
        $context = $this->context;
        $basePath = $this->getPathUri();
        $modulePath = $this->getLocalPath();

        $cssFiles = [
            'views/admin/css/presentation.css',
            'views/admin/css/payment-methods.css',
            'views/admin/css/about.css',
            'views/admin/css/start_onboarding.css',
            'views/admin/css/binding_data_modal.css',
            'views/admin/css/get_integration_error.css',
            'views/admin/css/integration_error.css',
            'views/admin/css/integration_expired.css',
            'views/admin/css/integration_wrapper_error.css',
            'views/admin/css/onboarding_modal.css',
            'views/admin/css/successful_integration.css',
        ];

        $jsFiles = [
            'views/admin/js/navigation.js',
            'views/admin/js/payment-methods.js',
            'views/admin/js/about.js',
            'views/admin/js/ticket-settings.js',
            'views/admin/js/pix-settings.js',
            'views/admin/js/pse-settings.js',
            'views/admin/js/card-settings.js',
            'views/admin/js/start_onboarding.js',
            'views/admin/js/binding_data_modal.js',
            'views/admin/js/get_integration_error.js',
            'views/admin/js/integration_error.js',
            'views/admin/js/integration_expired.js',
            'views/admin/js/integration_wrapper_error.js',
            'views/admin/js/onboarding_modal.js',
            'views/admin/js/successful_integration.js',
        ];

        foreach ($cssFiles as $cssFile) {
            if (file_exists($modulePath . $cssFile)) {
                $context->controller->addCSS($basePath . $cssFile, 'all');
            }
        }

        foreach ($jsFiles as $jsFile) {
            if (file_exists($modulePath . $jsFile)) {
                $context->controller->addJS($basePath . $jsFile);
            }
        }
    }

    /**
     * Register CSS and JavaScript assets for front-end
     * Only loads on checkout pages to optimize performance
     * Follows PrestaShop 9 best practices using Asset Manager
     *
     * @return void
     */
    public function hookDisplayHeader(): void
    {
        $context = $this->context;
        
        $controller = $context->controller;
        if (!$controller || !in_array($controller->php_self, ['order', 'checkout', 'order-opc', 'order-confirmation'])) {
            return;
        }
        
        $basePath = $this->getPathUri();
        $modulePath = $this->getLocalPath();

        $cssFiles = [
            'views/client/css/checkout-shared.css',
            'views/client/css/card-checkout.css',
            'views/client/css/preference-checkout.css',
            'views/client/css/pix-checkout.css',
            'views/client/css/ticket-payment.css',
            'views/client/css/pse-checkout.css',
            'views/client/css/yape-checkout.css',
        ];

        foreach ($cssFiles as $cssFile) {
            if (file_exists($modulePath . $cssFile)) {
                $context->controller->addCSS($basePath . $cssFile);
            }
        }

        $isPixOrder = false;
        if ($controller->php_self === 'order-confirmation') {
            $idOrder = (int) \Tools::getValue('id_order');
            if ($idOrder) {
                $order = new \Order($idOrder);
                $transactionRepository = new \MercadoPago\Repository\MPTransactionRepository();
                $pixData = $transactionRepository->getPixDataByCartId((int) $order->id_cart);
                $isPixOrder = !empty($pixData['qr_code_base64']);
            }
        }

        if ($isPixOrder && file_exists($modulePath . 'views/client/css/pix-pending.css')) {
            $context->controller->addCSS($basePath . 'views/client/css/pix-pending.css');
        }

        $context->controller->registerJavascript(
            'mercadopago-sdk',
            'https://sdk.mercadopago.com/js/v2',
            [
                'position' => 'head',
                'priority' => 5,
                'server' => 'remote'
            ]
        );

        if (file_exists($modulePath . 'views/client/js/masks.js')) {
            $context->controller->registerJavascript(
                'mercadopago-masks',
                $basePath . 'views/client/js/masks.js',
                [
                    'position' => 'bottom',
                    'priority' => 8,
                ]
            );
        }

        if (file_exists($modulePath . 'views/client/js/document-validator.js')) {
            $context->controller->registerJavascript(
                'mercadopago-document-validator',
                $basePath . 'views/client/js/document-validator.js',
                [
                    'position' => 'bottom',
                    'priority' => 10,
                ]
            );
        }

        if (file_exists($modulePath . 'views/client/js/card-checkout.js')) {
            $context->controller->registerJavascript(
                'mercadopago-card-checkout',
                $basePath . 'views/client/js/card-checkout.js',
                [
                    'position' => 'bottom',
                    'priority' => 15,
                ]
            );
        }

        if (file_exists($modulePath . 'views/client/js/preference-checkout.js')) {
            $context->controller->registerJavascript(
                'mercadopago-preference-checkout',
                $basePath . 'views/client/js/preference-checkout.js',
                [
                    'position' => 'bottom',
                    'priority' => 16,
                ]
            );
        }

        if (file_exists($modulePath . 'views/client/js/pix-checkout.js')) {
            $context->controller->registerJavascript(
                'mercadopago-pix-checkout',
                $basePath . 'views/client/js/pix-checkout.js',
                [
                    'position' => 'bottom',
                    'priority' => 17,
                ]
            );
        }

        if (file_exists($modulePath . 'views/client/js/ticket-checkout.js')) {
            $context->controller->registerJavascript(
                'mercadopago-ticket-checkout',
                $basePath . 'views/client/js/ticket-checkout.js',
                [
                    'position' => 'bottom',
                    'priority' => 18,
                ]
            );
        }

        $countryLink = strtolower((string) \Configuration::get('MERCADOPAGO_COUNTRY_LINK'));
        $isPseAvailable = $countryLink === 'mco';
        $isYapeAvailable = $countryLink === 'mpe';

        if ($isPseAvailable && file_exists($modulePath . 'views/client/js/pse-checkout.js')) {
            $context->controller->registerJavascript(
                'mercadopago-pse-checkout',
                $basePath . 'views/client/js/pse-checkout.js',
                [
                    'position' => 'bottom',
                    'priority' => 19,
                ]
            );
        }

        if ($isYapeAvailable && file_exists($modulePath . 'views/client/js/yape-checkout.js')) {
            $context->controller->registerJavascript(
                'mercadopago-yape-checkout',
                $basePath . 'views/client/js/yape-checkout.js',
                [
                    'position' => 'bottom',
                    'priority' => 20,
                ]
            );
        }

        if ($isPixOrder && file_exists($modulePath . 'views/client/js/pix-pending.js')) {
            $context->controller->registerJavascript(
                'mercadopago-pix-pending',
                $basePath . 'views/client/js/pix-pending.js',
                [
                    'position' => 'bottom',
                    'priority' => 20,
                ]
            );
        }
    }


    /**
     * Hook to display the failure message
     */
    public function hookDisplayWrapperTop()
    {
        if (Tools::getValue('typeReturn') == 'failure') {
            $cookie = $this->context->cookie;
            $redirectMessage = '';
            if ($cookie->__isset('redirect_message')) {
                $redirectMessage = $cookie->__get('redirect_message');
                $cookie->__unset('redirect_message');
            }
            $templateRenderer = new \MercadoPago\Service\Configuration\TemplateRenderer($this);
            return $templateRenderer->render('templates/hook/payment-failure.twig', [
                'redirect_message' => $redirectMessage,
            ]);
        }
    }

    /**
     * Hook to display payment info on order-confirmation page after PIX or Ticket payment
     */
    public function hookDisplayPaymentReturn(array $params): string
    {
        $order = $params['order'] ?? null;
        if (!$order) {
            return '';
        }

        // Direct instantiation is necessary here: the main PS module class is bootstrapped
        // by PrestaShop itself, not via Symfony DI, so constructor injection is not available in hooks.
        $transactionRepository = new \MercadoPago\Repository\MPTransactionRepository();
        $templateRenderer = new \MercadoPago\Service\Configuration\TemplateRenderer($this);

        $ticketData = $transactionRepository->getTicketDataByCartId((int) $order->id_cart);
        if (!empty($ticketData['ticket_url'])) {
            return $templateRenderer->render('templates/front/ticket-payment.twig', [
                'ticket_url' => $ticketData['ticket_url'],
                'digitable_line' => $ticketData['digitable_line'],
            ]);
        }

        $pixData = $transactionRepository->getPixDataByCartId((int) $order->id_cart);
        if (empty($pixData['qr_code_base64'])) {
            return '';
        }

        $currency = new \Currency((int) $order->id_currency);
        $totalAmount = $this->context->currentLocale->formatPrice(
            (float) $order->total_paid,
            $currency->iso_code
        );

        $expirationFormatted = '';
        if (!empty($pixData['expiration'])) {
            $ts = strtotime($pixData['expiration']);
            if ($ts) {
                $expirationFormatted = \Tools::displayDate(date('Y-m-d H:i:s', $ts), true);
            }
        }

        return $templateRenderer->render('templates/front/pix-pending.twig', [
            'module_dir' => $this->getPathUri(),
            'qr_code_base64' => $pixData['qr_code_base64'],
            'qr_code' => $pixData['qr_code'],
            'total_amount' => $totalAmount,
            'expiration_date' => $expirationFormatted,
        ]);
    }

    /**
     * Show payment options for PrestaShop 9
     */
    public function hookPaymentOptions(array $params): array
    {
        $checkoutService = new \MercadoPago\Service\Checkout\CheckoutService($this);

        return $checkoutService->getPaymentOptions($params);
    }
}
