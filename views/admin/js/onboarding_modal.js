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

document.addEventListener('DOMContentLoaded', function() {
    const onboarding_modal_component = document.querySelector("#onboarding-modal");
    
    if (!onboarding_modal_component) {
        return;
    }
    
    const onboarding_modal_clone = onboarding_modal_component.cloneNode(true);

    onboarding_modal_component.remove();

    document.querySelector("html").appendChild(onboarding_modal_clone);

    const closeBtn = onboarding_modal_clone.querySelector('.mp-credentials-modal-close');
    if (closeBtn) {
        closeBtn.addEventListener('click', function() {
            onboarding_modal_clone.style.display = 'none';
            document.querySelector('body').style.overflow = 'visible';
        });
    }
    
    const allowedOrigins = [
        'https://www.mercadopago.com',
        'https://www.mercadopago.com.ar',
        'https://www.mercadopago.com.br',
        'https://www.mercadopago.com.co',
        'https://www.mercadopago.com.mx',
        'https://www.mercadopago.com.pe',
        'https://www.mercadopago.com.uy',
        'https://www.mercadolibre.com',
    ];

    window.addEventListener('message', function(event) {
        if (!event.origin || !allowedOrigins.includes(event.origin)) {
            return;
        }

        if (event.data && event.data.type === 'onboarding-complete') {
            window.location.reload();
        }
    });
});