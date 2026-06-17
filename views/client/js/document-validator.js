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
 * This file validates documents according to their country of origin:
 * MLB (Brazil): Alphanumeric CPF and CNPJ
 * MLU (Uruguay): Identity Card (CI)
 * Ensures that only correctly formatted and valid documents are accepted by the system.
 */

/**
 * Validates document based on provided type (CPF, CNPJ or CI)
 * @param {string} docnumber
 * @param {string} docType
 * @returns {boolean}
 */
function validateDocument(docnumber, docType) {
  if (!docnumber || String(docnumber).trim() === '') {
    return false;
  }

  switch (String(docType).toUpperCase()) {
    case 'CPF':
      return validateCpf(docnumber);
    case 'CNPJ':
      return validateCnpj(docnumber);
    case 'CI':
      return validateCI(docnumber);
    default:
  return false;
  }
}

/* --- CPF Validation Functions --- */

/**
 * Validates CPF using standard Brazilian algorithm
 * @param {string} cpfNumber
 * @returns {boolean} 
 */
function validateCpf(cpfNumber) {
  const CPF_LENGTH = 11;
        
  const cleanCPF = stripNonDigits(cpfNumber);
  
  if (!validateDocumentLength(cleanCPF, CPF_LENGTH)) {
    return false;
  }
  
  if (isRepeatedNumber(cleanCPF)) {
    return false;
  }
  
  const firstDigit = calculateCpfCheckDigit(cleanCPF, 9, 11);
  if (firstDigit !== parseInt(cleanCPF.substring(9, 10))) {
    return false;
  }
  
  const secondDigit = calculateCpfCheckDigit(cleanCPF, 10, 12);
  if (secondDigit !== parseInt(cleanCPF.substring(10, 11))) {
    return false;
  }
  
  return true;
}

/**
 * Calculates a single check digit for CPF
 * @param {string} cpfBase - The full CPF string
 * @param {number} length - How many digits to process (9 for first digit, 10 for second)
 * @param {number} initialWeight - The starting weight multiplier (11 or 12)
 * @returns {number}
 */
function calculateCpfCheckDigit(cpfBase, length, initialWeight) {
  let sum = 0;
  for (let i = 1; i <= length; i++) {
    sum += parseInt(cpfBase.substring(i - 1, i)) * (initialWeight - i);
  }
  
  let digit = (sum * 10) % 11;
  return (digit === 10 || digit === 11) ? 0 : digit;
}

/* --- CNPJ Validation Functions --- */

/**
  * Validates both numeric (traditional) and alphanumeric CNPJ
  * @param {string} cnpj
  * @return {boolean}
  */
function validateCnpj(cnpj) {
  const CNPJ_LENGTH = 14;

  cnpj = stripNonAlphaNumeric(cnpj);

  if (!validateDocumentLength(cnpj, CNPJ_LENGTH)) {
    return false;
  }

  if (isNumeric(cnpj)) {
    return validateNumericCnpj(cnpj);
  }
  
  return validateAlphanumericCnpj(cnpj);
}

/**
 * Validates numeric CNPJ
 * @param {string} cnpj
 * @returns {boolean}
 */
function validateNumericCnpj(cnpj) {
  if (isRepeatedNumber(cnpj)) {
    return false;
  }

  const calculateCNPJCheckDigit = (base) => {
    let sum = 0;
    let weight = base.length - 7;
    for (let i = 0; i < base.length; i++) {
      sum += parseInt(base.charAt(i), 10) * weight--;
      if (weight < 2) weight = 9;
    }
    const result = sum % 11;
    return result < 2 ? 0 : 11 - result;
  };

  const base = cnpj.slice(0, 12);
  const digit1 = calculateCNPJCheckDigit(base);
  const digit2 = calculateCNPJCheckDigit(base + digit1);

  if (digit1 !== parseInt(cnpj.charAt(12), 10)) {
    return false;
  }

  if (digit2 !== parseInt(cnpj.charAt(13), 10)) {
    return false;
  }

  return true;
}

/**
 * Validates alphanumeric CNPJ
 * @param {string} cnpj
 * @returns {boolean}
 */
function validateAlphanumericCnpj(cnpj) {
  const firstTwelve = cnpj.slice(0, 12);
  const lastTwo = cnpj.slice(12);

  if (!isAlphanumeric(firstTwelve)) {
    return false;
  }

  if (!isNumeric(lastTwo)) {
    return false;
  }

  const calculateCNPJCheckDigitAlpha = (input) => {
    let sum = 0;
    let weight = 2;
    for (let i = input.length - 1; i >= 0; i--) {
      const val = parseInt(input.charAt(i), 36);
      sum += val * weight;
      weight = weight === 9 ? 2 : weight + 1;
    }
    const result = sum % 11;
    return result < 2 ? 0 : 11 - result;
  };

  const digitAlpha1 = calculateCNPJCheckDigitAlpha(firstTwelve);
  const digitAlpha2 = calculateCNPJCheckDigitAlpha(firstTwelve + digitAlpha1);

  if (digitAlpha1.toString() !== lastTwo.charAt(0)) {
    return false;
  }

  if (digitAlpha2.toString() !== lastTwo.charAt(1)) {
    return false;
  }

  return true;
}

/* --- Uruguay CI Validation Functions --- */

 /**
 * Validates CI (Cédula de Identidad) for MLU (Uruguay)
 * @param {string} ci
 * @return {boolean}
 */
function validateCI(ci) {
  const cleanCI = stripNonDigits(ci);

  if (isRepeatedNumber(cleanCI)) {
    return false;
  }

  const digit = parseInt(cleanCI.slice(-1), 10);

  const base = cleanCI.slice(0, -1).padStart(7, '0');

  if (!isCICalculationValid(base, digit)) {
    return false;
  }

  return true;
}

/**
 * Checks if the CI check digit is mathematically valid
 * @param {string} base - 7-digit numeric string
 * @param {number} expectedDigit - The digit we want to validate
 * @returns {boolean} - True if calculation matches, False otherwise
 */
function isCICalculationValid(base, expectedDigit) {
  const weights = '2987634';
  let sum = 0;

  for (let i = 0; i < 7; i++) {
    sum += (parseInt(base[i], 10) * parseInt(weights[i], 10)) % 10;
  }

  let calculatedDigit = 0;
  
  if (sum % 10 !== 0) {
    calculatedDigit = 10 - (sum % 10);
  }

  if (calculatedDigit !== expectedDigit) {
    return false;
  }

  return true;
}

/* --- Shared Utility Functions --- */

/**
 * Checks if all digits in the string are identical
 * @param {string} value
 * @returns {boolean}
 */
function isRepeatedNumber(value) {
  return /^(\d)\1+$/.test(value);
}

/**
 * Validates document length
 * @param {string} doc
 * @param {number} expectedLength
 * @returns {boolean}
 */
function validateDocumentLength(doc, expectedLength) {
  if (doc.length === expectedLength) {
    return true;
  }
  return false;
}

/**
 * Checks if the value contains only digits
 * @param {string} value
 * @returns {boolean}
 */
function isNumeric(value) {
  return /^\d+$/.test(value);
}

/**
 * Checks if value contains only alphanumeric characters
 * @param {string} value
 * @returns {boolean}
 */
function isAlphanumeric(value) {
  return /^[a-zA-Z0-9]+$/.test(value);
}


