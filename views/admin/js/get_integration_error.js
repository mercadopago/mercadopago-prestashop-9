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
    (function() {
    const form_credentials = document.querySelector("#module_form_1 .panel .form-wrapper");
    const original_component = document.querySelector("#error-get-integration");

    if (!original_component) {
        return;
    }

    let successful_integration_component_clone = original_component;
    if (form_credentials) {
        successful_integration_component_clone = original_component.cloneNode(true);
        original_component.remove();
        form_credentials.insertBefore(successful_integration_component_clone, form_credentials.firstChild);
        form_credentials.classList.remove('form-wrapper');
    }

    const switchAccountBtn = successful_integration_component_clone.querySelector('#switch-account-btn');
    if (switchAccountBtn) {
        switchAccountBtn.addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();

        const button = successful_integration_component_clone.querySelector('#switch-account-btn');
        const redirectUrl = button.getAttribute('data-redirect-url');

        if (redirectUrl && redirectUrl.startsWith('https://')) {
            window.open(redirectUrl, "_self");
        }

        return false;
        });
    }

    const bindingDataLink = successful_integration_component_clone.querySelector('#binding-data-link');
    if (bindingDataLink) {
        bindingDataLink.addEventListener('click', () => {
            const modal = document.querySelector("#binding-data-modal");
            if (modal) {
                modal.style.display = 'flex';
                document.querySelector('body').style.overflow = 'hidden';
            }
        });
    }
    })();
});