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

/* global stripNonDigits */
(function () {
  document.addEventListener('DOMContentLoaded', function () {
    initFormSubmit();
  });

  /**
   * The Yape form is nested inside the PS checkout form, so the outer confirm
   * button doesn't trigger the inner submit. We intercept it, validate, and
   * submit manually.
   */
  function initFormSubmit() {
    var form = document.getElementById('mp-yape-checkout');
    var phoneInput = document.getElementById('mp-yape-phone');
    var otpInput = document.getElementById('mp-yape-otp');
    var phoneError = document.getElementById('mp-yape-phone-error');
    var otpError = document.getElementById('mp-yape-otp-error');

    if (!form || !phoneInput || !otpInput || !phoneError || !otpError) {
      return;
    }

    var confirmButton = document.querySelector('#payment-confirmation button[type="submit"]');
    if (!confirmButton) return;

    phoneInput.addEventListener('input', function () {
      this.value = stripNonDigits(this.value);
      clearError(this, phoneError);
    });

    otpInput.addEventListener('input', function () {
      this.value = stripNonDigits(this.value);
      clearError(this, otpError);
    });

    confirmButton.addEventListener('click', function (e) {
      // Only act when the Yape form is visible (payment method selected)
      if (!isFormVisible(form)) {
        return;
      }

      var isPhoneValid = /^\d{9}$/.test(phoneInput.value.trim());
      var isOtpValid = /^\d{6}$/.test(otpInput.value.trim());

      if (isPhoneValid) {
        clearError(phoneInput, phoneError);
      } else {
        showError(phoneInput, phoneError);
      }

      if (isOtpValid) {
        clearError(otpInput, otpError);
      } else {
        showError(otpInput, otpError);
      }

      if (!isPhoneValid || !isOtpValid) {
        e.preventDefault();
        e.stopImmediatePropagation();
        (isPhoneValid ? otpInput : phoneInput).focus();
        return;
      }

      e.preventDefault();
      e.stopImmediatePropagation();
      disableFinishOrderButton(confirmButton);
      form.submit();
    });
  }

  function clearError(input, errorEl) {
    input.classList.remove('mp-form-control-error');
    input.removeAttribute('aria-invalid');
    errorEl.style.display = 'none';
  }

  function showError(input, errorEl) {
    input.classList.add('mp-form-control-error');
    input.setAttribute('aria-invalid', 'true');
    errorEl.style.display = 'block';
  }

  /**
   * Robust visibility check — covers display:none (offsetParent),
   * visibility:hidden and opacity:0. PrestaShop themes toggle payment
   * options differently across versions, so we cover all three.
   */
  function isFormVisible(form) {
    if (form.offsetParent === null) return false;
    var styles = window.getComputedStyle(form);
    return styles.visibility !== 'hidden' && parseFloat(styles.opacity) > 0;
  }

  /**
   * Disables the PrestaShop finish order button to prevent double submission.
   */
  function disableFinishOrderButton(button) {
    if (button) {
      button.setAttribute('disabled', 'disabled');
      button.classList.add('disabled');
    }
  }
})();
