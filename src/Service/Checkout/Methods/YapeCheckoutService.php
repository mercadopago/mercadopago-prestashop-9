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

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;
use Context;

/**
 * Yape Checkout Service
 * Handles Yape payment method display in checkout (Peru only)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class YapeCheckoutService extends AbstractCheckoutService
{
    protected function getEnabledConfigKey(): string
    {
        return 'MERCADOPAGO_YAPE_ENABLED';
    }

    public function isAvailableForCountry(): bool
    {
        $siteId = $this->configDataService->get('MERCADOPAGO_COUNTRY_LINK');
        return strtolower((string) $siteId) === 'mpe';
    }

    public function createPaymentOption(\Cart $cart): ?PaymentOption
    {
        if (!$this->isEnabled() || !$this->isAvailableForCountry()) {
            return null;
        }

        $actionUrl = Context::getContext()->link->getModuleLink($this->module->name, 'yape', [], true);

        $option = new PaymentOption();
        $option->setModuleName($this->module->name);
        $option->setCallToActionText($this->module->l('Yape'));
        $option->setForm(
            $this->templateRenderer->render('client/templates/checkout/yape-checkout.twig', [
                'module_dir'      => $this->module->getPathUri(),
                'action_url'      => $actionUrl,
                'terms_url'       => $this->getTermsUrl('MPE'),
                'text_title'      => $this->module->l('How to pay with Yape'),
                'text_instructions' => $this->module->l('Open the Yape app and generate your OTP code, then fill in the fields below.'),
                'text_phone_label' => $this->module->l('Phone number'),
                'text_phone_hint'  => $this->module->l('Enter your phone number registered in Yape'),
                'text_otp_label'   => $this->module->l('OTP code'),
                'text_otp_hint'    => $this->module->l('Enter the 6-digit code generated in the Yape app'),
                'text_phone_error' => $this->module->l('Enter a valid 9-digit phone number'),
                'text_otp_error'   => $this->module->l('Enter the 6-digit OTP code from the Yape app'),
                'text_required'    => $this->module->l('Obligatory field'),
                'text_terms'       => $this->module->l('By continuing, you agree to our '),
                'text_terms_link'  => $this->module->l('Terms and Conditions'),
            ])
        );

        return $option;
    }
}
