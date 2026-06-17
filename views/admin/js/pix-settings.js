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
 * MercadoPago Admin Panel - Pix Settings
 * Adds the "Importante" note below the expiration field and wraps the
 * discount field in a "Configuração Avançada" collapsible section.
 */

document.addEventListener('DOMContentLoaded', function () {
    initPixSettings();
});

function initPixSettings() {
    const container = document.querySelector('#tab-pix-checkout');
    if (!container) {
        return;
    }

    initImportantNote(container);
    initPixAdvancedConfig(container);
}

function initImportantNote(container) {
    const expiration = container.querySelector('[name="MERCADOPAGO_PIX_EXPIRATION"]');
    if (!expiration) {
        return;
    }

    const formGroup = expiration.closest('.form-group');
    if (!formGroup) {
        return;
    }

    const translations = window.mpPixTranslations || {};
    const noteText = translations.importantNote ||
        'Important: You can manage the Pix key(s) registered in your account through the Mercado Pago app.';

    const parts = noteText.split(':');
    const label = parts.shift();
    const rest = parts.join(':');

    const noteWrapper = document.createElement('div');
    noteWrapper.className = 'form-group mp-pix-important-note';

    const col = document.createElement('div');
    col.className = 'col-lg-12';

    const p = document.createElement('p');

    const strong = document.createElement('strong');
    strong.textContent = label + ':';

    p.appendChild(strong);
    p.appendChild(document.createTextNode(rest));
    col.appendChild(p);
    noteWrapper.appendChild(col);

    formGroup.insertAdjacentElement('afterend', noteWrapper);
}

function initPixAdvancedConfig(container) {
    const discount = container.querySelector('[name="MERCADOPAGO_PIX_DISCOUNT"]');
    if (!discount) {
        return;
    }

    const formGroup = discount.closest('.form-group');
    if (!formGroup) {
        return;
    }

    const translations = window.mpPixTranslations || {};
    const advancedTitle = translations.advancedConfig || 'Advanced Configuration';
    const advancedDesc  = translations.advancedDesc   ||
        'Offer discounts on payments with Pix. The defined percentage will be deducted from the total purchase value.';

    const header = document.createElement('div');
    header.className = 'mp-panel-advanced-config';

    const icon = document.createElement('i');
    icon.className = 'icon-cogs';
    header.appendChild(icon);
    header.appendChild(document.createTextNode(' ' + advancedTitle));

    const plusBtn = document.createElement('span');
    plusBtn.className = 'mp-btn-collapsible';
    plusBtn.textContent = '+';
    header.appendChild(plusBtn);

    const lessBtn = document.createElement('span');
    lessBtn.className = 'mp-btn-collapsible';
    lessBtn.style.display = 'none';
    lessBtn.textContent = '-';
    header.appendChild(lessBtn);

    const desc = document.createElement('div');
    desc.className = 'mp-advanced-config-description';

    const h4 = document.createElement('h4');
    h4.className = 'mp-title-checkout-body';
    h4.textContent = advancedDesc;
    desc.appendChild(h4);

    formGroup.parentNode.insertBefore(header, formGroup);
    formGroup.parentNode.insertBefore(desc, formGroup);
    formGroup.classList.add('mp-pix-collapsible');
    formGroup.style.display = 'none';
    desc.style.display = 'none';

    let collapsed = true;

    header.addEventListener('click', function () {
        collapsed = !collapsed;
        formGroup.style.display = collapsed ? 'none' : 'block';
        desc.style.display      = collapsed ? 'none' : 'block';
        plusBtn.style.display   = collapsed ? 'inline' : 'none';
        lessBtn.style.display   = collapsed ? 'none' : 'inline';
    });
}
