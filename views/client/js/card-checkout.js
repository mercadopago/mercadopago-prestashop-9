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
  'use strict';

  var seller = {
    site_id: '',
    public_key: '',
  };

  var additionalInfoNeeded = {};
  var mp = null;
  var mpCardForm = null;
  var cvvLength = null;
  var submitted = false;

  let docTypeElement = null;
  let docNumberElement = null;

  /**
   * @param {object} mpCustom
   */
  window.initializeCustom = function (mpCustom) {
    seller.site_id = mpCustom.site_id;
    seller.public_key = mpCustom.public_key;

    loadCardForm();
    setupDocumentFields();
    setChangeEventOnExpirationDate();
    setupCvvField();
    setupCardNumberMask();
    setupExpirationDateField();
    setChangeEventOnCardNumber();
    setupPaymentMethodsToggle();

    if (mpCustom.wallet_button_url) {
      setupWalletButton(mpCustom.wallet_button_url);
    }
  };

  function setupWalletButton(walletButtonUrl) {
    var button = document.getElementById('mp-wallet-button');
    if (!button) {
      return;
    }

    button.addEventListener('click', function (e) {
      e.preventDefault();

      button.disabled = true;

      fetch(walletButtonUrl)
        .then(function (response) { return response.json(); })
        .then(function (data) {
          if (!data.preference || !data.preference.id) {
            button.disabled = false;
            window.location.href = 'index.php?controller=order&step=3&typeReturn=failure';
            return;
          }

          var mpInstance = new MercadoPago(seller.public_key);
          mpInstance.checkout({
            preference: { id: data.preference.id },
            autoOpen: true,
          });
        })
        .catch(function () {
          button.disabled = false;
          window.location.href = 'index.php?controller=order&step=3&typeReturn=failure';
        });
    });
  }

  /**
   * Cria a instância do SDK mercado pago e configura o formulário
   */
  function loadCardForm() {
    mp = new MercadoPago(seller.public_key);

    mpCardForm = mp.cardForm({
      amount: getAmount(),
      autoMount: true,
      processingMode: 'aggregator',
      form: {
        id: 'mp-custom-checkout',
        cardNumber: { id: 'id-card-number' },
        cardholderName: { id: 'id-card-holder-name' },
        cardExpirationMonth: { id: 'id-card-expiration-month' },
        cardExpirationYear: { id: 'id-card-expiration-year' },
        securityCode: { id: 'id-security-code' },
        installments: { id: 'id-installments' },
        identificationType: { id: 'id-docType' },
        identificationNumber: { id: 'id-doc-number-clean' },
        issuer: { id: 'id-issuers-options' },
      },
      callbacks: {
        onFormMounted: function (error) {
          if (error) {
            return console.warn('Form Mounted handling error: ', error);
          }
        },
        onIdentificationTypesReceived: function (error, identificationTypes) {
          if (error) {
            return console.warn('identificationTypes handling error: ', error);
          }
        },
        onPaymentMethodsReceived: function (error, paymentMethods) {
          if (error) {
            return console.warn('paymentMethods handling error: ', error);
          }

          var paymentTypeId = paymentMethods[0].payment_type_id;

          setPaymentTypeId(paymentTypeId);
          setCvvLength(paymentMethods[0].settings[0].security_code.length);
          setImageCard(paymentMethods[0].thumbnail);
          toggleInstallments(paymentTypeId);
          loadAdditionalInfo(paymentMethods[0].additional_info_needed);
          additionalInfoHandler();

        },
        onInstallmentsReceived: function (error, installments) {
          if (error) {
            return console.warn('installments handling error: ', error);
          }
          setChangeEventOnInstallments(seller.site_id, installments.payer_costs);
        },
        onCardTokenReceived: function (error, token) {
          if (error) {
            showErrors(error);
            return console.warn('Token handling error: ', error);
          }

          processTokenAndSubmit(error);
        },
      },
    });
  }

  /**
   * Configura campos de documentos com máscaras, criação de campos ocultos e atualizações em tempo real
   */
  function setupDocumentFields() {
    const elements = initializeDocumentElements();
    if (!elements) return;

    const { docTypeElement, docNumberElement, docNumberHiddenElement } = elements;
    const siteId = getNormalizedSiteId();

    setupDocumentFieldsBySite(siteId, docTypeElement, docNumberElement, docNumberHiddenElement);
  }

  /**
   * Inicializa e valida elementos DOM necessários
   */
  function initializeDocumentElements() {
    docTypeElement = document.getElementById('id-docType');
    docNumberElement = document.getElementById('id-doc-number');

    if (!docTypeElement || !docNumberElement) {
      return null;
    }

    const docNumberHiddenElement = getOrCreateHiddenField(docNumberElement);

    return { docTypeElement, docNumberElement, docNumberHiddenElement };
  }

  /**
   * Cria ou retorna campo oculto existente
   */
  function getOrCreateHiddenField(docNumberElement) {
    let hiddenElement = document.getElementById('id-doc-number-clean');
    
    if (!hiddenElement) {
      hiddenElement = document.createElement('input');
      hiddenElement.type = 'hidden';
      hiddenElement.id = 'id-doc-number-clean';
      docNumberElement.parentNode.appendChild(hiddenElement);
    }

    return hiddenElement;
  }

  /**
   * Configura campos de acordo com o país
   */
  function setupDocumentFieldsBySite(siteId, docTypeElement, docNumberElement, docNumberHiddenElement) {
    switch (siteId) {
      case 'MLB':
        setupBrazilDocuments(docTypeElement, docNumberElement, docNumberHiddenElement);
        break;
      case 'MLU':
        setupUruguayDocuments(docNumberElement, docNumberHiddenElement);
        break;
      default:
        setupDefaultDocuments(docNumberElement, docNumberHiddenElement);
    }
  }

  /**
   * Configuração para documentos brasileiros (CPF/CNPJ)
   */
  function setupBrazilDocuments(docTypeElement, docNumberElement, docNumberHiddenElement) {
    const DOCUMENT_TYPES = {
      CPF: { maxLength: '14', maskFunction: maskCpf },
      CNPJ: { maxLength: '18', maskFunction: maskCnpjAlphanumeric }
    };
  
    setupBrazilInputListener(docTypeElement, docNumberElement, docNumberHiddenElement, DOCUMENT_TYPES);
    setupBrazilTypeChangeListener(docTypeElement, docNumberElement, docNumberHiddenElement, DOCUMENT_TYPES);
  }

  /**
   * Configuração para documentos uruguaios
   */
  function setupUruguayDocuments(docNumberElement, docNumberHiddenElement) {
    docNumberElement.setAttribute('maxlength', '11');
    
    docNumberElement.addEventListener('input', function() {
      const raw = this.value;
      this.value = maskCI(raw);
      docNumberHiddenElement.value = stripNonDigits(this.value);
    });
  }

  /**
   * Configuração padrão para outros países
   */
  function setupDefaultDocuments(docNumberElement, docNumberHiddenElement) {
    docNumberElement.addEventListener('input', function() {
      docNumberHiddenElement.value = stripNonDigits(this.value);
    });
  }

  /**
   * Aplica configuração de tipo de documento
   */
  function applyDocumentConfig(docType, documentTypes, docNumberElement, docNumberHiddenElement) {
    const config = documentTypes[docType];
    if (!config) return;

    docNumberElement.setAttribute('maxlength', config.maxLength);
    docNumberElement.value = '';
    docNumberHiddenElement.value = '';
  }

  /**
   * Configura listener de input para documentos brasileiros
   */
  function setupBrazilInputListener(docTypeElement, docNumberElement, docNumberHiddenElement, DOCUMENT_TYPES) {
    docNumberElement.addEventListener('input', function() {
      const raw = this.value;
      const type = docTypeElement.value;
      const config = DOCUMENT_TYPES[type];

      if (!config) return;

      const maskedValue = config.maskFunction(raw);
      this.value = maskedValue;

      docNumberHiddenElement.value = cleanDocumentNumber(this.value, type);
    });
  }

  /**
   * Configura listener de mudança de tipo para documentos brasileiros
   */
  function setupBrazilTypeChangeListener(docTypeElement, docNumberElement, docNumberHiddenElement, DOCUMENT_TYPES) {
    docTypeElement.addEventListener('change', function() {
      applyDocumentConfig(this.value, DOCUMENT_TYPES, docNumberElement, docNumberHiddenElement);
      clearDocumentErrors();
      docNumberElement.focus();
    });
  }

  /**
   * Processa quais campos adicionais são obrigatórios conforme retorno do SDK
   *
   * @param {Array<string>} sdkAdditionalInfoNeeded
   */
  function loadAdditionalInfo(sdkAdditionalInfoNeeded) {
    additionalInfoNeeded = {
      issuer: false,
      cardholder_name: false,
      cardholder_identification_type: false,
      cardholder_identification_number: false,
    };

    if (!sdkAdditionalInfoNeeded || !sdkAdditionalInfoNeeded.length) {
      return;
    }

    for (var i = 0; i < sdkAdditionalInfoNeeded.length; i++) {
      var item = sdkAdditionalInfoNeeded[i];

      if (item === 'issuer_id') {
        additionalInfoNeeded.issuer = true;
      }
      if (item === 'cardholder_name') {
        additionalInfoNeeded.cardholder_name = true;
      }
      if (item === 'cardholder_identification_type') {
        additionalInfoNeeded.cardholder_identification_type = true;
      }
      if (item === 'cardholder_identification_number') {
        additionalInfoNeeded.cardholder_identification_number = true;
      }
    }
  }

  /**
   * Exibe/oculta campos adicionais com base em additionalInfoNeeded
   */
  function additionalInfoHandler() {
    toggleCardholderName();
    toggleDocumentFields();
    fetchIdentificationTypesIfNeeded();
  }

  /**
   * Mostra/oculta o campo de nome do titular do cartão
   */
  function toggleCardholderName() {
    var holderDiv = document.getElementById('mp-card-holder-div');
    if (holderDiv) {
      toggleElementVisibility(holderDiv, additionalInfoNeeded.cardholder_name);
    }
  }

  /**
   * Mostra/oculta os campos relacionados ao documento
   */
  function toggleDocumentFields() {
    var docTypeDiv = document.getElementById('mp-doc-type-div');
    var docNumberDiv = document.getElementById('mp-doc-number-div');
    var docWrapperDiv = document.getElementById('mp-doc-div');
    var docTitleDiv = document.getElementById('mp-doc-div-title-document');
    
    var showDocType = additionalInfoNeeded.cardholder_identification_type;
    var showDocNumber = additionalInfoNeeded.cardholder_identification_number;
    var showDocSection = showDocType || showDocNumber;
    
    toggleElementVisibility(docTypeDiv, showDocType);
    toggleElementVisibility(docNumberDiv, showDocNumber);
    toggleElementVisibility(docWrapperDiv, showDocSection);
    toggleElementVisibility(docTitleDiv, showDocSection);
  }

  /**
   * Busca os tipos de identificação se necessário
   */
  function fetchIdentificationTypesIfNeeded() {
    if (additionalInfoNeeded.cardholder_identification_type && 
        mp && 
        typeof mp.getIdentificationTypes === 'function') {
      mp.getIdentificationTypes();
    }
  }

  /**
   * Helper: Mostra/oculta um elemento baseado em uma condição
   */
  function toggleElementVisibility(element, shouldShow) {
    if (element) {
      element.classList.toggle('mp-hidden', !shouldShow);
    }
  }

  /**
   * Divide a data em mês e ano para o campo de data de expiração
   */
  function setChangeEventOnExpirationDate() {
    document.getElementById('id-card-expiration').addEventListener('change', function (event) {
      var cardExpirationDate = document.getElementById('id-card-expiration').value.trim();
      
      if (!cardExpirationDate.includes('/')) {
        return;
      }
      
      var dateParts = cardExpirationDate.split('/');
      var cardExpirationMonth = dateParts[0] ? dateParts[0].trim() : '';
      var cardExpirationYear = dateParts[1] ? dateParts[1].trim() : '';
      
      document.getElementById('id-card-expiration-month').value = ('0' + cardExpirationMonth).slice(-2);
      document.getElementById('id-card-expiration-year').value = cardExpirationYear;
    });
  }

  /**
   * Configura máscara para o campo CVV - aceita apenas números
   */
  function setupCvvField() {
    var cvvInput = document.getElementById('id-security-code');
    if (!cvvInput) {
      return;
    }

    cvvInput.addEventListener('input', function() {
      this.value = stripNonDigits(this.value);
    });
  }

  /**
   * Configura máscara para o campo número do cartão:
   * Aceita apenas números
   * Limita a 16 dígitos
   * Formata em grupos de 4 (ex.: 1111 1111 1111 1111)
   */
  function setupCardNumberMask() {
    var cardInput = document.getElementById('id-card-number');
    if (!cardInput || cardInput.dataset.cardMaskAttached) return;
    cardInput.dataset.cardMaskAttached = '1';
  
    cardInput.addEventListener('input', function () {
      this.value = maskCardNumber(this.value);
    });
  }

  /**
   * Configura máscara para o campo de data de expiração
   * Formata automaticamente como MM/AAAA durante a digitação
   */
  function setupExpirationDateField() {
    var expirationInput = document.getElementById('id-card-expiration');
    if (!expirationInput || expirationInput.dataset.expirationMaskAttached) {
      return;
    }
    expirationInput.dataset.expirationMaskAttached = '1';

    expirationInput.addEventListener('input', function () {
      this.value = maskExpirationDate(this.value);
    });
  }

  /**
   * Configura evento de mudança no campo de número do cartão
   */
  function setChangeEventOnCardNumber() {
    var cardNumberInput = document.getElementById('id-card-number');
    if (!cardNumberInput || cardNumberInput.dataset.cardNumberChangeEventAttached) {
      return;
    }
    cardNumberInput.dataset.cardNumberChangeEventAttached = '1';
  
    cardNumberInput.addEventListener('keyup', function (e) {
      var digits = stripNonDigits(e.target.value);
      if (digits.length <= 4) {
        clearInputs();
      }
    });
  }

  /**
   * Valida e limpa um valor de taxa extraído de um label
   * @param {string} value - O valor bruto a ser limpo
   * @returns {string|null} - Valor limpo ou null se inválido
   */
  function validateAndCleanTaxValue(value) {
    if (!value) return null;
    
    const cleaned = value.replace('%', '').trim();
    const numberPattern = /^\d+([,.]\d+)?$/;
    
    return numberPattern.test(cleaned) ? cleaned : null;
  }

  /**
   * Extrai um tipo específico de taxa de uma string
   * @param {string} taxString - A string de taxa a ser processada
   * @param {string} taxType - O tipo de taxa a extrair (ex: 'CFT_', 'TEA_', 'TNA_')
   * @returns {string|null} - Valor da taxa extraído ou null
   */
  function extractTaxValue(taxString, taxType) {
    if (!taxString.includes(taxType)) return null;
    
    const splitResult = taxString.split(taxType);
    if (splitResult.length <= 1 || !splitResult[1]) return null;
    
    return validateAndCleanTaxValue(splitResult[1]);
  }

  /**
   * Processa todas as informações de taxas dos labels
   * @param {Array} labels - Array de strings de labels
   * @returns {Object} - Objeto com valores de cft, tna e tea
   */
  function parseTaxesFromLabels(labels) {
    const taxInfo = {
      cft: '0,00',
      tna: '0,00',
      tea: '0,00'
    };
    
    if (!labels || !Array.isArray(labels)) return taxInfo;
    
    labels.forEach(label => {
      if (typeof label !== 'string') return;
      
      const taxesSplit = label.split('|');
      
      taxesSplit.forEach(tax => {
        const cftValue = extractTaxValue(tax, 'CFT_');
        if (cftValue) {
          taxInfo.cft = cftValue;
          return;
        }
        
        const teaValue = extractTaxValue(tax, 'TEA_');
        if (teaValue) {
          taxInfo.tea = teaValue;
          return;
        }
        
        const tnaValue = extractTaxValue(tax, 'TNA_');
        if (tnaValue) {
          taxInfo.tna = tnaValue;
        }
      });
    });
    
    return taxInfo;
  }

  /**
   * Exibe as taxas governamentais na Argentina (MLA)
   * @param {Array} payerCosts - Array de objetos com custos de parcelas
   */
  function showTaxes(payerCosts) {
    var taxEl = document.querySelector('#mp-mla-tax-text');
    if (!taxEl) return;

    taxEl.innerHTML = '';

    var installmentsSelect = document.querySelector('#id-installments');
    if (!installmentsSelect || !payerCosts || !payerCosts.length) {
      return;
    }

    for (var i = 0; i < payerCosts.length; i++) {
      if (
        installmentsSelect.value != '1' &&
        String(payerCosts[i].installments) === String(installmentsSelect.value)
      ) {
        const taxInfo = parseTaxesFromLabels(payerCosts[i].labels);
        
        var taxText = `<b>CFTEA: ${taxInfo.cft}%</b> - TEA: ${taxInfo.tea}%. Tasa fija.`;
        
        taxEl.innerHTML = taxText;
      }
    }
  }

  /**
   * Configura o evento de clique no botão de mostrar métodos de pagamento
   */
  function setupPaymentMethodsToggle() {
    var showPayments = document.querySelector('#button-show-payments');
    var framePayments = document.querySelector('#mp-frame-payments');

    if (showPayments && framePayments) {
      showPayments.addEventListener('click', function () {
        framePayments.classList.toggle('mp-hidden');
      });
    }
  }

  /**
   * Obtém valor da compra para o checkout
   */
  function getAmount() {
    var amountEl = document.getElementById('amount');
    return amountEl ? amountEl.value : '0';
  }

  /**
   * Normaliza o siteId com fallback consistente
   *
   * @param {string} fallbackSiteId
   * @return {string}
   */
  function getNormalizedSiteId(fallbackSiteId) {
    var raw =
      fallbackSiteId ||
      seller.site_id ||
      (document.getElementById('mp-site-id') ? document.getElementById('mp-site-id').value : '') ||
      'MLB';

    return String(raw).toUpperCase();
  }

  /**
   * Obtém formulário do checkout 
   */
  function getFormCustom() {
    return document.querySelector('#mp-custom-checkout');
  }

  /**
   * Previne múltiplos envios
   */
  function setFormSubmit() {
    submitted = true;
  }

  /**
   * Defina o ID do tipo de pagamento
   *
   * @param {string} paymentTypeId
   */
  function setPaymentTypeId(paymentTypeId) {
    document.querySelector('#payment_type_id').value = paymentTypeId;
  }

  /**
   * Define o tamanho do CVV
   * Atualiza o maxlength do campo HTML dinamicamente com o tamanho do CVV definido pelo Mercado Pago
   *
   * @param {number} length
   */
  function setCvvLength(length) {
    cvvLength = length;

    var cvvInput = document.getElementById('id-security-code');
    if (cvvInput && length) {
      cvvInput.setAttribute('maxlength', length);
    }
  }

  /**
   * Define a imagem do cartão no elemento
   *
   * @param string - URL da imagem da bandeira do cartão
   */
  function setImageCard(secureThumbnail) {
    var mpCardNumber = document.getElementById('id-card-number');
    mpCardNumber.style.background = 'url(' + secureThumbnail + ') 98% 50% no-repeat #fff';
    mpCardNumber.style.backgroundSize = 'auto 24px';
  }

    /**
   * Ativa ou desativa o seletor de parcelas baseado no tipo de pagamento.
   * @param {string} paymentTypeId - Tipo de pagamento selecionado (ex: 'credit_card', 'debit_card')
   */
  function toggleInstallments(paymentTypeId) {
    const installmentsEl = document.getElementById('id-installments');
    if (!installmentsEl) return;

    if (paymentTypeId === 'debit_card') {
      installmentsEl.disabled = true;
    } else {
      installmentsEl.disabled = false;
    }
  }

    /**
   * Show governmental taxes in MLA
   *
   * @params any payer_costs
   */
  function setChangeEventOnInstallments(siteId, payer_costs) {
    var normalizedSiteId = getNormalizedSiteId(siteId);

    if (normalizedSiteId === 'MLA') {
      clearTax();

      var installmentsEl = document.getElementById('id-installments');
      if (!installmentsEl) return;

      installmentsEl.addEventListener('change', function () {
        showTaxes(payer_costs);
      });
    }
  }
  /**
   * Desativa botão de finalizar pedido
   */
  function disableFinishOrderButton() {
    var finishOrderButton = document.querySelector('#payment-confirmation button[type="submit"]');
    if (finishOrderButton) {
      finishOrderButton.setAttribute('disabled', 'disabled');
      finishOrderButton.classList.add('disabled');
    }
  }

  /**
   * Obtenha os termos de condição
   */
  function getConditionTerms() {
    var terms = document.getElementById('conditions_to_approve[terms-and-conditions]');
    if (typeof terms === 'object' && terms !== null) {
      terms.checked = false;
      return terms.checked;
    }
  }

  /**
   * Valida campos antes de gerar token
   */
  function validateInputs() {
    hideErrors();

    var fixedInputs = validateFixedInputs();
    var additionalInputs = validateAdditionalInputs();

    if (fixedInputs || additionalInputs) {
      focusInputError();
      return false;
    }

    return true;
  }

  /**
   * Valida campos fixos obrigatórios para o checkout
   *
   * @return bool
   */
  function validateFixedInputs() {
    var emptyInputs = false;
    var form = getFormCustom();
    if (!form) return false;

    var formInputs = form.querySelectorAll('[data-checkout]');
    var fixedInputs = ['cardNumber', 'cardholderName', 'cardExpiration', 'securityCode', 'installments'];

    for (var x = 0; x < formInputs.length; x++) {
      var element = formInputs[x];

      if (fixedInputs.indexOf(element.getAttribute('data-checkout')) > -1) {
        if (element.offsetParent === null) {
          continue;
        }

        if (element.value === -1 || element.value === '') {
          var span = form.querySelectorAll('small[data-main="#' + element.id + '"]');

          if (span.length > 0) {
            span[0].style.display = 'block';
          }

          element.classList.add('mp-form-control-error');
          emptyInputs = true;
        }
      }
    }

    return emptyInputs;
  }

  /**
   * Valida campos adicionais do formulário de pagamento
   */
  function validateAdditionalInputs() {
    var hasError = false;
    var error324El = document.getElementById('mp-error-324');
    if (additionalInfoNeeded.cardholder_name) {
      hasError = validateField('id-card-holder-name') || hasError;
    }

    if (additionalInfoNeeded.cardholder_identification_type) {
      hasError = validateField('id-docType') || hasError;
    }

    if (additionalInfoNeeded.cardholder_identification_number) {
      hasError = validateDocumentNumber(error324El) || hasError;
    }

    return hasError;
  }

  /**
   * Valida se um campo está vazio e aplica estilo de erro
   * @param {string} fieldId - ID do elemento a validar
   * @returns {boolean} true se houver erro
   */
  function validateField(fieldId) {
    var field = document.getElementById(fieldId);
    
    if (field && (field.value === -1 || field.value === '')) {
      field.classList.add('mp-form-control-error');
      return true;
    }
    
    return false;
  }

  /**
   * Valida o número do documento com regras específicas por país
   * @param {HTMLElement} errorElement - Elemento de erro 324
   * @returns {boolean} true se houver erro
   */
  function validateDocumentNumber(errorElement) {
    var docNumber = document.getElementById('id-doc-number');
    
    if (!docNumber) {
      return false;
    }

    if (docNumber.value === -1 || docNumber.value === '') {
      docNumber.classList.add('mp-form-control-error');
      showDocumentError(errorElement);
      return true;
    }

    var siteId = (seller.site_id || (document.getElementById('mp-site-id') ? document.getElementById('mp-site-id').value : '')).toUpperCase();
    
    if (siteId === 'MLB') {
      var docType = document.getElementById('id-docType');

      if (docType && typeof validateDocument === 'function') {
        var isValid = validateDocument(docNumber.value, docType.value);

        if (isValid === false) {
          docNumber.classList.add('mp-form-control-error');
          showDocumentError(errorElement);
          return true;
        }
      }
    }

    if (siteId === 'MLU' && typeof validateDocument === 'function') {
      var isValidCI = validateDocument(docNumber.value, 'CI');

      if (isValidCI === false) {
        docNumber.classList.add('mp-form-control-error');
        showDocumentError(errorElement);
        return true;
      }
    }

    return false;
  }

  /**
   * Exibe mensagem de erro de documento inválido
   * @param {HTMLElement} errorElement - Elemento de erro a exibir
   */
  function showDocumentError(errorElement) {
    if (errorElement) {
      errorElement.style.display = 'inline-block';
    }
  }

  /**
   * Valida o comprimento do CVV
   *
   * @returns {boolean}
   */
  function validateCvvLength() {
    if (cvvLength === null || cvvLength === undefined) {
      return false;
    }

    var form = getFormCustom();
    if (!form) {
      return false;
    }

    var cvvInput = document.getElementById('id-security-code');
    if (!cvvInput) {
      return false;
    }

    var cvvValidation = cvvLength === cvvInput.value.length;

    if (!cvvValidation) {
      var span = form.querySelectorAll('small[data-main="#id-security-code"]');
      if (span.length > 0) {
        span[0].style.display = 'block';
      }
      cvvInput.classList.add('mp-form-control-error');
      cvvInput.focus();
      getConditionTerms();
    }

    return cvvValidation;
  }

  /**
   * Mostra erros que ocorrem no checkout, que vem da sdk js do mercadopago
   *
   * @param  {object}  error
   */
  function showErrors(error) {
    var form = getFormCustom();
    if (!form) return;

    var serializedError = error.cause || error;

    for (var x = 0; x < serializedError.length; x++) {
      var code = serializedError[x].code;
      var span = undefined;

      if (code === '208' || code === '209' || code === '325' || code === '326') {
        span = form.querySelector('#mp-error-208');
      } else {
        span = form.querySelector('#mp-error-' + code);
      }

      if (span !== undefined) {
        span.style.display = 'block';
        var mainElement = form.querySelector(span.getAttribute('data-main'));
        if (mainElement) {
          mainElement.classList.add('mp-form-control-error');
        }
      }
    }

    focusInputError();
  }

  /**
   * Limpa erros
   */
  function hideErrors() {
    var fields = document.querySelectorAll('[data-checkout]');
    for (var x = 0; x < fields.length; x++) {
      fields[x].classList.remove('mp-form-control-error');
    }

    var errorMessages = document.querySelectorAll('.mp-erro-form');
    for (var y = 0; y < errorMessages.length; y++) {
      errorMessages[y].style.display = 'none';
    }
  }

  /**
   * Limpa campos relacionados ao cartão (exceto o número do cartão)
   */
  function clearInputs() {
    hideErrors();

    var cardNumber = document.getElementById('id-card-number');
    if (cardNumber) {
      cardNumber.style.background = '#fff';
    }

    [
      'id-card-expiration',
      'id-card-expiration-month',
      'id-card-expiration-year',
      'id-security-code',
      'id-card-holder-name',
      'id-doc-number',
      'id-doc-number-clean',
    ].forEach(function (id) {
      var el = document.getElementById(id);
      if (el) {
        el.value = '';
      }
    });
  }

  /**
   * Foca no primeiro erro
   */
  function focusInputError() {
    var formInputs = document.querySelectorAll('.mp-form-control-error');
    if (formInputs.length > 0) {
      formInputs[0].focus();
    }
  }

  /**
   * Limpa mensagens de erro e redefine as classes de validação
   */
  function clearDocumentErrors() {
    if (docNumberElement) {
      docNumberElement.classList.remove('mp-form-control-error');
    }
    var error324 = document.getElementById('mp-error-324');
    if (error324) {
      error324.style.display = 'none';
    }
  }

  /**
   * Número de documento limpo com base no tipo de documento
   * @param {string} docNumber
   * @param {string} docType
   * @return {string}
   */
  function cleanDocumentNumber(docNumber, docType) {
    if (!docNumber) return '';
    
    if (docType === 'CNPJ') {
      return stripNonAlphaNumeric(docNumber);
    }

    return stripNonDigits(docNumber);
  }

   /**
   * Clear Tax
   */
  function clearTax() {
    document.querySelector('.mp-text-mla-tax').innerHTML = '';
  }

  /**
   * Processa a resposta do SDK do MercadoPago após a geração do token do cartão
   *
   * @param number error
   */
  function processTokenAndSubmit(error) {
    if (!validateCvvLength()) {
      return;
    }

    if (error) {
      showErrors(error);
      return;
    }

    if (submitted) {
      return;
    }

    var formData = mpCardForm.getCardFormData();

    var docType = formData.identificationType || 
    (document.getElementById('id-docType') ? document.getElementById('id-docType').value : 'CPF');

    var cleanedDocNumber = cleanDocumentNumber(formData.identificationNumber, docType);
    formData.identificationNumber = cleanedDocNumber;

    var docNumberHiddenElement = document.getElementById('id-doc-number-clean');
    if (docNumberHiddenElement) {
      docNumberHiddenElement.value = cleanedDocNumber;
    }

    document.querySelector('#card_token_id').value = formData.token;
    document.querySelector('#mp_issuer').value = formData.issuerId;
    document.querySelector('#mp_installments').value = formData.installments;
    document.querySelector('#payment_method_id').value = formData.paymentMethodId;
    document.querySelector('#doc_type').value = formData.identificationType;
    document.querySelector('#doc_number').value = cleanedDocNumber;

    setFormSubmit();
    disableFinishOrderButton();
    getFormCustom().submit();
  }

  /**
   * Lidar com envio do formulário
   */
  jQuery(function () {
    var form = getFormCustom();
    
    if (form !== null && form !== undefined) {
      var confirmButton = document.querySelector('#payment-confirmation button[type="submit"]');
      
      if (confirmButton) {
        confirmButton.addEventListener('click', function(e) {
          var cardForm = getFormCustom();
          if (!cardForm || cardForm.offsetParent === null) {
            return;
          }

          e.preventDefault();
          e.stopPropagation();

          if (submitted) {
             getFormCustom().submit();
             return true;
          }

          if (validateInputs()) {
            mpCardForm.createCardToken();
            return false;
          }

          getConditionTerms();
          return false;
        });
      }
    }
  });

})();
