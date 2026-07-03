# Changelog

All notable changes to the Mercado Pago module for PrestaShop are documented in
this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [5.0.1] - 2026-07-01

### Fixed
- Isolated the module's bundled dependencies (Doctrine, Symfony, Monolog, ...)
  with PHP-Scoper, prefixing their namespaces with `MercadoPagoVendor\`. This
  prevents autoloader collisions and fatal "Declaration ... must be compatible"
  errors when PrestaShop 9 loads different versions of the same libraries in its
  core.
- Fixed a fatal "Undefined constant MP_VERSION" caused by PHP-Scoper prefixing
  the module's version constant while leaving its usages unprefixed; kept
  `MP_VERSION` global and also bumped the version shown in the PrestaShop admin
  panel, which was not being updated.
- Fixed the admin configuration screen rendering blank/unstyled sections: Twig
  failed to initialize in the packaged build because it was instantiated
  through a dynamic class-name variable that PHP-Scoper cannot rewrite.
- Fixed Twig and PrestaShop core classes (`PaymentOption`) being incorrectly
  prefixed by PHP-Scoper, since they are provided by the PrestaShop 9 core at
  runtime and are not bundled by the module; this caused fatal "Class not
  found" errors when opening the checkout or building payment options.
- Fixed module uninstall failing silently ("Could not perform action uninstall
  for module undefined") caused by a `TypeError` when reusing PrestaShop's own
  Doctrine connection object, which belongs to an incompatible (unprefixed)
  class hierarchy; the module now always builds its own isolated connection.
- Fixed a fatal "Class AbstractMercadopagoModuleFrontController not found" on
  Checkout Pro, Ticket, Pix, PSE, Yape and the wallet button: the shared base
  controller is now loaded via an explicit `require_once` instead of relying
  on Composer's classmap autoload, which cannot resolve it once PHP-Scoper
  wraps the file.
- Fixed order-status email templates being reported as missing (e.g.
  "pending"): the release build was deleting the module's own plain-text
  (`.txt`) email templates together with unrelated documentation files.

### Changed
- Pinned PHP-Scoper to a fixed version with SHA256 verification in the release
  build, making the packaged artifact reproducible and guarding against a
  compromised scoper release.
- Hardened `bin/create-release-zip.sh`: the original build directory is only
  replaced after the scoped output is confirmed, avoiding an inconsistent build
  state.
- Hardened migration error handling in the installation service to catch
  `\Throwable` instead of only `\Exception`, so a broken class does not abort
  install/uninstall with an unhandled fatal.
- Added mandatory WebSec and DataSec pre-commit hooks (hardcoded credential and
  PII checks).
- Added public-repo snapshot tooling for building a sanitized public mirror.
- Removed the `publish-release.yml` and `release-zip-validator.yml` CI
  workflows: both referenced tooling that no longer exists in this branch line
  (`bin/setup-release.sh`, `package.json`) or provided no real validation
  beyond a build smoke test. The release/publish pipeline will be rebuilt on
  top of the current tooling in a future version.

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
