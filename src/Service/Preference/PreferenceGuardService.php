<?php
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
 *
 * @author    PrestaShop SA and Contributors <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace MercadoPago\Service\Preference;

use MercadoPago\Client\MPApi;
use MercadoPago\Repository\MPTransactionRepository;
use MercadoPago\Repository\PreferenceGuardRepository;
use MercadoPago\Service\Notification\Exception\NotificationProcessingException;
use MercadoPago\Service\Notification\Exception\NotificationRetryableException;
use MercadoPago\Service\Notification\Exception\NotificationSkippedException;
use MercadoPago\Service\Notification\Exception\PendingCheckoutRetryException;
use MercadoPago\Service\Order\OrderAttemptReference;

if (!defined('_PS_VERSION_')) {
    exit;
}

/** Canonical Checkout Pro correlation and one guarded materialization path. */
class PreferenceGuardService
{
    private const ORDER_CREATION_UNAVAILABLE = 'Order creation is temporarily unavailable';

    private array $merchantResults = [];

    public function __construct(
        private MPApi $api,
        private MPTransactionRepository $transactions,
        private PreferenceGuardRepository $storage,
        private PreferenceService $preferences,
    ) {
    }

    /** A generic MP order type also occurs outside preferences; confirm the checkout family. */
    public function isPreferencePayment(array $payment, ?array $transaction = null, string $expectedPreference = ''): bool
    {
        if ($expectedPreference !== '' || ($payment['metadata']['checkout'] ?? '') === 'preference'
            || ($payment['metadata']['checkout_type'] ?? '') === 'wallet_button'
            || preg_match('/^[0-9]+-[a-f0-9-]{36}$/D', (string) ($transaction['order_id'] ?? '')) === 1
        ) {
            return true;
        }
        if (($payment['order']['type'] ?? '') !== 'mercadopago') {
            return false;
        }
        $merchantId = (string) ($payment['order']['id'] ?? '');
        if (preg_match('/^[0-9]{1,30}$/D', $merchantId) !== 1) {
            throw new NotificationRetryableException('Canonical checkout family is unavailable');
        }
        $result = $this->merchantResult($merchantId);
        $http = (int) ($result['status'] ?? 0);
        if ($http < 200 || $http >= 300) {
            throw new NotificationRetryableException('Canonical checkout family could not be confirmed');
        }

        return !empty($result['response']['preference_id']);
    }

    private function merchantResult(string $merchantId): array
    {
        if (!isset($this->merchantResults[$merchantId])) {
            try {
                $this->merchantResults[$merchantId] = $this->api->getMerchantOrder($merchantId);
            } catch (\Throwable $cause) {
                throw new NotificationRetryableException('Merchant order API request failed', 0, $cause);
            }
        }

        return $this->merchantResults[$merchantId];
    }

