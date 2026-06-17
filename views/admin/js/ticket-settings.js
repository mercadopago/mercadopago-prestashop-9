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
 * MercadoPago Admin Panel - Ticket Settings
 * Moves pre-rendered master checkbox and advanced config header into position
 * and wires up the collapsible toggle and select-all behaviour.
 */

document.addEventListener('DOMContentLoaded', function () {
    initTicketSettings();
});

function initTicketSettings() {
    var container = document.querySelector('#tab-ticket-boleto');
    if (!container) {
        return;
    }

    initMasterCheckbox(container);
    initAdvancedConfig(container);
}

function initMasterCheckbox(container) {
    var ticketInputs = container.querySelectorAll('input[name^="MERCADOPAGO_TICKET_PAYMENT_"]');
    if (ticketInputs.length === 0) {
        return;
    }

    var group = document.getElementById('mp-ticket-checkbox-group');
    var masterCheckbox = document.getElementById('mp-checkmeticket');
    if (!group || !masterCheckbox) {
        return;
    }

    var firstCheckbox = ticketInputs[0].closest('.checkbox');
    if (!firstCheckbox) {
        return;
    }

    var allChecked = Array.prototype.every.call(ticketInputs, function (input) {
        return input.checked;
    });
    masterCheckbox.checked = allChecked;

    firstCheckbox.parentNode.insertBefore(group, firstCheckbox);
    ticketInputs.forEach(function (input) {
        var checkboxDiv = input.closest('.checkbox');
        if (checkboxDiv) {
            group.appendChild(checkboxDiv);
        }
    });
    group.style.display = '';

    masterCheckbox.addEventListener('change', function () {
        ticketInputs.forEach(function (input) {
            input.checked = masterCheckbox.checked;
        });
    });

    ticketInputs.forEach(function (input) {
        input.addEventListener('change', function () {
            masterCheckbox.checked = Array.prototype.every.call(ticketInputs, function (i) {
                return i.checked;
            });
        });
    });
}

function initAdvancedConfig(container) {
    var discountGroup = container.querySelector('[name="MERCADOPAGO_TICKET_DISCOUNT"]');
    if (!discountGroup) {
        return;
    }

    var formGroup = discountGroup.closest('.form-group');
    if (!formGroup) {
        return;
    }

    var header  = document.getElementById('mp-ticket-advanced-header');
    var desc    = document.getElementById('mp-ticket-advanced-desc');
    var plusBtn = document.getElementById('mp-header-plus-ticket');
    var lessBtn = document.getElementById('mp-header-less-ticket');

    if (!header || !desc) {
        return;
    }

    formGroup.parentNode.insertBefore(header, formGroup);
    formGroup.parentNode.insertBefore(desc, formGroup);
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
