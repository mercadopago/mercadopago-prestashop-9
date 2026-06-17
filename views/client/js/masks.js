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
 * Masks and Formatters Library
 * 
 * Centralizes all input formatting functions (masks) for:
 * - Documents (CPF, CNPJ, CI)
 * - Card inputs (number, expiration, CVV)
 * - Other form fields
 * 
 * @author MercadoPago Team
 * @version 1.0.0
 */

/**
 * Remove tudo que não é dígito
 * @param {string} value
 * @returns {string} valor limpo
 */
function stripNonDigits(value) {
  return value ? String(value).replace(/[^\d]/g, '') : '';
}

/**
 * Remove caracteres não-alfanuméricos e converte para maiúsculas
 * @param {string} value
 * @returns {string} valor limpo
 */
function stripNonAlphaNumeric(value) {
  return value ? String(value).replace(/[^a-zA-Z0-9]/g, '').toUpperCase() : '';
}

/**
 * Formata CPF no padrão XXX.XXX.XXX-XX
 * Limita a 11 dígitos
 * @param {string} cpf
 * @returns {string} valor formatado
 */
function maskCpf(cpf) {
  cpf = stripNonDigits(cpf).slice(0, 11);
  cpf = cpf.replace(/(\d{3})(\d)/, '$1.$2');
  cpf = cpf.replace(/(\d{3})(\d)/, '$1.$2');
  cpf = cpf.replace(/(\d{3})(\d{1,2})$/, '$1-$2');
  
  return cpf;
}

/**
 * Formata CNPJ no padrão XX.XXX.XXX/XXXX-XX
 * Suporta alfanumérico (normaliza para maiúsculas)
 * @param {string} cnpj
 * @returns {string} cnpj formatado
 */
function maskCnpjAlphanumeric(cnpj) {
  cnpj = stripNonAlphaNumeric(cnpj);
  cnpj = cnpj.replace(/^(.{2})(.{1,3})/, '$1.$2');
  cnpj = cnpj.replace(/^(.{6})(.{1,3})/, '$1.$2');
  cnpj = cnpj.replace(/^(.{10})(.{1,4})/, '$1/$2');
  cnpj = cnpj.replace(/^(.{15})(.{1,2})/, '$1-$2');

  return cnpj;
}

/**
 * Formata CI uruguaio no padrão X.XXX.XXX-X
 * Limita a 8 dígitos
 * @param {string} ci
 * @returns {string} ci formatado
 */
function maskCI(ci) {
  ci = stripNonDigits(ci);
  
  if (ci.length > 8) {
    ci = ci.substring(0, 8);
  }

  ci = ci.replace(/^(\d{1})(\d)/, '$1.$2');
  ci = ci.replace(/^(\d{1})\.(\d{3})(\d)/, '$1.$2.$3');
  ci = ci.replace(/\.(\d{3})(\d)/, '.$1-$2');

  return ci;
}

/**
 * Formata número do cartão no padrão XXXX XXXX XXXX XXXX
 * Limita a 16 dígitos
 * @param {string} value
 * @returns {string} número do cartão formatado
 */
function maskCardNumber(value) {
  var digits = stripNonDigits(value).slice(0, 16);
  return digits.replace(/(\d{4})(?=\d)/g, '$1 ');
}

/**
 * Formata data de expiração no padrão MM/AAAA
 * @param {string} value - Valor bruto digitado
 * @returns {string} - Valor formatado (ex: "12/2030")
 */
function maskExpirationDate(value) {
  var digits = stripNonDigits(value).slice(0, 6);
  
  if (digits.length >= 3) {
    return digits.slice(0, 2) + '/' + digits.slice(2);
  }
  
  return digits;
}

/**
 * Formata CVV - aceita apenas dígitos
 * @param {string} value
 * @returns {string} cvv formatado
 */
function maskCvv(value) {
  return stripNonDigits(value);
}