    public function ensureOrder(
        array $payment,
        string $secureKey = '',
        int $expectedCart = 0,
        string $expectedPreference = '',
        array $notificationPaymentIds = [],
    ): array {
        $paymentId = (string) ($payment['id'] ?? '');
        $merchantId = (string) ($payment['order']['id'] ?? '');
        if (preg_match('/^[0-9]{1,30}$/D', $paymentId) !== 1) {
            throw new NotificationProcessingException('Canonical payment ID is invalid');
        }
        if (preg_match('/^[0-9]{1,30}$/D', $merchantId) !== 1) {
            $this->blockWithoutLock(null, $payment, '', 'missing_relationship');
        }
        $result = $this->merchantResult($merchantId);
        $http = (int) ($result['status'] ?? 0);
        if ($http === 0 || $http === 429 || $http >= 500) {
            throw new NotificationRetryableException('Merchant order API is temporarily unavailable');
        }
        if ($http === 404) {
            $this->blockWithoutLock(null, $payment, '', 'missing_relationship');
        }
        if ($http < 200 || $http >= 300) {
            throw new NotificationRetryableException('Merchant order API could not be verified');
        }
        $merchant = $result['response'] ?? null;
        if (!is_array($merchant) || !is_array($merchant['payments'] ?? null)) {
            throw new NotificationRetryableException('Merchant order API returned an invalid response');
        }
        $preferenceId = (string) ($merchant['preference_id'] ?? '');
        $cartId = (int) ($payment['external_reference'] ?? 0);
        $paymentIds = [];
        foreach ($merchant['payments'] as $merchantPayment) {
            if (!is_array($merchantPayment)) {
                throw new NotificationRetryableException('Merchant order API returned an invalid payment');
            }
            $id = $merchantPayment['id'] ?? null;
            if (is_int($id) || is_string($id)) {
                $paymentIds[] = (string) $id;
            }
        }
        if ((string) ($merchant['id'] ?? '') !== $merchantId
            || preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $preferenceId) !== 1
            || $cartId <= 0 || (string) ($payment['external_reference'] ?? '') !== (string) $cartId
            || (string) ($merchant['external_reference'] ?? '') !== (string) $cartId
            || !in_array($paymentId, $paymentIds, true)
        ) {
            $this->blockWithoutLock(null, $payment, '', 'missing_relationship');
        }
        if (($expectedCart > 0 && $cartId !== $expectedCart)
            || ($expectedPreference !== '' && $preferenceId !== $expectedPreference)
        ) {
            throw new NotificationProcessingException('Return hints do not match canonical preference');
        }
        try {
            $transaction = $this->storage->find($preferenceId);
        } catch (\DomainException) {
            $this->blockWithoutLock(null, $payment, $preferenceId, 'ambiguous_preference');
        }
        if ($transaction === null) {
            $this->blockWithoutLock(null, $payment, $preferenceId, 'missing_snapshot');
        }
        if ((int) $transaction['cart_id'] !== $cartId) {
            $this->blockWithoutLock(null, $payment, $preferenceId, 'reference_mismatch');
        }
        // A terminal negative attempt or a pending payment cannot create an order.
        if (($transaction['guard_status'] ?? '') !== 'linked' && ($payment['status'] ?? '') !== 'approved') {
            throw new NotificationSkippedException('Checkout Pro payment has no approved order to process', 'guard_pending');
        }
        $cart = new \Cart($cartId);
        $customer = new \Customer((int) $cart->id_customer);
        if (!$cart->id) {
            $this->blockWithoutLock($transaction, $payment, $preferenceId, 'missing_cart');
        }
        if ($secureKey !== '' && !hash_equals((string) $customer->secure_key, $secureKey)) {
            throw new NotificationProcessingException('Checkout Pro payment owner does not match');
        }

