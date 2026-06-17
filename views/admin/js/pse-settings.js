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
 * MercadoPago Admin Panel - PSE Settings
 * Moves pre-rendered advanced config header into position
 * and wires up the collapsible toggle behaviour.
 */

document.addEventListener('DOMContentLoaded', function () {
    initPseSettings();
});

function initPseSettings() {
    var container = document.querySelector('#tab-pse-checkout');
    if (!container) {
        return;
    }

    initPseAdvancedConfig(container);
}

function initPseAdvancedConfig(container) {
    var discountGroup = container.querySelector('[name="MERCADOPAGO_PSE_DISCOUNT_PERCENT"]');
    if (!discountGroup) {
        return;
    }

    var formGroup = discountGroup.closest('.form-group');
    if (!formGroup) {
        return;
    }

    var header  = document.getElementById('mp-pse-advanced-header');
    var desc    = document.getElementById('mp-pse-advanced-desc');
    var plusBtn = document.getElementById('mp-header-plus-pse');
    var lessBtn = document.getElementById('mp-header-less-pse');

    if (!header || !desc) {
        return;
    }

    formGroup.parentNode.insertBefore(header, formGroup);
    formGroup.parentNode.insertBefore(desc, formGroup);
    formGroup.classList.add('mp-pse-collapsible');
    formGroup.style.display = 'none';
    header.style.display = '';

    var collapsed = true;

    header.addEventListener('click', function () {
        collapsed = !collapsed;
        formGroup.style.display = collapsed ? 'none' : 'block';
        desc.style.display      = collapsed ? 'none' : 'block';
        plusBtn.style.display   = collapsed ? 'inline' : 'none';
        lessBtn.style.display   = collapsed ? 'none' : 'inline';
    });
}
