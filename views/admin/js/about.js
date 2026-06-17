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
 * MercadoPago Admin Panel - About Tab JavaScript
 */

document.addEventListener('DOMContentLoaded', function() {
    // Smooth scroll para links internos
    document.querySelectorAll('a[href^="#"]').forEach(function(link) {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            const target = document.querySelector(this.getAttribute('href'));
            if (target) {
                target.scrollIntoView({ behavior: 'smooth' });
            }
        });
    });

    // Verificar se a imagem de fundo carrega
    const footerElement = document.querySelector('.mercadopago-footer');
    if (footerElement) {
        const imageUrl = 'https://http2.mlstatic.com/storage/cpp/static-files/8351df1d-7a12-4803-a876-69fe7dc34969.jpg';

        // Testar se a imagem carrega
        const testImage = new Image();
        testImage.onerror = function() {
            // Imagem falhou - aplicar fallback
            footerElement.setAttribute('data-bg-failed', 'true');
        };
        testImage.src = imageUrl;
    }
});

