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

namespace MercadoPago\Service\Checkout\Methods;

use MercadoPago\Client\MPApi;
use MercadoPago\Service\Configuration\ConfigurationDataService;
use MercadoPago\Service\Configuration\TemplateRenderer;
use PrestaShop\PrestaShop\Core\Payment\PaymentOption;
use Context;

/**
 * Card Checkout Service (Checkout Transparente)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class CardCheckoutService extends AbstractCheckoutService
{
    /**
     * @var MPApi
     */
    private $mpApi;

    /**
     * @param \mercadopago $module
     * @param ConfigurationDataService|null $configDataService
     * @param TemplateRenderer|null $templateRenderer
     */
    public function __construct(
        \mercadopago $module,
        ?ConfigurationDataService $configDataService = null,
        ?TemplateRenderer $templateRenderer = null
    ) {
        parent::__construct($module, $configDataService, $templateRenderer);
        $this->mpApi = new MPApi();
    }

    /**
     * @return string
     */
    protected function getEnabledConfigKey(): string
    {
        return 'MERCADOPAGO_CUSTOM_ENABLED';
    }

    /**
     * @return array
     */
    public function getAcceptedPaymentMethods(): array
    {
        $credit = [];
        $debit  = [];

        $result = $this->mpApi->getPaymentMethods();

        if (!$result || $result['status'] > 202) {
            return ['credit' => $credit, 'debit' => $debit];
        }

        foreach ($result['response'] as $raw) {
            $method = self::normalizePaymentMethod($raw);

            if ($method['type'] === 'credit_card') {
                $credit[] = $method;
            } elseif ($method['type'] === 'debit_card' || $method['type'] === 'prepaid_card') {
                $debit[] = $method;
            }
        }

        return ['credit' => $credit, 'debit' => $debit];
    }

    /**
     * @param \Cart $cart
     * @return PaymentOption|null
     */
    public function createPaymentOption(\Cart $cart): ?PaymentOption
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $customContollerLink = Context::getContext()->link->getModuleLink($this->module->name, 'card');
        $siteId = $this->configDataService->get('MERCADOPAGO_COUNTRY_LINK');
        $actionUrl = \Context::getContext()->link->getModuleLink($this->module->name, 'card', [], true);

        $prodStatus = $this->configDataService->get('MERCADOPAGO_PROD_STATUS');
        $configKey = $prodStatus ? 'MERCADOPAGO_PUBLIC_KEY' : 'MERCADOPAGO_SANDBOX_PUBLIC_KEY';
        $publicKey = $this->configDataService->get($configKey, '');

        if (empty($publicKey)) {
            return null;
        }

        $acceptedMethods = $this->getAcceptedPaymentMethods();

        $option = new PaymentOption();
        $option->setModuleName($this->module->name);
        $option->setCallToActionText($this->module->l('Credit or debit card'));
        $option->setAdditionalInformation(
            $this->templateRenderer->render(
                'client/templates/checkout/card-checkout.twig',
                [
                    'site_id'           => $siteId,
                    'action_url'        => $actionUrl,
                    'public_key'        => $publicKey,
                    'amount'            => $cart->getOrderTotal(true),
                    'credit_cards'      => $acceptedMethods['credit'],
                    'debit_cards'       => $acceptedMethods['debit'],
                    'terms_url'         => $this->getTermsUrl((string) $siteId),
                    'wallet_button'             => ((string) ($this->configDataService->get('MERCADOPAGO_CUSTOM_WALLET_BUTTON') ?? '1')) !== '0',
                    'wallet_button_url'         => Context::getContext()->link->getModuleLink($this->module->name, 'walletbutton', [], true),
                    'wallet_button_title'       => $this->module->l('Pay with your saved cards'),
                    'wallet_button_description' => $this->module->l('Access Mercado Pago and pay faster without filling out forms.'),
                ]
            )
        );
        $option->setAction($customContollerLink);

        return $option;
    }
}
