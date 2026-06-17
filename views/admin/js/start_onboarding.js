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
    const start_onboarding_component = document.querySelector("#start-onboarding");
    
    if (!start_onboarding_component) {
        return;
    }
    
    const button = start_onboarding_component.querySelector('#start-automatic-credentials');
    if (button) {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            const redirectUrl = button.getAttribute('data-redirect-url');
            if (redirectUrl && redirectUrl.startsWith('https://')) {
                window.open(redirectUrl, "_self");
            }
            return false;
        });
    }
});

