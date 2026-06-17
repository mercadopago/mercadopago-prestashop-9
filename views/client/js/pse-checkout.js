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
  document.addEventListener('DOMContentLoaded', function () {
    initFormSubmit();
  });

  /* ── Form submit ── */

  /**
   * The PSE form is nested inside the PS checkout form, so the outer confirm
   * button doesn't trigger the inner submit. We intercept it, validate, and
   * submit manually.
   */
  function initFormSubmit() {
    var form = document.getElementById('mp-pse-checkout');
    if (!form) return;

    var confirmButton = document.querySelector('#payment-confirmation button[type="submit"]');
    if (!confirmButton) return;

    confirmButton.addEventListener('click', function (e) {
      // Only act when the PSE form is visible (payment method selected)
      if (form.offsetParent === null) {
        return;
      }

      if (!validateAllFields()) {
        e.preventDefault();
        e.stopImmediatePropagation();
        uncheckedTerms();
        return;
      }

      e.preventDefault();
      e.stopImmediatePropagation();
      disableFinishOrderButton();
      form.submit();
    });
  }

  /* ── Validation ── */

  /**
   * Returns the four required form fields with their inputs and error elements.
   * @return {Object}
   */
  function getFormFields() {
    return {
      personType: {
        input: document.getElementById('mp-pse-person-type'),
        error: document.getElementById('mp-pse-person-type-error')
      },
      documentType: {
        input: document.getElementById('mp-pse-document-type'),
        error: document.getElementById('mp-pse-document-type-error')
      },
      documentNumber: {
        input: document.getElementById('mp-pse-document-number'),
        error: document.getElementById('mp-pse-document-number-error')
      },
      financialInstitution: {
        input: document.getElementById('mp-pse-bank'),
        error: document.getElementById('mp-pse-bank-error')
      }
    };
  }

  /**
   * Validates required fields and document number format.
   * @return {boolean}
   */
  function validateAllFields() {
    var fields = getFormFields();
    var keys = ['personType', 'documentType', 'documentNumber', 'financialInstitution'];
    var isValid = true;
    var firstInvalid = null;

    for (var i = 0; i < keys.length; i++) {
      var field = fields[keys[i]];
      var hasValue = !!(field.input && field.input.value);
      setFieldError(field.input, field.error, !hasValue);
      if (!hasValue) {
        isValid = false;
        if (!firstInvalid) firstInvalid = field.input;
      }
    }

    if (fields.documentType.input.value && fields.documentNumber.input.value) {
      var docOk = isDocumentNumberValid(fields.documentType.input, fields.documentNumber.input.value);
      if (!docOk) {
        setFieldError(fields.documentNumber.input, fields.documentNumber.error, true);
        isValid = false;
        if (!firstInvalid) firstInvalid = fields.documentNumber.input;
      }
    }

    if (firstInvalid) firstInvalid.focus();
    return isValid;
  }

  /**
   * Validates the document number against the selected option's
   * data-minlength, data-maxlength and data-type attributes.
   * @param {HTMLSelectElement} docTypeSelect
   * @param {string} value
   * @return {boolean}
   */
  function isDocumentNumberValid(docTypeSelect, value) {
    var option = Array.from(docTypeSelect.options).find(function (o) {
      return o.value === docTypeSelect.value;
    });
    if (!option) return false;

    var min = parseInt(option.getAttribute('data-minlength'), 10);
    var max = parseInt(option.getAttribute('data-maxlength'), 10);
    var type = option.getAttribute('data-type');

    if (!isNaN(min) && value.length < min) return false;
    if (!isNaN(max) && value.length > max) return false;
    if (type === 'number' && !/^\d+$/.test(value)) return false;

    return true;
  }

  /* ── Helpers ── */

  /**
   * Shows or hides a field error state.
   * @param {HTMLElement} input
   * @param {HTMLElement} errorEl
   * @param {boolean} hasError
   */
  function setFieldError(input, errorEl, hasError) {
    if (hasError) {
      if (errorEl) errorEl.style.display = 'inline-block';
      if (input) input.classList.add('mp-form-control-error');
    } else {
      if (errorEl) errorEl.style.display = 'none';
      if (input) input.classList.remove('mp-form-control-error');
    }
  }

  /**
   * Disables the PrestaShop finish order button to prevent double submission.
   */
  function disableFinishOrderButton() {
    var button = document.querySelector('#payment-confirmation button[type="submit"]');
    if (button) {
      button.setAttribute('disabled', 'disabled');
      button.classList.add('disabled');
    }
  }

  /**
   * Unchecks the PrestaShop T&C checkbox to block PS's auto-submit when
   * validation fails, allowing field errors to surface first.
   *
   * Side effect: user must re-check T&C after fixing errors.
   */
  function uncheckedTerms() {
    var terms = document.getElementById('conditions_to_approve[terms-and-conditions]');
    if (terms) {
      terms.checked = false;
    }
  }
})();
