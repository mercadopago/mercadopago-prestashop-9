# Changelog


All notable changes to the Mercado Pago module for PrestaShop are documented in
this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [5.0.6] - 2026-11-19

### Added
- Repository release skill for the PrestaShop 9 module, adapted from the legacy workflow with scoped packaging, native upgrade evidence and Marketplace/publication gates. (PPCO-5889)

### Fixed
- Checkout Pro order creation now checks the configured approved order state and the module's active status before marking the attempt `uncertain_order`; these configuration errors return a retryable 503 and the cart recovers on the next notification after the fix. The Checkout Pro return banner shows the buyer only a generic message; the configuration detail is logged. The same precheck refuses order creation when `orders.reference` or `order_payment.order_reference` cannot hold the 27-character attempt reference: shops migrated to PrestaShop 9 without the 9.0.0 upgrade script keep `VARCHAR(9)`, and PrestaShop's empty `sql_mode` would truncate the reference silently after hooks and e-mails ran. Installation and the 5.0.6 upgrade now widen both columns to `VARCHAR(255)` with the PrestaShop 9.0.0 statement when they are narrower; if that is not possible (for example without `ALTER` privilege) the module configuration page shows a warning with the exact SQL for the narrow columns only (a column already wider than `VARCHAR(255)` is never shrunk) until both widths are read and confirmed; a failed width lookup keeps the warning. Installation never fails because of these core columns. Any order-creation failure is logged with its cause after the cart transaction rolls back (PrestaShop's own `validateOrder()` log is discarded with it), including the stored versus expected order-reference length when the final link check fails. Database error details, e-mail addresses and payment activity identifiers (cart, transaction, payment, merchant order and order ids) are withheld from these logs. (PPSP-2465)
- Checkout Pro and Wallet Button no longer send a buyer whose payment was approved back to the payment step when the order is not created yet. The return now opens a "payment received" page in the theme layout (or the native order confirmation once the notification creates the order), and a new preference for the same cart is refused while an approved payment awaits its order (48-hour window counted from the latest webhook reservation or uncertain marker), preventing a second charge. (PPSP-2465)

## [5.0.5] - 2026-11-16

### Fixed
- Preserve order states, payment tables, history and configuration on uninstall. Reinstallation validates configured states and reuses active Mercado Pago states before creating replacements. Explicit state cleanup never deletes another module's states. (PPSP-2164)
- Repair missing transaction tables and additive payment fields during installation and upgrade, preserving existing records and reporting schema or migration failures.

### Security
- Require Symfony HttpClient 6.4.50 and update the bundled dependency from 6.4.42 to fix CVE-2026-121971 and CVE-2026-123119.

## [5.0.4] - 2026-10-28

### Fixed
- Map native ENUM columns on the isolated Doctrine migration connection so real-shop schema introspection succeeds during upgrade.
- Run pending Doctrine migrations through the native 5.0.4 upgrade hook; preserve fresh order lookups across preference materialization and align scoped migration formatting with Marketplace checks.
- Align the preference guard exception formatting with Marketplace coding standards.
- Checkout Pro and Wallet Button now persist an immutable snapshot of the exact preference items, cart composition, shop, currency and discount conditions. A return or notification creates/links an order only after canonical Mercado Pago payment and merchant-order identifiers, amount and the locked current cart match that snapshot; mismatches are held for reconciliation. (PPSP-2323)
- Checkout Pro notification and return lookups now retain preference guard state through SQL projections, preventing pending webhooks from creating an order through the legacy path before approval. (PPSP-2323)
- Preference snapshots preserve six-decimal outbound unit prices and compare currency-rounded totals after applying quantity, avoiding false mismatches for fractional prices. (PPSP-2323)

### Changed
- Pre-commit now checks Marketplace Standards on source and the scoped ZIP, cached-core PHPStan, native upgrade contracts and gate regressions. Release mode requires real-database upgrade receipts for the exact ZIP.

## [5.0.3] - 2026-10-22

### Fixed
- Register checkout JavaScript with module-relative paths so PrestaShop resolves scripts in both root and subdirectory stores. This restores card form initialization, masks and installments when the shop URL contains a path prefix. (PPCO-5698)

## [5.0.2] - 2026-10-21

### Fixed
- Corrige o prefixo do `ClientExceptionInterface` no `catch` do `MPRestCli` empacotado (erros 4xx deixavam de retornar status/response); o build agora falha se houver referências a vendor sem prefixo no código do módulo.
- Release ZIP now preserves PrestaShop's global module and front-controller entrypoints and root `.htaccess` after dependency scoping, and guards the two new PHP directories against direct browsing.
- Marketplace packaging now preserves first-party source formatting, generates missing directory guards, places static assets under `views/{css,js,img}`, and produces a deterministic ZIP. Local package and PrestaShop coding-standard checks are available as optional Composer commands.
- First-party PHP file headers now use the spacing accepted by the Marketplace Standards check, while preserving the required license comment as the first file comment.
- Module services now receive PrestaShop's request context from the module or front controller instead of calling the legacy Context singleton directly.
- Marketplace compatibility cleanup now uses PrestaShop's complete product-image URL, rejects unavailable or malformed seller responses without a fatal error, narrows payment/order collections to their actual types, and keeps the prior failure when Cart Rule cleanup also fails. Outbound HTTP now has a 30-second timeout and does not follow redirects.
- PrestaShop 9 compatibility checks now pass locally on 9.0.3, 9.1.4 and 9.2.0; the remaining static findings were resolved with explicit module/order-payment guards, precise types and removal of unreachable branches while retaining retry and order-ownership behavior.
- Pre-release gate: `composer pre-release` (`bin/pre-release.sh`) checks version/CHANGELOG consistency, Composer validate/audit, phpcs, Marketplace standards, unit tests, the release ZIP and package structure, and runs the Marketplace PHPStan rules against PrestaShop 9.0.3, 9.1.5 and 9.2.0 cores extracted from the official Docker images (`bin/fetch-prestashop-core.sh`). The unit suite runs on PHP 8.1 to 8.5 (local binaries or the official `php:<version>-cli` image). `composer lint` now reports zero errors, broken `stan`/`quality`/`dev-check`/`validate-di` scripts were fixed or removed, and the unit suite exits cleanly on PHP 8.2, 8.4 and 8.5 (declared `CartRule::$id_customer`, explicit nullable parameters in test doubles, no `setAccessible()`). `CredentialsService` no longer calls `curl_close()`, which has had no effect since PHP 8.0 and is deprecated in PHP 8.5.
- Outbound HTTP clients, the migration database connection and the Doctrine Migrations factory are now created without static factory calls on bundled (scoped) dependencies, which the Marketplace Validator reported as missing methods. Transport choice, 30-second timeout, disabled redirects and the `pdo_mysql` connection parameters are unchanged.
- The payment-options hook now checks that all returned options are native PrestaShop `PaymentOption` instances. Four first-party PHP files now carry the Marketplace-required license tags in order.
- Checkout Pro merchant-order notifications now confirm every retained payment individually before the cart lock, validating canonical id, cart reference, status and amount. Stale snapshots and transient Payment API failures return retryable HTTP 503 without local mutations, including zero-aggregate fallback; classic payloads, coupon/paid-amount semantics, same-state repair and numeric-id rotation remain supported. Status-aware totals exclude processing attempts from approved funds, use captured/materialized totals and tolerate decimal representation error. Confirmed approved payments alone create receipts; legacy automatic false receipts are corrected without deleting audit rows, while manual/refund history is preserved. Invoice-payment associations are durable and idempotent, including retries and split contributions. (PPSP-2184)
- Checkout Pro `merchant_order` processing now filters the remote payment collection before computing its aggregate or synchronizing local `OrderPayment` rows: entries must be arrays, have an allowlisted payment identifier and be unique by that identifier. Malformed or duplicate entries therefore cannot alter the local financial collection. (PPSP-2074)
- Checkout Pro `merchant_order` processing now keeps the financial synchronization of the local PrestaShop payment collection inside the same cart-scoped critical section that protects webhook state mutation and deferred buyer-return order creation. The validated remote merchant-order snapshot is still fetched before lock acquisition; only the local state/order-payment mutation is serialized, then the lock is released in `finally`. Approved classic merchant-order entries that omit `transaction_details.total_paid_amount` now use their documented `transaction_amount`, so they remain on that synchronized path instead of falling back to a payment-only update. A partial older multi-payment snapshot no longer deletes local payments it does not contain; synchronization updates known rows, assigns unbound rows and adds only new payment identifiers. Both false returns and thrown local synchronization failures are retryable while preserving the original cause. This closes the duplicate payment/invoice window when callbacks overlap the return flow and completes the canonical local payment-id binding. (PPSP-2074)
- Payment-method discount retries now reconcile the Mercado Pago Cart Rule when a cart moves through `10% → 5% → 0%`. New rules use an HMAC-derived code, are owned by the cart customer and expire after 30 minutes; legacy `MPDISCOUNT{cartId}` rows remain recognized only for cleanup. Card Form quotes `0%` through the read-only projection so a residual rule cannot lower the displayed amount. Card, Ticket, Pix, PSE and Yape capture one consistent `items`, `transaction_amount` and `payments[].amount` snapshot under the cart-scoped lock, explicitly verify the item sum, and detach the temporary rule in `finally` before calling the Order API outside that lock. A successful response must echo a valid canonical amount; idempotent replay preserves the attempt's original `total`/`payment_amount` and durable state fields. After persistence, an exact-attempt reservation prevents the webhook from rebuilding a discounted order from the neutral cart. A negative notification without an order remains terminal, but a later approved payment proven to belong to the same `merchant_order` keeps its positive status and asks for retry so the exact checkout can resume instead of becoming permanently poisoned. Before the repeatable-read order transaction starts, a durable `uncertain-order:<attempt>` guard closes process-death/OOM windows around `validateOrder()` hooks and e-mails; caught pre-order failures restore `pending:<attempt>`, while any failure after order creation begins remains manual. The transaction locks the cart's mutable financial rows through rule reapplication, payload validation, `validateOrder()`, primary-connection materialized-order amount verification, exact linking and cleanup. Proven pre-order financial divergence becomes `invalid-cart:<attempt>` after rollback. Processed webhooks compare the paid amount with the primary materialized split-order total rather than the now-neutral cart, avoiding false fraud states on legitimate discounts. Legacy duplicate rows are detached and disabled for positive and zero percentages, and independent store vouchers are preserved.
- Chilean and Colombian Card and Ticket orders now recognize the lowercase country codes persisted by the localization settings and use one shared zero-decimal amount normalizer across Card Form and Order API payload creation, preventing the browser SDK, `transaction_amount`, and `payments[].amount` from applying different CLP/COP rounding. When Card is rendered with a configured discount, its displayed amount is calculated by PrestaShop's real cart calculator with a synthetic `CartRule` kept only in memory: rendering neither saves nor associates the rule, replaces any residual Mercado Pago rule in the calculator input, reads cart rules with automatic rule insertion disabled, resets the cart static cache before and after the preview, and restores the temporary cart-rule feature flag afterwards. The synthetic rule and the persistent rule applied on POST share `PaymentMethodDiscountRule`, including the DB-safe lowest execution priority `4294967295`. The Card POST remains authoritative: it serializes primary-database lookup, save and association with the existing cart-wide lock, idempotently reuses and reassociates the persisted rule when needed, and fails closed if the lock, save or association cannot complete before rebuilding items and totals. Card and Ticket convert the persisted percentage to the typed PrestaShop value and use a positive or negative difference item to keep `sum(unit_price × quantity)` equal to the discounted transaction and payment totals.
- Orders left in native `PS_OS_PREPARATION` by checkout's missing `MERCADOPAGO_STATUS_7` fallback can now receive later Mercado Pago status updates, but only after the exact `id_mp_transaction` + `cart_id` + `merchant_order_id` ownership tuple is proven. The `pp_order` webhook now requires the active cart-customer secure key, validates local ownership before calling the Payment API, reads the canonical payment id only from `payments[].references.payment_id`, acknowledges payment-id-less `processing` updates, classifies transient failures as retryable, converges split-order retries and serializes mutation with a cart-scoped database lock. Post-lock payment ownership also prevents a late legacy callback from replacing the canonical payment id of a newer checkout attempt for the same cart. (PPSP-1604)
- Generate the Mercado Pago `notification_url` via PrestaShop's `Link::getModuleLink()` component instead of manually concatenating `__PS_BASE_URI__` with a `?fc=module...` query string, so the callback URL correctly reflects the shop's configured SSL, proxy, and subdirectory setup. (PPSP-1602)
- `AbstractOrderService::getSiteUrl()` had a dead ternary (`'https://' ? 'https://' : 'http://'`) whose condition was always truthy, so it always returned `https://` regardless of the shop's actual SSL configuration. Fixed by resolving the scheme via `Configuration::get('PS_SSL_ENABLED') || Tools::usingSecureMode()`, matching PrestaShop core's own `Tools::getShopProtocol()` (verified against PrestaShop 8.2.7 and 9.0.3 source) — `PS_SSL_ENABLED` alone would still return `http://` for a request that is actually secure (e.g. SSL terminated by a proxy/CDN) but where that toggle isn't set. Standard Checkout Pro and Wallet Button `back_urls` now reuse that same scheme/domain resolution instead of forcing HTTPS, preserving HTTP-only stores without weakening proxy detection. Configured domains reused by callbacks, redirects and onboarding metadata share one strict validator that accepts only a hostname with an optional numeric port and rejects schemes, user-info, paths, queries and fragments. (PPSP-1603)
- Existing orders preserve the configured Mercado Pago negative states (`MERCADOPAGO_STATUS_3` for `failed`, `MERCADOPAGO_STATUS_2` for `canceled`/`expired`) rather than substituting native PrestaShop states. When no order exists, those negative notifications are acknowledged for idempotent delivery without creating an order or emitting an initial order-state email, and their terminal disposition remains durable. Replayed Order API responses now refresh only non-state metadata, preserving `payment_status`, `received_webhook`, `merchant_order_id` and the canonical `payment_id`; this keeps the proof required to recover from payment A rejected to payment B approved in the same `merchant_order`. A proven same-merchant payment-id rotation now compare-and-sets the new id and status in one SQL transition and restores both with an inverse CAS if the PrestaShop order mutation fails, so a retry cannot be stranded in a split `failed/new-payment-id` state. The exact transaction is then re-read under the cart lock and a terminal attempt without an order stops before reservation or `validateOrder()`. A database failure while looking up the existing Order API transaction fails closed instead of being treated as absence and inserting a second attempt. Every checkout keeps `persist transaction -> reserve exact attempt -> validateOrder with opaque attempt reference -> link exact attempt` inside the webhook's cart-scoped lock, including Card and the shared Pix/PSE/Ticket/Yape path. Checkout Pro and Wallet Button apply the same protocol on the deferred return and repeated returns recognize an existing exact link idempotently. Webhook-created orders use `webhook-pending:<id_mp_transaction>`, so a delivery whose `validateOrder()` fails returns retryable HTTP 503 and can safely retry the exact reservation; checkout/deferred `pending:<id_mp_transaction>` remains fail-closed to notification creation. A proven pre-order financial mismatch can replace either retryable namespace with `invalid-cart:<id_mp_transaction>`, preventing an invalid webhook retry. Retry after an interrupted final link still requires the matching namespace and HMAC order reference, so a stale reservation cannot claim another attempt's order. (PPSP-1883)

### Changed
- Make Claude/Codex SessionStart offline; keep AOC tool synchronization explicit and leave its generated installer unreferenced and ignored.
- Migrate maintained agent documentation to AOC under `.agents/`, with Claude/Codex SessionStart hooks, explicit sync and manifest-based freshness scopes.
- Move internal GitHub Actions validation to pre-commit, preserving WebSec/DataSec and adding doc freshness v1.1.0, local PHP lint, unit tests and isolated production ZIP/package checks.
- Agentified: generated AGENTS.md and the initial agent knowledge package, now maintained under `.agents/`.

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
