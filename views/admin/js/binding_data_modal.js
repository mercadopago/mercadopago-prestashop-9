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
    'use strict';

    const bindingDataModalComponent = document.querySelector("#binding-data-modal");
    if (!bindingDataModalComponent) {
        return;
    }

    const bindingDataModalClone = bindingDataModalComponent.cloneNode(true);
    const closedIconUrl = 'https://http2.mlstatic.com/storage/cpp/static-files/15fcd268-ce2a-4b74-8698-f48d09cf8159.png';
    const openedIconUrl = 'https://http2.mlstatic.com/storage/cpp/static-files/93025abd-3c28-4a7e-99c4-f88484e1a49e.png';

    bindingDataModalComponent.remove();
    document.querySelector("html").appendChild(bindingDataModalClone);

    const closeIcon = bindingDataModalClone.querySelector('.mp-close-icon');
    if (closeIcon) {
        closeIcon.addEventListener('click', () => {
            bindingDataModalClone.style.display = 'none';
            document.querySelector('body').style.overflow = 'visible';
        });
    }

    const currentParams = new URLSearchParams(window.location.search);
    const ajaxUrl = window.location.pathname
        + '?controller=AdminModules'
        + '&configure=mercadopago'
        + '&ajax=1'
        + '&action=getCredentials'
        + '&token=' + encodeURIComponent(currentParams.get('token') || '');

    let cachedCredentials = null;

    async function fetchCredentials() {
        if (cachedCredentials) {
            return cachedCredentials;
        }
        const response = await fetch(ajaxUrl, { credentials: 'same-origin' });
        if (!response.ok) {
            throw new Error('Failed to load credentials');
        }
        cachedCredentials = await response.json();
        return cachedCredentials;
    }

    const revealProdBtn = bindingDataModalClone.querySelector('#reveal-prod-credentials-btn');
    if (revealProdBtn) {
        revealProdBtn.addEventListener('click', async (e) => {
            const prodAccessToken = bindingDataModalClone.querySelector('#prod-access-token');
            if (!prodAccessToken) return;
            const isVisible = credentialsIsVisible(e.target);
            toggleEyeIcon(e.target, isVisible);
            if (!isVisible) {
                try {
                    const creds = await fetchCredentials();
                    prodAccessToken.textContent = creds.access_token || '';
                } catch (_) {
                    prodAccessToken.textContent = prodAccessToken.getAttribute('data-mask');
                }
            } else {
                prodAccessToken.textContent = prodAccessToken.getAttribute('data-mask');
            }
        });
    }

    const revealTestBtn = bindingDataModalClone.querySelector('#reveal-test-credentials-btn');
    if (revealTestBtn) {
        revealTestBtn.addEventListener('click', async (e) => {
            const testAccessToken = bindingDataModalClone.querySelector('#test-access-token');
            if (!testAccessToken) return;
            const isVisible = credentialsIsVisible(e.target);
            toggleEyeIcon(e.target, isVisible);
            if (!isVisible) {
                try {
                    const creds = await fetchCredentials();
                    testAccessToken.textContent = creds.sandbox_access_token || '';
                } catch (_) {
                    testAccessToken.textContent = testAccessToken.getAttribute('data-mask');
                }
            } else {
                testAccessToken.textContent = testAccessToken.getAttribute('data-mask');
            }
        });
    }

    const bindingDataLink = document.querySelector('#binding-data-link');
    if (bindingDataLink) {
        bindingDataLink.addEventListener('click', (e) => {
            e.preventDefault();
            bindingDataModalClone.style.display = 'flex';
            document.querySelector('body').style.overflow = 'hidden';
        });
    }

    function credentialsIsVisible(currentElement) {
        const currentSrcUrl = currentElement.currentSrc || currentElement.src;
        return currentSrcUrl === openedIconUrl;
    }

    function toggleEyeIcon(currentElement, isVisible) {
        currentElement.setAttribute('src', isVisible ? closedIconUrl : openedIconUrl);
    }
    })();
});
