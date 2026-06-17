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

use MercadoPago\Service\Configuration\ConfigurationDataService;
use PrestaShop\PrestaShop\Core\Payment\PaymentOption;
use Context;

/**
 * PIX Checkout Service
 * Handles PIX payment method display in checkout (Brazil only)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class PixCheckoutService extends AbstractCheckoutService
{
    public function __construct(
        \mercadopago $module,
        ?ConfigurationDataService $configDataService = null
    ) {
        parent::__construct($module, $configDataService);
    }

    protected function getEnabledConfigKey(): string
    {
        return 'MERCADOPAGO_PIX_ENABLED';
    }

    public function isAvailableForCountry(): bool
    {
        $siteId = $this->configDataService->get('MERCADOPAGO_COUNTRY_LINK');
        return strtolower((string) $siteId) === 'mlb';
    }

    public function createPaymentOption(\Cart $cart): ?PaymentOption
    {
        if (!$this->isEnabled() || !$this->isAvailableForCountry()) {
            return null;
        }

        $actionUrl = Context::getContext()->link->getModuleLink($this->module->name, 'pix', [], true);

        $option = new PaymentOption();
        $option->setModuleName($this->module->name);
        $option->setCallToActionText($this->module->l('PIX'));
        $option->setAction($actionUrl);
        $option->setAdditionalInformation(
            $this->templateRenderer->render('client/templates/checkout/pix-checkout.twig', [
                'module_dir' => $this->module->getPathUri(),
                'terms_url' => $this->getTermsUrl('MLB'),
            ])
        );

        return $option;
    }
}
