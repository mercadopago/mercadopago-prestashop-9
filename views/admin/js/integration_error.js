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
    const form_credentials = document.querySelector("#module_form_1 .panel .form-wrapper");
    const original_component = document.querySelector("#integration-error");

    if (!original_component) {
        return;
    }

    let integration_error_component_clone = original_component;
    if (form_credentials) {
        integration_error_component_clone = original_component.cloneNode(true);
        original_component.remove();
        form_credentials.insertBefore(integration_error_component_clone, form_credentials.firstChild);
    }

    const retryButton = integration_error_component_clone.querySelector('#integration-error-btn');
    if (retryButton) {
        retryButton.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            location.reload();
            
            return false;
        });
    }
});