        if (!$this->transactions->acquireNotificationLock($cartId)) {
            throw new NotificationRetryableException('Could not acquire preference order lock');
        }
        $transactionId = 0;
        $uncertainMarkerPersisted = false;
        $validateOrderStarted = false;
        try {
            $this->transactions->beginCartOrderTransactionAndLock($cartId);
            try {
                $transaction = $this->storage->find($preferenceId);
                if ($transaction === null) {
                    throw new \RuntimeException('Preference transaction disappeared');
                }
                if (($transaction['guard_status'] ?? '') === 'linked') {
                    if (($transaction['guard_payment_id'] ?? '') !== $paymentId) {
                        if (($payment['status'] ?? '') === 'approved') {
                            $this->storage->review($transaction, $payment, $preferenceId, 'duplicate_approved_payment');
                            $this->transactions->commitCartOrderTransaction();
                        }
                        throw new NotificationSkippedException('Another payment owns this preference order', 'preference_guard');
                    }
                    try {
                        $this->assertLinkedOrder($transaction, $paymentId);
                    } catch (NotificationProcessingException $cause) {
                        if (($payment['status'] ?? '') !== 'approved') {
                            throw $cause;
                        }
                        $this->storage->review($transaction, $payment, $preferenceId, 'order_ownership_mismatch');
                        $this->transactions->commitCartOrderTransaction();
                        throw new NotificationSkippedException('Linked order requires manual reconciliation', 'preference_guard');
                    }
                    $this->transactions->commitCartOrderTransaction();

                    return $transaction;
                }
                $this->assertTerminalPaymentRecovery(
                    $transaction,
                    $payment,
                    $preferenceId,
                    $paymentId,
                    $paymentIds,
                    $notificationPaymentIds
                );
                if (MPTransactionRepository::hasPendingOrderCreationMarker($transaction)) {
                    throw new PendingCheckoutRetryException('Checkout still owns this preference order attempt');
                }
                if (MPTransactionRepository::hasUncertainOrderCreationMarker($transaction)) {
                    $this->storage->review($transaction, $payment, $preferenceId, 'uncertain_order');
                    $this->transactions->commitCartOrderTransaction();
                    throw new NotificationSkippedException('Preference order creation requires manual reconciliation', 'uncertain_order');
                }
                $reason = ($transaction['guard_status'] ?? '') === 'blocked'
                    ? (string) ($transaction['guard_reason'] ?: 'manual_review') : null;
                if ($reason === null && MPTransactionRepository::hasInvalidOrderCreationMarker($transaction)) {
                    $reason = 'invalid_cart';
                }
                if ($reason === null && empty($transaction['snapshot_hash'])) {
                    $reason = 'missing_snapshot';
                }
                if ($reason === null && $this->currentOrderId($cartId) > 0) {
                    $reason = 'existing_order';
                }
                if ($reason === null) {
                    \Cart::resetStaticCache();
                    $cart = new \Cart($cartId);
                    $current = $this->currentSnapshot($cart);
                    $reason = $current['guard_reason'] ?? PreferenceGuardDecision::reason($transaction, $payment, $current);
                }
                if ($reason !== null) {
                    $this->storage->review($transaction, $payment, $preferenceId, $reason);
                    $this->transactions->commitCartOrderTransaction();
                    throw new NotificationSkippedException('Approved payment requires manual reconciliation', 'preference_guard');
                }

                $transactionId = (int) $transaction['id_mp_transaction'];
                // Keep the webhook reservation durable while preparing the order attempt.
                $this->transactions->commitCartOrderTransaction();
                if ($this->transactions->reserveWebhookOrderCreation($transactionId, $cartId) !== true) {
                    throw new \RuntimeException('Could not reserve preference order ownership');
                }
                $this->transactions->beginCartOrderTransactionAndLock($cartId);
                $transaction = $this->storage->find($preferenceId);
                if ($transaction === null || (int) $transaction['cart_id'] !== $cartId
                    || ($transaction['guard_status'] ?? '') !== 'open'
                    || !($this->transactions->isWebhookOrderCreationReserved($transactionId, $cartId) ?? false)
                ) {
                    throw new \RuntimeException('Could not confirm preference order reservation');
                }
                $this->assertTerminalPaymentRecovery(
                    $transaction,
                    $payment,
                    $preferenceId,
                    $paymentId,
                    $paymentIds,
                    $notificationPaymentIds
                );
                \Cart::resetStaticCache();
                $cart = new \Cart($cartId);
                $customer = new \Customer((int) $cart->id_customer);
                if (!$cart->id || !$customer->id
                    || ($secureKey !== '' && !hash_equals((string) $customer->secure_key, $secureKey))
                ) {
                    throw new NotificationProcessingException('Checkout Pro payment owner changed before order creation');
                }
                if ($this->currentOrderId($cartId) > 0) {
                    $this->storage->review($transaction, $payment, $preferenceId, 'existing_order');
                    $this->transactions->commitCartOrderTransaction();
                    throw new NotificationSkippedException('Preference order requires manual reconciliation', 'preference_guard');
                }
                $current = $this->currentSnapshot($cart);
                $reason = $current['guard_reason'] ?? PreferenceGuardDecision::reason($transaction, $payment, $current);
                if ($reason !== null) {
                    $this->storage->review($transaction, $payment, $preferenceId, $reason);
                    $this->transactions->commitCartOrderTransaction();
                    throw new NotificationSkippedException('Approved payment requires manual reconciliation', 'preference_guard');
                }
                $module = \Module::getInstanceByName('mercadopago');
                if (!$module || !method_exists($module, 'validateOrder')) {
                    throw new NotificationRetryableException('Mercado Pago module is unavailable for order creation');
                }
                // validateOrder() rejects an unloadable order state or an inactive module before
                // any hook, order or e-mail exists, and a narrow reference column would only fail
                // after them. Check all three first so a configuration error stays a retryable
                // 503 instead of a permanent uncertain_order block.
                $orderStateId = (int) $this->preferences->withCartContext(
                    $cart,
                    fn (): int => $this->approvedOrderStateId()
                );
                $this->assertOrderCreationPrerequisites(
                    $module,
                    $orderStateId,
                    OrderAttemptReference::length()
                );
                // A database rollback cannot retract hooks or emails emitted by validateOrder.
                // Persist the fail-closed marker before invoking code with external side effects.
                $this->transactions->commitCartOrderTransaction();
                if ($this->transactions->markOrderCreationUncertain($transactionId, $cartId) !== true) {
                    throw new \RuntimeException('Could not mark preference order creation as uncertain');
                }
                $uncertainMarkerPersisted = true;
                $this->transactions->beginCartOrderTransactionAndLock($cartId);
                $transaction = $this->storage->find($preferenceId);
                if ($transaction === null || (int) $transaction['cart_id'] !== $cartId
                    || ($transaction['guard_status'] ?? '') !== 'open'
                    || !MPTransactionRepository::hasUncertainOrderCreationMarker($transaction)
                ) {
                    throw new \RuntimeException('Could not confirm uncertain preference order reservation');
                }
                $this->assertTerminalPaymentRecovery(
                    $transaction,
                    $payment,
                    $preferenceId,
                    $paymentId,
                    $paymentIds,
                    $notificationPaymentIds
                );
                \Cart::resetStaticCache();
                $cart = new \Cart($cartId);
                $customer = new \Customer((int) $cart->id_customer);
                if (!$cart->id || !$customer->id
                    || ($secureKey !== '' && !hash_equals((string) $customer->secure_key, $secureKey))
                ) {
                    throw new NotificationProcessingException('Checkout Pro payment owner changed before order creation');
                }
                if ($this->currentOrderId($cartId) > 0) {
                    $this->storage->review($transaction, $payment, $preferenceId, 'existing_order');
                    $this->transactions->commitCartOrderTransaction();
                    throw new NotificationSkippedException('Preference order requires manual reconciliation', 'preference_guard');
                }
                $current = $this->currentSnapshot($cart);
                $reason = $current['guard_reason'] ?? PreferenceGuardDecision::reason($transaction, $payment, $current);
                if ($reason !== null) {
                    $this->storage->review($transaction, $payment, $preferenceId, $reason);
                    $this->transactions->commitCartOrderTransaction();
                    throw new NotificationSkippedException('Approved payment requires manual reconciliation', 'preference_guard');
                }
                $this->preferences->withCartContext($cart, function () use ($module, $cartId, $transaction, $customer, $cart, $transactionId, $orderStateId, &$validateOrderStarted): void {
                    $validateOrderStarted = true;
                    $module->validateOrder(
                        $cartId,
                        $orderStateId,
                        (float) $transaction['snapshot_total'],
                        $module->displayName,
                        null,
                        [],
                        (int) $cart->id_currency,
                        false,
                        $customer->secure_key,
                        new \Shop((int) $cart->id_shop),
                        OrderAttemptReference::fromAttempt($transactionId, $cartId)
                    );
                });
                $orderId = $this->currentOrderId($cartId);
                $order = new \Order($orderId);
                if ($orderId <= 0) {
                    throw new OrderCreationDiagnosticException('Could not link exact preference order: validateOrder() returned without an order for the cart');
                }
                if (!OrderAttemptReference::matches($order, $transactionId, $cartId)) {
                    // The opaque reference is not sensitive, but its length tells a truncating
                    // ps_orders.reference column or a module that rewrites references apart.
                    throw new OrderCreationDiagnosticException('Could not link exact preference order: the order reference does not match the attempt (stored length ' . strlen((string) $order->reference) . ', expected ' . strlen(OrderAttemptReference::fromAttempt($transactionId, $cartId)) . ')');
                }
                if ($this->transactions->linkMerchantOrderId($transactionId, $cartId, $orderId) !== true) {
                    throw new OrderCreationDiagnosticException('Could not link exact preference order: the order link was not persisted');
                }
                if (!$this->storage->materializedMatches($transaction, $order)) {
                    $this->transactions->rollbackCartOrderTransaction();
                    $this->transactions->beginCartOrderTransactionAndLock($cartId);
                    $this->storage->review($transaction, $payment, $preferenceId, 'materialization_mismatch');
                    $this->transactions->commitCartOrderTransaction();
                    throw new NotificationSkippedException('Order materialization requires reconciliation', 'preference_guard');
                }
                $this->storage->link($transactionId, $paymentId, $orderId);
                $this->transactions->commitCartOrderTransaction();

                return $this->storage->find($preferenceId) ?? throw new \RuntimeException('Linked preference disappeared');
            } catch (\Throwable $cause) {
                $this->transactions->rollbackCartOrderTransaction();
                // Log only after the rollback: anything written inside the cart transaction,
                // including PrestaShop's own validateOrder() log, is discarded with it.
                if (!$cause instanceof NotificationSkippedException
                    && !$cause instanceof PendingCheckoutRetryException
                ) {
                    $this->logOrderCreationFailure($cartId, $transactionId, $validateOrderStarted, $cause);
                }
                if ($uncertainMarkerPersisted && !$validateOrderStarted
                    && $this->transactions->restoreWebhookOrderCreationReservation($transactionId, $cartId) !== true
                ) {
                    throw new NotificationRetryableException('Could not restore preference order reservation', 0, $cause);
                }
                throw $cause;
            }
        } catch (NotificationSkippedException|NotificationProcessingException|NotificationRetryableException $cause) {
            throw $cause;
        } catch (\Throwable $cause) {
            throw new NotificationRetryableException('Preference guard could not persist its decision', 0, $cause);
        } finally {
            $this->transactions->releaseNotificationLock($cartId);
        }
    }

    /** Same resolution validateOrder() receives: the module state, or PS_OS_PAYMENT when unset. */
    private function approvedOrderStateId(): int
    {
        $state = (int) \Configuration::get('MERCADOPAGO_STATUS_1');

        return $state > 0 ? $state : (int) \Configuration::get('PS_OS_PAYMENT');
    }

    /**
     * Mirror the checks PaymentModule::validateOrder() performs before any side effect,
     * plus the core columns' capacity for the opaque attempt reference: a legacy
     * VARCHAR(9) silently truncates it, and only after hooks and e-mails ran.
     * The top-level message reaches the buyer through the Checkout Pro return banner,
     * so it stays generic; the configuration detail travels as the logged previous cause.
     */
    private function assertOrderCreationPrerequisites(object $module, int $orderStateId, int $referenceLength): void
    {
        $state = $orderStateId > 0 ? new \OrderState($orderStateId) : null;
        if ($state === null || (int) $state->id !== $orderStateId) {
            throw new NotificationRetryableException(self::ORDER_CREATION_UNAVAILABLE, 0, new OrderCreationDiagnosticException('Approved Checkout Pro order state ' . $orderStateId . ' cannot be loaded; review MERCADOPAGO_STATUS_1'));
        }
        if (property_exists($module, 'active') && !$module->active) {
            throw new NotificationRetryableException(self::ORDER_CREATION_UNAVAILABLE, 0, new OrderCreationDiagnosticException('Mercado Pago module is not active for order creation'));
        }
        // Unknown capacity keeps the previous behavior; only a proven narrow column blocks.
        $capacity = $this->transactions->orderReferenceCapacity();
        if ($capacity !== null && $capacity < $referenceLength) {
            throw new NotificationRetryableException(self::ORDER_CREATION_UNAVAILABLE, 0, new OrderCreationDiagnosticException('Order reference columns hold ' . $capacity . ' characters but the Mercado Pago attempt reference needs ' . $referenceLength . '; widen orders.reference and order_payment.order_reference to VARCHAR(255) as PrestaShop 9 does'));
        }
    }

    private function logOrderCreationFailure(
        int $cartId,
        int $transactionId,
        bool $validateOrderStarted,
        \Throwable $cause,
    ): void {
        $chain = [];
        for ($error = $cause, $depth = 0; $error !== null && $depth < 3; $error = $error->getPrevious(), ++$depth) {
            $chain[] = get_class($error) . ': ' . self::loggableExceptionMessage($error);
        }
        // Payment activity identifiers (cart, transaction, payment, order) never reach the log (PPSP-1604).
        $message = '[MercadoPago] Preference order creation failed (validateOrder '
            . ($validateOrderStarted ? 'started' : 'not started') . '): ' . implode(' | caused by ', $chain);

        \PrestaShopLogger::addLog(mb_substr($message, 0, 1000), 3, null, 'MPNotification', 0, true);
    }

    /**
     * Exception text may come from the core or third-party hooks. Database errors can
     * carry the failed SQL (customer data), so only their class is kept; other messages
     * are flattened to one line with e-mail addresses masked.
     *
     * PrestaShopLogger stores the message through pSQL(), whose strip_tags() drops
     * everything from a "<" on, so neither the log format nor these messages may keep
     * angle brackets (PPSP-2465). Text outside OrderCreationDiagnosticException can carry
     * payment activity identifiers, so its digits are masked (PPSP-1604).
     */
    private static function loggableExceptionMessage(\Throwable $error): string
    {
        if ($error instanceof \PrestaShopDatabaseException || $error instanceof \PDOException) {
            return '(database error details withheld)';
        }
        $message = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $error->getMessage());
        $message = (string) preg_replace('/[^\s@<>"\']+@[^\s@<>"\']+\.[A-Za-z]{2,}/u', '[email]', $message);
        $message = str_replace(['<', '>'], ['[', ']'], $message);
        if (!$error instanceof OrderCreationDiagnosticException) {
            $message = (string) preg_replace('/\d+/', '#', $message);
        }

        return mb_substr(trim($message), 0, 300);
    }

    /** A prior terminal payment can be replaced only by exact proof from both views. */
    /**
     * Read the current database state again after locking and validateOrder().
     * PrestaShop deliberately disables the query cache for this lookup.
     *
     * @phpstan-impure
     */
    private function currentOrderId(int $cartId): int
    {
        return (int) \Order::getIdByCartId($cartId);
    }

    private function assertTerminalPaymentRecovery(
        array $transaction,
        array $payment,
        string $preferenceId,
        string $paymentId,
        array $merchantPaymentIds,
        array $notificationPaymentIds,
    ): void {
        $status = (string) ($transaction['payment_status'] ?? '');
        if (!in_array($status, ['failed', 'canceled', 'expired'], true)) {
            return;
        }

        $storedPaymentId = (string) ($transaction['payment_id'] ?? '');
        $notificationPaymentIds = array_values(array_map(
            'strval',
            array_filter($notificationPaymentIds, static fn ($id): bool => is_int($id) || is_string($id))
        ));
        $proven = $storedPaymentId !== ''
            && $storedPaymentId !== $paymentId
            && in_array($storedPaymentId, $merchantPaymentIds, true)
            && in_array($paymentId, $merchantPaymentIds, true)
            && in_array($storedPaymentId, $notificationPaymentIds, true)
            && in_array($paymentId, $notificationPaymentIds, true);
        if ($proven) {
            return;
        }

        if (($transaction['guard_status'] ?? '') !== 'blocked') {
            $this->storage->review($transaction, $payment, $preferenceId, 'terminal_payment');
            $this->transactions->commitCartOrderTransaction();
        }
        throw new NotificationSkippedException('Terminal Checkout Pro payment cannot create an order without exact payment-rotation proof', 'preference_guard');
    }

    private function currentSnapshot(\Cart $cart): array
    {
        try {
            return $this->preferences->withCartContext($cart, function (\Context $context) use ($cart): array {
                foreach ($cart->getCartRules(\CartRule::FILTER_ACTION_ALL, false) as $entry) {
                    $rule = new \CartRule((int) $entry['id_cart_rule']);
                    if (!$rule->checkValidity($context, true, false)) {
                        return ['guard_reason' => 'cart_rule_invalid'];
                    }
                }

                return $this->preferences->snapshotForCart($cart);
            });
        } catch (\DomainException) {
            // A cart total no longer representable by the original payload is a financial mismatch.
            return ['guard_reason' => 'amount_mismatch'];
        }
    }

    private function assertLinkedOrder(array $transaction, string $paymentId): void
    {
        $order = new \Order((int) $transaction['guard_order_id']);
        if (($transaction['guard_payment_id'] ?? '') !== $paymentId) {
            throw new NotificationProcessingException('Another payment already owns this preference order');
        }
        if (!$order->id || $order->module !== 'mercadopago'
            || (int) $order->id_cart !== (int) $transaction['cart_id']
            || (int) $order->id_shop !== (int) $transaction['snapshot_shop']
            || (int) $order->id_currency !== (int) $transaction['snapshot_currency']
            || (int) $transaction['merchant_order_id'] !== (int) $order->id
            || !OrderAttemptReference::matches($order, (int) $transaction['id_mp_transaction'], (int) $transaction['cart_id'])
        ) {
            throw new NotificationProcessingException('Linked preference order ownership is invalid');
        }
    }

    private function blockWithoutLock(?array $transaction, array $payment, string $preferenceId, string $reason): never
    {
        if (($payment['status'] ?? '') !== 'approved') {
            throw new NotificationSkippedException('Unapproved Checkout Pro payment has no order', 'guard_pending');
        }
        $pdo = $this->storage->connection();
        try {
            $pdo->beginTransaction();
            $this->storage->review($transaction, $payment, $preferenceId, $reason);
            $pdo->commit();
        } catch (\Throwable $cause) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw new NotificationRetryableException('Could not persist manual reconciliation', 0, $cause);
        }
        throw new NotificationSkippedException('Approved payment requires manual reconciliation', 'preference_guard');
    }
}
