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
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * @author    PrestaShop SA and Contributors <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

/**
 * 2007-2025 PrestaShop.
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * @copyright Copyright (c) MercadoPago
 * @license   http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

namespace MercadoPago\Service\Notification;

use MercadoPago\Service\Notification\Exception\NotificationNotFoundException;
use MercadoPago\Service\Notification\Exception\NotificationProcessingException;
use MercadoPago\Service\Notification\Exception\NotificationSameStateException;
use MercadoPago\Service\Notification\Exception\NotificationSkippedException;

if (!defined('_PS_VERSION_')) {
    exit;
}

class PaymentNotificationService extends AbstractNotificationService
{
    private const STATUS_MAP = [
        'action_required' => 'MERCADOPAGO_STATUS_0',
        'processing'      => 'MERCADOPAGO_STATUS_0',
        'in_review'       => 'MERCADOPAGO_STATUS_0',
        'processed'       => 'MERCADOPAGO_STATUS_1',
        'authorized'      => 'MERCADOPAGO_STATUS_8',
        'pending'         => 'MERCADOPAGO_STATUS_7',
        'canceled'        => 'MERCADOPAGO_STATUS_2',
        'expired'         => 'MERCADOPAGO_STATUS_2',
        'failed'          => 'MERCADOPAGO_STATUS_3',
        'refunded'        => 'MERCADOPAGO_STATUS_4',
        'charged_back'    => 'MERCADOPAGO_STATUS_5',
        'in_mediation'    => 'MERCADOPAGO_STATUS_6',
    ];

    /**
     * Classic MP payment statuses (Checkout Pro / merchant_order feed) mapped to
     * the canonical tokens used across this service. The legacy notification flow
     * receives these instead of the PP_ORDER statuses, so they are normalized
     * before the status mapping and downgrade/terminal logic run.
     */
    private const CLASSIC_STATUS_MAP = [
        'approved'     => 'processed',
        'authorized'   => 'authorized',
        'in_process'   => 'processing',
        'pending'      => 'pending',
        'in_mediation' => 'in_mediation',
        'rejected'     => 'failed',
        'cancelled'    => 'canceled',
        'canceled'     => 'canceled',
        'refunded'     => 'refunded',
        'charged_back' => 'charged_back',
    ];

    private const TERMINAL_STATES = [
        'processed', 'canceled', 'expired', 'failed', 'refunded', 'charged_back',
    ];

    private const DEVOLUTION_STATUSES = ['refunded', 'charged_back', 'in_mediation'];

    private const ALLOWED_TERMINAL_TRANSITIONS = [
        'processed'    => ['in_mediation', 'refunded', 'charged_back'],
        'in_mediation' => ['refunded', 'charged_back'],
    ];

    private const NEGATIVE_STATUSES = ['failed', 'canceled', 'expired'];

    public function processPaymentNotificationWithAggregate(
        string $paymentId,
        string $secureKey,
        string $aggregatedStatus,
        float $aggregatedAmount,
        int $cartId = 0
    ): void {
        if ($aggregatedStatus === '' || $aggregatedAmount <= 0) {
            $this->processPaymentNotification($paymentId, $secureKey, $cartId);
            return;
        }

        $transaction = $this->resolveTransaction($paymentId, $cartId);

        if (!$transaction) {
            throw new NotificationNotFoundException('No transaction found for payment ' . $paymentId);
        }

        $transactionId = (int) $transaction['id_mp_transaction'];
        $this->persistPaymentId($transaction, $paymentId);

        if ($secureKey !== '' && !$this->isTransactionOwner($transaction, $secureKey)) {
            throw new NotificationProcessingException(
                'secure_key does not match order owner for payment ' . $paymentId
            );
        }

        $this->repository->markWebhookReceived($transactionId);

        $currentStatus = (string) ($transaction['payment_status'] ?? '');

        if ($this->isDowngrade($currentStatus, $aggregatedStatus)) {
            throw new NotificationSkippedException(
                'Downgrade blocked: ' . $currentStatus . ' → ' . $aggregatedStatus
            );
        }

        $this->applyOrderState($transaction, $aggregatedStatus, $aggregatedAmount);

        $persisted = $this->repository->updatePaymentStatus($transactionId, $aggregatedStatus, $currentStatus);
        if (!$persisted && $aggregatedStatus !== $currentStatus) {
            \PrestaShopLogger::addLog(
                '[MP Notification] Optimistic lock: status not persisted for transaction ' . $transactionId
                . ' (' . $currentStatus . ' → ' . $aggregatedStatus . '), likely a concurrent notification',
                2, null, 'MPNotification', 0, true
            );
        }
    }

    public function processPaymentNotification(string $paymentId, string $secureKey = '', int $cartId = 0): void
    {
        $transaction = $this->resolveTransaction($paymentId, $cartId);

        if (!$transaction) {
            \PrestaShopLogger::addLog(
                '[MP Notification] No transaction found for payment ' . $paymentId,
                2, null, 'MPNotification', 0, true
            );
            throw new NotificationNotFoundException('No transaction found for payment ' . $paymentId);
        }

        $transactionId = (int) $transaction['id_mp_transaction'];
        $currentStatus = (string) ($transaction['payment_status'] ?? '');
        $this->persistPaymentId($transaction, $paymentId);

        if ($secureKey !== '' && !$this->isTransactionOwner($transaction, $secureKey)) {
            \PrestaShopLogger::addLog(
                '[MP Notification] secure_key does not match order owner for payment ' . $paymentId,
                2, null, 'MPNotification', 0, true
            );
            throw new NotificationProcessingException(
                'secure_key does not match order owner for payment ' . $paymentId
            );
        }

        $this->repository->markWebhookReceived($transactionId);

        $apiResult = $this->mpApi->getPayment($paymentId);
        $httpStatus = $apiResult['status'] ?? 0;
        $payment = $apiResult['response'] ?? [];

        if ($httpStatus < 200 || $httpStatus >= 300 || empty($payment['status'])) {
            throw new NotificationProcessingException(
                'Could not fetch payment ' . $paymentId . ' — HTTP ' . $httpStatus
            );
        }

        $mpStatus = $this->normalizeStatus((string) $payment['status']);

        if ($this->isDowngrade($currentStatus, $mpStatus)) {
            throw new NotificationSkippedException('Downgrade blocked: ' . $currentStatus . ' → ' . $mpStatus);
        }

        $this->applyOrderState($transaction, $mpStatus, (float) ($payment['transaction_details']['total_paid_amount'] ?? 0));

        $persisted = $this->repository->updatePaymentStatus($transactionId, $mpStatus, $currentStatus);
        if (!$persisted && $mpStatus !== $currentStatus) {
            \PrestaShopLogger::addLog(
                '[MP Notification] Optimistic lock: status not persisted for transaction ' . $transactionId
                . ' (' . $currentStatus . ' → ' . $mpStatus . '), likely a concurrent notification',
                2, null, 'MPNotification', 0, true
            );
        }
    }

    public function processOrderNotification(array $notification): void
    {
        $status = (string) ($notification['status'] ?? '');
        $externalRef = (string) ($notification['external_reference'] ?? '');
        $paymentsDetails = $notification['payments_details'] ?? [];
        $totalPaid = (float) ($notification['total_paid'] ?? 0);

        if ($status === '' || $externalRef === '') {
            throw new NotificationProcessingException('Missing required fields: status or external_reference');
        }

        $cartId = (int) $externalRef;
        $transaction = $this->repository->findByCartId($cartId);

        if (!$transaction && !empty($paymentsDetails[0]['references']['payment_id'])) {
            $transaction = $this->repository->findByPaymentId(
                (string) $paymentsDetails[0]['references']['payment_id']
            );
        }

        if (!$transaction) {
            \PrestaShopLogger::addLog(
                '[MP Notification] No transaction found for cart ' . $cartId,
                2, null, 'MPNotification', 0, true
            );
            throw new NotificationNotFoundException('No transaction found for cart ' . $cartId);
        }

        $transactionId = (int) $transaction['id_mp_transaction'];
        $this->repository->markWebhookReceived($transactionId);

        $currentStatus = (string) ($transaction['payment_status'] ?? '');

        if ($this->isDowngrade($currentStatus, $status)) {
            throw new NotificationSkippedException('Downgrade blocked: ' . $currentStatus . ' → ' . $status);
        }

        $sameStateException = null;
        try {
            $this->applyOrderState($transaction, $status, $totalPaid);
        } catch (NotificationSameStateException $e) {
            $sameStateException = $e;
        }

        $this->updateOrderPaymentTransactionId($transaction, $paymentsDetails);

        $persisted = $this->repository->updatePaymentStatus($transactionId, $status, $currentStatus);
        if (!$persisted && $status !== $currentStatus) {
            \PrestaShopLogger::addLog(
                '[MP Notification] Optimistic lock: status not persisted for transaction ' . $transactionId
                . ' (' . $currentStatus . ' → ' . $status . '), likely a concurrent notification',
                2, null, 'MPNotification', 0, true
            );
        }

        if ($sameStateException !== null) {
            throw $sameStateException;
        }
    }

    private function updateOrderPaymentTransactionId(array $transaction, array $paymentsDetails): void
    {
        $paymentId = (string) ($paymentsDetails[0]['references']['payment_id'] ?? '');
        $psOrderId = $this->resolvePSOrderId($transaction);

        if ($paymentId === '' || $psOrderId <= 0) {
            return;
        }

        $order = new \Order($psOrderId);
        $payments = $order->id ? $order->getOrderPaymentCollection() : [];

        if (!empty($payments[0]) && (string) $payments[0]->transaction_id !== $paymentId) {
            $payments[0]->transaction_id = $paymentId;
            $payments[0]->update();
        }
    }

    private function applyOrderState(array $transaction, string $status, float $totalPaid = 0.0): void
    {
        $newStateId = $this->mapStatusToPSState($status);

        if ($newStateId <= 0) {
            return;
        }

        $psOrderId = $this->resolvePSOrderId($transaction);

        if ($psOrderId <= 0) {
            $psOrderId = $this->createOrderFromNotification($transaction, $status, $totalPaid);
            if ($psOrderId <= 0) {
                \PrestaShopLogger::addLog(
                    '[MP Notification] Could not create order for cart ' . (int) $transaction['cart_id'],
                    3, null, 'MPNotification', 0, true
                );
                throw new NotificationNotFoundException(
                    'Could not create order for cart ' . (int) $transaction['cart_id']
                );
            }
        }

        $order = new \Order($psOrderId);
        if (!$order->id) {
            throw new NotificationProcessingException('Order ' . $psOrderId . ' not found in PS');
        }

        if ((int) $order->current_state === $newStateId) {
            throw new NotificationSameStateException('Order ' . $psOrderId . ' already in state ' . $newStateId);
        }

        if (!in_array($status, self::DEVOLUTION_STATUSES, true)
            && !$this->isCurrentStateAllowed((int) $order->current_state)
        ) {
            throw new NotificationSkippedException(
                'Order ' . $psOrderId . ' state was manually changed — skipping update'
            );
        }

        if ($status === 'processed' && $totalPaid > 0) {
            $cart = new \Cart((int) $transaction['cart_id']);
            if ($cart->id) {
                $orderTotal = (float) $cart->getOrderTotal(true, \Cart::BOTH);
                if ($orderTotal > 0 && $totalPaid < ($orderTotal - 0.01)) {
                    \PrestaShopLogger::addLog(
                        '[MP Notification] Possible fraud on order ' . $psOrderId
                        . ': paid ' . $totalPaid . ' but order total is ' . $orderTotal,
                        3, null, 'Order', $psOrderId, true
                    );
                    $fraudState = (int) \Configuration::get('MERCADOPAGO_STATUS_9');
                    $newStateId = $fraudState > 0 ? $fraudState : (int) \Configuration::get('PS_OS_ERROR');
                }
            }
        }

        $orders = \Order::getByReference($order->reference);
        if (empty($orders)) {
            $orders = [$order];
        }

        foreach ($orders as $splitOrder) {
            if ((int) $splitOrder->current_state !== $newStateId) {
                $splitOrder->setCurrentState($newStateId);
                \PrestaShopLogger::addLog(
                    '[MP Notification] Order ' . $splitOrder->id
                    . ' state updated to ' . $newStateId
                    . ' (status: ' . $status . ')',
                    1, null, 'Order', (int) $splitOrder->id, true
                );
            }
        }
    }

    /**
     * Locate the transaction row from the MP payment id, falling back to the
     * cart id (external_reference) when the payment id is not yet stored — which
     * is the case for Checkout Pro, whose preference transaction only knows the
     * cart until a payment exists.
     *
     * @return array|null
     */
    private function resolveTransaction(string $paymentId, int $cartId): ?array
    {
        $transaction = $paymentId !== '' ? $this->repository->findByPaymentId($paymentId) : null;

        if (!$transaction && $cartId > 0) {
            $transaction = $this->repository->findByCartId($cartId);
        }

        return $transaction;
    }

    /**
     * Persist the MP payment id on the transaction so later payment-topic IPNs
     * can be reconciled directly by payment id.
     */
    private function persistPaymentId(array $transaction, string $paymentId): void
    {
        if ($paymentId === '' || (string) ($transaction['payment_id'] ?? '') === $paymentId) {
            return;
        }

        $this->repository->update(['payment_id' => $paymentId], (int) $transaction['id_mp_transaction']);
    }

    /**
     * Validate the notification's customer key. When the order already exists it
     * is checked against the order's customer; otherwise (the order may be
     * created by this very notification) it is checked against the cart's
     * customer — keeping the same ownership guarantee in both cases.
     */
    private function isTransactionOwner(array $transaction, string $secureKey): bool
    {
        $psOrderId = $this->resolvePSOrderId($transaction);
        if ($psOrderId > 0) {
            $order = new \Order($psOrderId);
            if ($order->id) {
                $customer = new \Customer((int) $order->id_customer);
                return $customer->id > 0 && $customer->secure_key === $secureKey;
            }
        }

        $cartId = (int) ($transaction['cart_id'] ?? 0);
        if ($cartId > 0) {
            $cart = new \Cart($cartId);
            if ($cart->id) {
                $customer = new \Customer((int) $cart->id_customer);
                return $customer->id > 0 && $customer->secure_key === $secureKey;
            }
        }

        return false;
    }

    private function resolvePSOrderId(array $transaction): int
    {
        $merchantOrderId = (int) ($transaction['merchant_order_id'] ?? 0);
        if ($merchantOrderId > 0) {
            return $merchantOrderId;
        }

        return (int) \Order::getIdByCartId((int) $transaction['cart_id']);
    }

    /**
     * Normalize a classic MP payment status (Checkout Pro / merchant_order feed)
     * to the canonical token used across this service. PP_ORDER statuses pass
     * through unchanged.
     */
    private function normalizeStatus(string $status): string
    {
        return self::CLASSIC_STATUS_MAP[$status] ?? $status;
    }

    private function mapStatusToPSState(string $status): int
    {
        if (!isset(self::STATUS_MAP[$status])) {
            \PrestaShopLogger::addLog(
                '[MP Notification] Unmapped status received: ' . $status,
                2, null, 'MPNotification', 0, true
            );
            return 0;
        }

        return (int) \Configuration::get(self::STATUS_MAP[$status]);
    }

    private function isDowngrade(string $currentStatus, string $newStatus): bool
    {
        if ($currentStatus === $newStatus) {
            return false;
        }

        if (in_array($newStatus, self::DEVOLUTION_STATUSES, true)) {
            return false;
        }

        if (!in_array($currentStatus, self::TERMINAL_STATES, true)) {
            return false;
        }

        $allowed = self::ALLOWED_TERMINAL_TRANSITIONS[$currentStatus] ?? [];
        return !in_array($newStatus, $allowed, true);
    }

    private function createOrderFromNotification(array $transaction, string $status, float $totalPaid = 0.0): int
    {
        $cartId = (int) $transaction['cart_id'];
        $cart = new \Cart($cartId);

        if (!$cart->id) {
            return 0;
        }

        if (in_array($status, self::NEGATIVE_STATUSES, true)) {
            return 0;
        }

        $orderTotal = (float) $cart->getOrderTotal(true, \Cart::BOTH);
        if ($totalPaid > 0 && $orderTotal > 0 && $totalPaid < ($orderTotal - 0.01)) {
            \PrestaShopLogger::addLog(
                '[MP Notification] Skipping order creation: paid ' . $totalPaid . ' < total ' . $orderTotal,
                2, null, 'MPNotification', 0, true
            );
            return 0;
        }

        $newStateId = $this->mapStatusToPSState($status);
        if ($newStateId <= 0) {
            $newStateId = (int) \Configuration::get('PS_OS_PREPARATION');
        }

        $paidAmount = $totalPaid > 0 ? $totalPaid : (float) $cart->getOrderTotal();
        $customer = new \Customer((int) $cart->id_customer);
        $module = \Module::getInstanceByName('mercadopago');

        try {
            $module->validateOrder(
                $cartId,
                $newStateId,
                $paidAmount,
                $module->displayName,
                null,
                [],
                (int) $cart->id_currency,
                false,
                $customer->secure_key
            );
        } catch (\Throwable $th) {
            \PrestaShopLogger::addLog(
                '[MP Notification] validateOrder failed for cart ' . $cartId . ': ' . $th->getMessage(),
                3, null, 'MPNotification', 0, true
            );
            return 0;
        }

        $psOrderId = (int) \Order::getIdByCartId($cartId);

        if ($psOrderId > 0) {
            $this->repository->updateMerchantOrderIdByCartId($cartId, $psOrderId);
            \PrestaShopLogger::addLog(
                '[MP Notification] Order ' . $psOrderId . ' created from notification for cart ' . $cartId,
                1, null, 'Order', $psOrderId, true
            );
        }

        return $psOrderId;
    }

    public function syncOrderPayments(int $psOrderId, array $payments): void
    {
        if ($psOrderId <= 0 || empty($payments)) {
            return;
        }

        $order = new \Order($psOrderId);
        if (!$order->id) {
            return;
        }

        $orderPayments = $order->getOrderPaymentCollection();

        if (count($payments) === 1) {
            $payment = $payments[0];
            $paymentId = (string) ($payment['id'] ?? '');
            if (!empty($orderPayments[0]) && $paymentId !== '') {
                $orderPayments[0]->transaction_id = $paymentId;
                $orderPayments[0]->amount = (float) ($payment['transaction_amount'] ?? $orderPayments[0]->amount);
                $orderPayments[0]->update();
            }
            return;
        }

        \Db::getInstance()->execute('START TRANSACTION');
        try {
            foreach ($orderPayments as $existingPayment) {
                $existingPayment->delete();
            }
            foreach ($payments as $payment) {
                $paymentId = (string) ($payment['id'] ?? '');
                if ($paymentId === '') {
                    continue;
                }
                $newPayment = new \OrderPayment();
                $newPayment->order_reference = $order->reference;
                $newPayment->id_currency = $order->id_currency;
                $newPayment->amount = (float) ($payment['transaction_amount'] ?? 0);
                $newPayment->payment_method = 'Mercado Pago';
                $newPayment->transaction_id = $paymentId;
                $newPayment->add();
            }
            \Db::getInstance()->execute('COMMIT');
        } catch (\Throwable $th) {
            \Db::getInstance()->execute('ROLLBACK');
            throw $th;
        }
    }

    public function resolveAggregatedStatus(array $payments, float $orderTotal): string
    {
        $processedTotal = 0.0;
        $pendingTotal = 0.0;
        $lastStatus = '';

        foreach ($payments as $payment) {
            $status = $this->normalizeStatus((string) ($payment['status'] ?? ''));
            $lastStatus = $status ?: $lastStatus;
            $txAmount = (float) ($payment['transaction_details']['total_paid_amount'] ?? 0);
            $coupon = (float) ($payment['coupon_amount'] ?? 0);

            if ($status === 'processed') {
                $processedTotal += $txAmount + $coupon;
            } elseif (in_array($status, ['processing', 'action_required', 'in_review'], true)) {
                $pendingTotal += (float) ($payment['transaction_amount'] ?? 0);
            }
        }

        if ($orderTotal > 0 && $processedTotal >= $orderTotal) {
            return 'processed';
        }

        if ($orderTotal > 0 && $pendingTotal >= $orderTotal) {
            return 'processing';
        }

        return $lastStatus;
    }

    public function resolveAggregatedAmount(array $payments): float
    {
        $total = 0.0;
        foreach ($payments as $payment) {
            $status = $this->normalizeStatus((string) ($payment['status'] ?? ''));
            if ($status === 'processed') {
                $total += (float) ($payment['transaction_details']['total_paid_amount'] ?? 0);
                $total += (float) ($payment['coupon_amount'] ?? 0);
            } elseif (in_array($status, ['processing', 'action_required'], true)) {
                $total += (float) ($payment['transaction_amount'] ?? 0);
            }
        }
        return $total;
    }

    private function isCurrentStateAllowed(int $currentStateId): bool
    {
        if ($currentStateId <= 0) {
            return true;
        }

        $state = \Db::getInstance()->getRow(
            'SELECT `module_name` FROM `' . _DB_PREFIX_ . 'order_state`'
            . ' WHERE `id_order_state` = ' . $currentStateId,
            false
        );

        if ($state && $state['module_name'] === 'mercadopago') {
            return true;
        }

        $backOrder = \Db::getInstance()->getRow(
            'SELECT `id_order_state` FROM `' . _DB_PREFIX_ . 'order_state_lang`'
            . ' WHERE `template` = \'outofstock\''
            . ' AND `id_order_state` = ' . $currentStateId,
            false
        );

        return !empty($backOrder);
    }
}
