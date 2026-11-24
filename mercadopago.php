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
use MercadoPago\Service\Checkout\Methods\CardCheckoutService;
use MercadoPago\Service\Checkout\Methods\PixCheckoutService;
use MercadoPago\Service\Checkout\Methods\PreferenceCheckoutService;
use MercadoPago\Service\Checkout\Methods\PseCheckoutService;
use MercadoPago\Service\Checkout\Methods\TicketCheckoutService;
use MercadoPago\Service\Checkout\Methods\YapeCheckoutService;

define('MP_VERSION', '5.0.6');
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
    public array $errors = [];
    public array $confirmations = [];

    public function __construct()
    {
        $this->name = 'mercadopago';
        $this->tab = 'payments_gateways';
        // Always update, because prestashop doesn't accept version coming from another variable (MP_VERSION)
        $this->version = '5.0.6';
        $this->author = 'MercadoPago';
        $this->need_instance = 1;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = ['min' => '9.0', 'max' => _PS_VERSION_];

        parent::__construct();

        $this->displayName = 'Mercado Pago';
        $this->description = $this->l('Customize the payment experience of your customers in your online store.');
        $this->module_key = '4380f33bbe84e7899aacb0b7a601376f';
    }

    /** Return the current request's PrestaShop context to module services. */
    public function getLegacyContext(): object
    {
        return $this->context;
    }

    /**
     * Install the module
     */
    public function install(): bool
    {
        $installationService = new MercadoPago\Service\Installation\InstallationService($this);

        return parent::install()
            && $this->registerHook('paymentOptions')
            && $this->registerHook('displayHeader')
            && $this->registerHook('displayWrapperTop')
            && $this->registerHook('displayPaymentReturn')
            && $installationService->createTables()
            && $installationService->createOrderStates($this);
    }

    /**
     * Uninstall the module
     */
    public function uninstall(): bool
    {
        return parent::uninstall();
    }

    /**
     * Load the configuration form
     */
    public function getContent(): string
    {
        if (Tools::isSubmit('ajax') && Tools::getValue('action') === 'getCredentials') {
            $this->ajaxProcessGetCredentials();
        }

        $this->registerAdminAssets();
        $this->addOrderReferenceSchemaWarning();

        $configurationService = new MercadoPago\Service\Configuration\ConfigurationService($this);

        return $configurationService->render();
    }

    /**
     * Back-office warning while the core order-reference columns cannot hold the attempt
     * reference and install/upgrade could not widen them (PPSP-2465). Cleared once both
     * widths are read and fixed; an unreadable width keeps the last recorded warning.
     */
    private function addOrderReferenceSchemaWarning(): void
    {
        if (!Configuration::get(MercadoPago\Service\Installation\InstallationService::ORDER_REFERENCE_WARNING_KEY)) {
            return;
        }

        $warning = (new MercadoPago\Service\Installation\InstallationService($this))->pendingOrderReferenceWarning();
        if ($warning === null) {
            return;
        }

        if (isset($this->context->controller) && property_exists($this->context->controller, 'warnings')) {
            $this->context->controller->warnings[] = sprintf(
                $this->l('Mercado Pago could not widen these order reference columns to VARCHAR(255): %s. Checkout Pro orders may stay pending manual reconciliation until they are widened. Ask your developer to run: %s'),
                $warning['columns'],
                $warning['sql']
            );
        }
    }

    private function ajaxProcessGetCredentials(): void
    {
        $config = new MercadoPago\Service\Configuration\ConfigurationDataService();
        $isProd = $config->isProductionMode();

        header('Content-Type: application/json');
        exit(json_encode([
            'access_token' => $isProd
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
            'views/css/admin/presentation.css',
            'views/css/admin/payment-methods.css',
            'views/css/admin/about.css',
            'views/css/admin/start_onboarding.css',
            'views/css/admin/binding_data_modal.css',
            'views/css/admin/get_integration_error.css',
            'views/css/admin/integration_error.css',
            'views/css/admin/integration_expired.css',
            'views/css/admin/integration_wrapper_error.css',
            'views/css/admin/onboarding_modal.css',
            'views/css/admin/successful_integration.css',
        ];

        $jsFiles = [
            'views/js/admin/navigation.js',
            'views/js/admin/payment-methods.js',
            'views/js/admin/about.js',
            'views/js/admin/ticket-settings.js',
            'views/js/admin/pix-settings.js',
            'views/js/admin/pse-settings.js',
            'views/js/admin/card-settings.js',
            'views/js/admin/start_onboarding.js',
            'views/js/admin/binding_data_modal.js',
            'views/js/admin/get_integration_error.js',
            'views/js/admin/integration_error.js',
            'views/js/admin/integration_expired.js',
            'views/js/admin/integration_wrapper_error.js',
            'views/js/admin/onboarding_modal.js',
            'views/js/admin/successful_integration.js',
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

        // The native JavaScript manager adds the shop base URI itself.
        $jsBasePath = 'modules/' . $this->name . '/';

        $cssFiles = [
            'views/css/client/checkout-shared.css',
            'views/css/client/card-checkout.css',
            'views/css/client/preference-checkout.css',
            'views/css/client/pix-checkout.css',
            'views/css/client/ticket-payment.css',
            'views/css/client/pse-checkout.css',
            'views/css/client/yape-checkout.css',
        ];

        foreach ($cssFiles as $cssFile) {
            if (file_exists($modulePath . $cssFile)) {
                $context->controller->addCSS($basePath . $cssFile);
            }
        }

        $isPixOrder = false;
        if ($controller->php_self === 'order-confirmation') {
            $idOrder = (int) Tools::getValue('id_order');
            if ($idOrder) {
                $order = new Order($idOrder);
                $transactionRepository = new MercadoPago\Repository\MPTransactionRepository();
                $pixData = $transactionRepository->getPixDataByCartId((int) $order->id_cart);
                $isPixOrder = !empty($pixData['qr_code_base64']);
            }
        }

        if ($isPixOrder && file_exists($modulePath . 'views/css/client/pix-pending.css')) {
            $context->controller->addCSS($basePath . 'views/css/client/pix-pending.css');
        }

        $context->controller->registerJavascript(
            'mercadopago-sdk',
            'https://sdk.mercadopago.com/js/v2',
            [
                'position' => 'head',
                'priority' => 5,
                'server' => 'remote',
            ]
        );

        if (file_exists($modulePath . 'views/js/client/masks.js')) {
            $context->controller->registerJavascript(
                'mercadopago-masks',
                $jsBasePath . 'views/js/client/masks.js',
                [
                    'position' => 'bottom',
                    'priority' => 8,
                ]
            );
        }

        if (file_exists($modulePath . 'views/js/client/document-validator.js')) {
            $context->controller->registerJavascript(
                'mercadopago-document-validator',
                $jsBasePath . 'views/js/client/document-validator.js',
                [
                    'position' => 'bottom',
                    'priority' => 10,
                ]
            );
        }

        if (file_exists($modulePath . 'views/js/client/card-checkout.js')) {
            $context->controller->registerJavascript(
                'mercadopago-card-checkout',
                $jsBasePath . 'views/js/client/card-checkout.js',
                [
                    'position' => 'bottom',
                    'priority' => 15,
                ]
            );
        }

        if (file_exists($modulePath . 'views/js/client/preference-checkout.js')) {
            $context->controller->registerJavascript(
                'mercadopago-preference-checkout',
                $jsBasePath . 'views/js/client/preference-checkout.js',
                [
                    'position' => 'bottom',
                    'priority' => 16,
                ]
            );
        }

        if (file_exists($modulePath . 'views/js/client/pix-checkout.js')) {
            $context->controller->registerJavascript(
                'mercadopago-pix-checkout',
                $jsBasePath . 'views/js/client/pix-checkout.js',
                [
                    'position' => 'bottom',
                    'priority' => 17,
                ]
            );
        }

        if (file_exists($modulePath . 'views/js/client/ticket-checkout.js')) {
            $context->controller->registerJavascript(
                'mercadopago-ticket-checkout',
                $jsBasePath . 'views/js/client/ticket-checkout.js',
                [
                    'position' => 'bottom',
                    'priority' => 18,
                ]
            );
        }

        $countryLink = strtolower((string) Configuration::get('MERCADOPAGO_COUNTRY_LINK'));
        $isPseAvailable = $countryLink === 'mco';
        $isYapeAvailable = $countryLink === 'mpe';

        if ($isPseAvailable && file_exists($modulePath . 'views/js/client/pse-checkout.js')) {
            $context->controller->registerJavascript(
                'mercadopago-pse-checkout',
                $jsBasePath . 'views/js/client/pse-checkout.js',
                [
                    'position' => 'bottom',
                    'priority' => 19,
                ]
            );
        }

        if ($isYapeAvailable && file_exists($modulePath . 'views/js/client/yape-checkout.js')) {
            $context->controller->registerJavascript(
                'mercadopago-yape-checkout',
                $jsBasePath . 'views/js/client/yape-checkout.js',
                [
                    'position' => 'bottom',
                    'priority' => 20,
                ]
            );
        }

        if ($isPixOrder && file_exists($modulePath . 'views/js/client/pix-pending.js')) {
            $context->controller->registerJavascript(
                'mercadopago-pix-pending',
                $jsBasePath . 'views/js/client/pix-pending.js',
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
            $templateRenderer = new MercadoPago\Service\Configuration\TemplateRenderer($this);

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
        $transactionRepository = new MercadoPago\Repository\MPTransactionRepository();
        $templateRenderer = new MercadoPago\Service\Configuration\TemplateRenderer($this);

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

        $currency = new Currency((int) $order->id_currency);
        $totalAmount = $this->context->currentLocale->formatPrice(
            (float) $order->total_paid,
            $currency->iso_code
        );

        $expirationFormatted = '';
        if (!empty($pixData['expiration'])) {
            $ts = strtotime($pixData['expiration']);
            if ($ts) {
                $expirationFormatted = Tools::displayDate(date('Y-m-d H:i:s', $ts), true);
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
     *
     * @return PrestaShop\PrestaShop\Core\Payment\PaymentOption[]
     */
    public function hookPaymentOptions(array $params): array
    {
        if (empty($params['cart']) || !$params['cart'] instanceof Cart || !$params['cart']->id) {
            return [];
        }

        $cart = $params['cart'];
        $serviceClasses = [
            CardCheckoutService::class,
            PixCheckoutService::class,
            PreferenceCheckoutService::class,
            TicketCheckoutService::class,
            YapeCheckoutService::class,
            PseCheckoutService::class,
        ];
        $options = [];

        foreach ($serviceClasses as $serviceClass) {
            $service = new $serviceClass($this);
            $data = $service->getPaymentOptionData($cart);
            if ($data === null) {
                continue;
            }

            $option = new PrestaShop\PrestaShop\Core\Payment\PaymentOption();
            $option->setModuleName($this->name);
            $option->setCallToActionText($data['call_to_action_text']);
            if (isset($data['action'])) {
                $option->setAction($data['action']);
            }
            if (isset($data['additional_information'])) {
                $option->setAdditionalInformation($data['additional_information']);
            }
            if (isset($data['logo'])) {
                $option->setLogo($data['logo']);
            }
            if (isset($data['form'])) {
                $option->setForm($data['form']);
            }

            $options[] = $option;
        }

        return $options;
    }
}
