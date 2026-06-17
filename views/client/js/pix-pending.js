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
 * MercadoPago PIX Pending Page
 * Handles QR code copy-to-clipboard functionality.
 */
(function () {
    'use strict';

    function copyToClipboard(inputId, buttonId) {
        var input = document.getElementById(inputId);
        var button = document.getElementById(buttonId);

        if (!input || !button) {
            return;
        }

        button.addEventListener('click', function () {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(input.value).then(function () {
                    flashButton(button);
                });
            } else {
                input.select();
                document.execCommand('copy');
                flashButton(button);
            }
        });
    }

    function flashButton(button) {
        var original = button.textContent;
        button.textContent = '✓';
        setTimeout(function () {
            button.textContent = original;
        }, 1500);
    }

    document.addEventListener('DOMContentLoaded', function () {
        copyToClipboard('mp-pix-input-code', 'mp-pix-copy-code');
        copyToClipboard('mp-pix-input-code-mobile', 'mp-pix-copy-code-mobile');
    });
})();
