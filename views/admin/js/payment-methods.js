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

/**
 * MercadoPago Admin Panel - Payment Methods Tabs Navigation
 */

document.addEventListener('DOMContentLoaded', function() {
    // Payment methods tab navigation
    initPaymentMethodsTabs();
});

/**
 * Initialize payment methods tab navigation
 */
function initPaymentMethodsTabs() {
    const tabLinks = document.querySelectorAll('.mercadopago-payment-methods-tabs .nav-tabs a[data-toggle="tab"]');
    
    tabLinks.forEach(function(link) {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            
            // Remove active class from all tabs
            const tabs = document.querySelectorAll('.mercadopago-payment-methods-tabs .nav-tabs li');
            tabs.forEach(function(tab) {
                tab.classList.remove('active');
            });
            
            // Add active class to clicked tab
            this.parentElement.classList.add('active');
            
            // Show corresponding tab content
            const targetId = this.getAttribute('href');
            const tabPanes = document.querySelectorAll('.mercadopago-payment-methods-tabs .tab-content .tab-pane');
            tabPanes.forEach(function(pane) {
                pane.classList.remove('active');
            });
            
            const targetPane = document.querySelector(targetId);
            if (targetPane) {
                targetPane.classList.add('active');
            }
        });
    });
}

