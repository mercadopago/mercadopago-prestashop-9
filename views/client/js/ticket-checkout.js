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

/* global validateDocument, maskCpf, maskCnpjAlphanumeric, maskCI, stripNonDigits */

(function () {
  var currentDocType = 'CPF';
  var siteId = '';

  document.addEventListener('DOMContentLoaded', function () {
    var siteIdEl = document.getElementById('mp-site-id');
    siteId = siteIdEl ? siteIdEl.value.toUpperCase() : '';

    initPaymentMethodToggle();

    if (siteId === 'MLB') {
      initDocumentToggle();
      initDocumentMask();
      initNumberMask();
    }

    if (siteId === 'MLU') {
      initCIMask();
    }

    initFormSubmit();
  });

  /* ── Payment method toggle ── */

  /**
   * Updates the hidden paymentTypeId and paymentOptionId fields when the user
   * selects a payment method. Methods with payment_places (paycash networks)
   * are rendered as inline siblings, each carrying data-payment-option-id.
   */
  function initPaymentMethodToggle() {
    var radios = document.querySelectorAll('input[name="mercadopago_ticket[paymentMethodId]"]');
    var paymentTypeInput = document.getElementById('mp-payment-type-id');
    var paymentOptionInput = document.getElementById('mp-payment-option-id');

    if (!paymentTypeInput || radios.length === 0) {
      return;
    }

    function applyState(radio) {
      paymentTypeInput.value = radio.getAttribute('data-payment-type') || '';
      if (paymentOptionInput) {
        paymentOptionInput.value = radio.getAttribute('data-payment-option-id') || '';
      }
    }

    var hasChecked = false;
    radios.forEach(function (radio) {
      if (radio.checked) {
        hasChecked = true;
        applyState(radio);
      }
      radio.addEventListener('change', function () {
        applyState(this);
      });
    });

    if (!hasChecked) {
      radios[0].checked = true;
      applyState(radios[0]);
    }
  }

  /* ── MLB: CPF/CNPJ toggle ── */

  /**
   * Toggles form fields between CPF (individual) and CNPJ (legal entity) modes.
   */
  function initDocumentToggle() {
    var docTypeRadios = document.querySelectorAll('input[type="radio"][name="mercadopago_ticket[docType]"]');

    // Apply initial state (CPF is checked by default)
    applyDocTypeState('CPF');

    docTypeRadios.forEach(function (radio) {
      radio.addEventListener('change', function () {
        applyDocTypeState(this.value);
      });
    });
  }

  /**
   * Shows/hides fields based on selected document type.
   * @param {string} docType - 'CPF' or 'CNPJ'
   */
  function applyDocTypeState(docType) {
    currentDocType = docType;

    var boxFirstname    = document.getElementById('mp-box-firstname');
    var boxLastname     = document.getElementById('mp-box-lastname');
    var firstnameLabel  = document.getElementById('mp-firstname-label');
    var socialnameLabel = document.getElementById('mp-socialname-label');
    var cpfLabel        = document.getElementById('mp-cpf-label');
    var cnpjLabel       = document.getElementById('mp-cnpj-label');
    var docNumber       = document.getElementById('mp-doc-number');

    if (docType === 'CPF') {
      firstnameLabel.style.display  = 'table-cell';
      socialnameLabel.style.display = 'none';
      cpfLabel.style.display        = 'table-cell';
      cnpjLabel.style.display       = 'none';
      boxLastname.style.display     = 'block';
      boxFirstname.classList.add('col-md-4');
      boxFirstname.classList.remove('col-md-8');
      docNumber.setAttribute('maxlength', '14');
    } else {
      firstnameLabel.style.display  = 'none';
      socialnameLabel.style.display = 'table-cell';
      cpfLabel.style.display        = 'none';
      cnpjLabel.style.display       = 'table-cell';
      boxLastname.style.display     = 'none';
      boxFirstname.classList.add('col-md-8');
      boxFirstname.classList.remove('col-md-4');
      docNumber.setAttribute('maxlength', '18');
    }

    // Clear doc number and error on switch
    docNumber.value = '';
    setFieldError(docNumber, document.getElementById('mp-error-docnumber'), false);
  }

  /* ── Masks ── */

  /**
   * Applies CPF or CNPJ mask on input depending on current document type.
   * Uses maskCpf() and maskCnpjAlphanumeric() from masks.js.
   */
  function initDocumentMask() {
    var docNumber = document.getElementById('mp-doc-number');
    if (!docNumber) return;

    docNumber.addEventListener('input', function () {
      if (currentDocType === 'CPF') {
        this.value = maskCpf(this.value);
      } else {
        this.value = maskCnpjAlphanumeric(this.value);
      }
    });
  }

  /**
   * Applies CI mask for MLU.
   * Uses maskCI() from masks.js.
   */
  function initCIMask() {
    var docNumber = document.getElementById('mp-doc-number');
    if (!docNumber) return;

    docNumber.addEventListener('input', function () {
      this.value = maskCI(this.value);
    });
  }

  /**
   * MLB: ensures the address number field accepts only digits.
   * Uses stripNonDigits() from masks.js.
   */
  function initNumberMask() {
    var numberInput = document.getElementById('mp-number');
    if (!numberInput) return;

    numberInput.addEventListener('input', function () {
      this.value = stripNonDigits(this.value);
    });
  }

  /* ── Form submit ── */

  /**
   * Intercepts the prestashop confirm button click to validate before submitting the
   * ticket form directly — same pattern as card-checkout.js.
   *
   * The ticket form is nested inside the PS checkout form, so the PS confirm
   * button never triggers the inner form's submit event. We must intercept the
   * button click, validate, and call form.submit() ourselves.
   */
  function initFormSubmit() {
    var form = document.getElementById('mp-ticket-checkout');
    if (!form) return;

    var confirmButton = document.querySelector('#payment-confirmation button[type="submit"]');
    if (!confirmButton) return;

    confirmButton.addEventListener('click', function (e) {
      // Only act when the ticket form is visible (payment method selected)
      if (form.offsetParent === null) {
        return;
      }

      var isValid = true;

      if (siteId === 'MLB') {
        isValid = validateMLBInputs() && validateDocumentNumber();
      }

      if (siteId === 'MLU') {
        isValid = validateDocumentNumber();
      }

      if (!isValid) {
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

  /* ── MLB validation ── */

  /**
   * Validates all visible [data-checkout] fields for MLB.
   * @return {boolean}
   */
  function validateMLBInputs() {
    var form = document.getElementById('mp-ticket-checkout');
    var inputs = form.querySelectorAll('[data-checkout]');
    var errors = form.querySelectorAll('.mp-erro-febraban');
    var isValid = true;
    var firstInvalid = null;

    for (var i = 0; i < inputs.length; i++) {
      var input    = inputs[i];
      var errorEl  = errors[i];
      var isHidden = input.offsetParent === null ||
                     window.getComputedStyle(input).display === 'none';

      if (!isHidden && (input.value === '' || input.value === '-1')) {
        setFieldError(input, errorEl, true);
        if (!firstInvalid) firstInvalid = input;
        isValid = false;
      } else {
        setFieldError(input, errorEl, false);
      }
    }

    if (firstInvalid) firstInvalid.focus();
    return isValid;
  }

  /* ── Document number validation ── */

  /**
   * Validates the document number using validateDocument() from document-validator.js.
   * @return {boolean}
   */
  function validateDocumentNumber() {
    var docNumber = document.getElementById('mp-doc-number');
    var errorEl   = document.getElementById('mp-error-docnumber');

    var docType = siteId === 'MLU' ? 'CI' : currentDocType;
    var isValid = validateDocument(docNumber.value, docType);

    setFieldError(docNumber, errorEl, !isValid);

    if (!isValid) {
      docNumber.focus();
    }

    return isValid;
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
   * Unchecks the PrestaShop T&C checkbox when validation fails,
   * preventing the order from being submitted via the confirm button.
   */
  function uncheckedTerms() {
    var terms = document.getElementById('conditions_to_approve[terms-and-conditions]');
    if (terms) {
      terms.checked = false;
    }
  }

})();
