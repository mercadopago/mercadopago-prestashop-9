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

(function () {
  var container = document.querySelector(
    ".mp-checkout-preference[data-mp-modal]"
  );
  var mpMessageHandler = null;
  var trustedDomains = [
    "mercadopago.com",
    "mercadopago.com.ar",
    "mercadopago.com.br",
    "mercadopago.com.co",
    "mercadopago.com.mx",
    "mercadopago.com.pe",
    "mercadopago.com.uy",
    "mercadopago.cl",
  ];

  function isTrustedOrigin(origin) {
    if (!origin) {
      return false;
    }

    try {
      var hostname = new URL(origin).hostname.toLowerCase();
      return trustedDomains.some(function (domain) {
        return hostname === domain || hostname.endsWith("." + domain);
      });
    } catch (e) {
      return false;
    }
  }

  function hasTrustedSource(src) {
    if (!src) {
      return false;
    }

    try {
      return isTrustedOrigin(new URL(src, window.location.href).origin);
    } catch (e) {
      return false;
    }
  }

  function removeMPOverlay() {
    document
      .querySelectorAll(".mp-mercadopago-checkout-wrapper")
      .forEach(function (el) {
        el.remove();
      });
    document.querySelectorAll("#mercadopago-checkout").forEach(function (el) {
      el.remove();
    });
    document.querySelectorAll("body > iframe").forEach(function (el) {
      if (hasTrustedSource(el.src)) {
        el.remove();
      }
    });

    document.body.style.overflow = "";
    document.body.style.position = "";
    document.body.style.top = "";
    document.body.style.width = "";
  }

  function watchForMPClose() {
    if (mpMessageHandler) {
      window.removeEventListener("message", mpMessageHandler);
    }

    mpMessageHandler = function (event) {
      if (!isTrustedOrigin(event.origin)) {
        return;
      }

      try {
        var message =
          typeof event.data === "string" ? JSON.parse(event.data) : event.data;

        if (
          message &&
          (message.type === "close" || message.action === "finalize")
        ) {
          window.removeEventListener("message", mpMessageHandler);
          mpMessageHandler = null;
          removeMPOverlay();
        }
      } catch (e) {
        return;
      }
    };

    window.addEventListener("message", mpMessageHandler);
  }

  function redirectToFailure() {
    var failureUrl = container
      ? container.getAttribute("data-mp-failure-url")
      : null;
    window.location.href = failureUrl || "index.php?controller=order&step=3&typeReturn=failure";
  }

  function openMercadoPagoModal(publicKey, preferenceId) {
    if (typeof MercadoPago === "undefined") {
      redirectToFailure();
      return;
    }

    var mp = new MercadoPago(publicKey);
    mp.checkout({
      preference: { id: preferenceId },
      autoOpen: true,
    });
    watchForMPClose();
  }

  jQuery(function () {
    if (!container) return;
    if (container.getAttribute("data-mp-modal") !== "true") return;

    var publicKey = container.getAttribute("data-mp-public-key") || "";
    var actionUrl = container.getAttribute("data-mp-action-url") || "";

    if (!publicKey || !actionUrl) return;

    var confirmButton = document.querySelector(
      "#payment-confirmation button[type='submit']"
    );

    if (!confirmButton) return;

    confirmButton.addEventListener("click", function (e) {
      if (!container || container.offsetParent === null) {
        return;
      }

      e.preventDefault();
      e.stopPropagation();

      fetch(actionUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
      })
        .then(function (response) {
          return response.json();
        })
        .then(function (data) {
          if (data.preference && data.preference.id) {
            openMercadoPagoModal(publicKey, data.preference.id);
          } else {
            redirectToFailure();
          }
        })
        .catch(function () {
          redirectToFailure();
        });
    });
  });
})();
