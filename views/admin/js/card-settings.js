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
 * MercadoPago Admin Panel - Card Settings
 * Moves pre-rendered advanced config header into position
 * and wires up the collapsible toggle behaviour.
 */

document.addEventListener('DOMContentLoaded', function () {
    initCardSettings();
});

function initCardSettings() {
    var container = document.querySelector('#tab-custom-checkout');
    if (!container) {
        return;
    }

    initCardAdvancedConfig(container);
}

function initCardAdvancedConfig(container) {
    var binaryModeEl = container.querySelector('[name="MERCADOPAGO_CUSTOM_BINARY_MODE"]');
    if (!binaryModeEl) {
        return;
    }

    var binaryModeGroup = binaryModeEl.closest('.form-group');
    if (!binaryModeGroup) {
        return;
    }

    var discountEl = container.querySelector('[name="MERCADOPAGO_CUSTOM_DISCOUNT_PERCENT"]');
    var discountGroup = discountEl ? discountEl.closest('.form-group') : null;

    var header  = document.getElementById('mp-card-advanced-header');
    var desc    = document.getElementById('mp-card-advanced-desc');
    var plusBtn = document.getElementById('mp-header-plus-card');
    var lessBtn = document.getElementById('mp-header-less-card');

    if (!header || !desc) {
        return;
    }

    binaryModeGroup.parentNode.insertBefore(header, binaryModeGroup);
    binaryModeGroup.parentNode.insertBefore(desc, binaryModeGroup);
    binaryModeGroup.style.display = 'none';
    if (discountGroup) {
        discountGroup.style.display = 'none';
    }
    header.style.display = '';

    var configureFeesEl = document.getElementById('mp-card-configure-fees');
    if (configureFeesEl) {
        binaryModeGroup.parentNode.insertBefore(configureFeesEl, header);
        configureFeesEl.style.display = '';
    }

    var collapsed = true;

    header.addEventListener('click', function () {
        collapsed = !collapsed;
        binaryModeGroup.style.display = collapsed ? 'none' : 'block';
        if (discountGroup) {
            discountGroup.style.display = collapsed ? 'none' : 'block';
        }
        desc.style.display      = collapsed ? 'none' : 'block';
        plusBtn.style.display   = collapsed ? 'inline' : 'none';
        lessBtn.style.display   = collapsed ? 'none' : 'inline';
    });
}
