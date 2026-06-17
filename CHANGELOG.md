# Changelog

All notable changes to the Mercado Pago module for PrestaShop are documented in
this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [5.0.0] - 2026-06-17

First release of the Mercado Pago module rebuilt exclusively for **PrestaShop 9**
(PHP 8.1+, Symfony 6, Doctrine 3).

### Added
- PrestaShop 9 support.
- Checkout Pro (redirect and modal) using the Mercado Pago Preferences API.
- Transparent/custom checkouts: credit and debit card, Pix, PSE, Ticket/Boleto and Yape.
- Payment notification (IPN/webhook) handling that creates and updates orders
  from Mercado Pago payment statuses, with dedicated order states per status.
- Per-method configuration: discounts, installments, binary mode and payment expiration.
- Credentials onboarding and Sandbox/Production payment mode.
- Full admin translations in English, Spanish and Portuguese, following the
  PrestaShop back-office language.

### Fixed
- Checkout Pro now creates and updates the order from the Mercado Pago
  notification (IPN), so the order is no longer lost when the buyer does not
  return to the store (e.g. boleto/Pix paid later).
