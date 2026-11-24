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
if (!defined('_PS_VERSION_')) {
    exit;
}

use MercadoPago\Repository\PreferenceGuardRepository;
use MercadoPago\Service\Order\AbstractOrderService;
use MercadoPago\Service\Order\Exception\FinancialCartMismatchException;
use MercadoPago\Service\Order\OrderAttemptReference;

abstract class AbstractMercadopagoModuleFrontController extends ModuleFrontController
{
    /** Window in which an approved Checkout Pro payment without order blocks a new payment for the cart. */
    protected const PAYMENT_RECEIVED_WINDOW_HOURS = 48;

    protected const CHECKOUT_FAILURE_URL = 'index.php?controller=order&step=3&typeReturn=failure';

    /**
     * Approved Checkout Pro attempt of a cart that still has no order, or null.
     * A failure to read keeps the previous behaviour (no block, no received page).
     */
    protected function approvedPaymentAwaitingOrder(int $cartId): ?array
    {
        if ($cartId <= 0 || (int) Order::getIdByCartId($cartId) > 0) {
            return null;
        }
        try {
            return $this->createPreferenceGuardRepository()->findApprovedAwaitingOrder(
                $cartId,
                self::PAYMENT_RECEIVED_WINDOW_HOURS
            );
        } catch (Throwable $e) {
            // No cart or payment identifiers in the log (PPSP-1604).
            PrestaShopLogger::addLog(
                '[MercadoPago] Could not read approved payments awaiting order (' . get_class($e) . ')',
                2,
                null,
                'MPNotification',
                0,
                true
            );

            return null;
        }
    }

    protected function createPreferenceGuardRepository(): PreferenceGuardRepository
    {
        return new PreferenceGuardRepository();
    }

    /** Module URL that renders the "payment received" page for the cart. */
    protected function paymentReceivedUrl(string $controller, int $cartId): string
    {
        return __PS_BASE_URI__ . 'index.php?fc=module&module=mercadopago&controller=' . rawurlencode($controller)
            . '&typeReturn=received&cart_id=' . $cartId;
    }

    /**
     * Render the "payment received" page, reusing the theme's page layout.
     * Only the cart owner sees it; once the notification creates the order the
     * buyer is sent to the native order confirmation instead.
     */
    protected function renderPaymentReceived(string $checkoutType): void
    {
        $cartId = (int) Tools::getValue('cart_id');
        $cart = $cartId > 0 ? new Cart($cartId) : null;
        if (!$cart || !$cart->id || (int) $cart->id_customer !== (int) $this->context->customer->id) {
            Tools::redirect(self::CHECKOUT_FAILURE_URL);

            return;
        }

        $orderId = (int) Order::getIdByCartId($cartId);
        if ($orderId > 0) {
            $order = new Order($orderId);
            Tools::redirect(
                __PS_BASE_URI__ . 'index.php?controller=order-confirmation'
                . '&id_cart=' . $cartId
                . '&key=' . rawurlencode((string) $order->secure_key)
                . '&id_order=' . $orderId
                . '&id_module=' . (int) $this->module->id
                . '&checkout_type=' . rawurlencode($checkoutType)
            );

            return;
        }

        $attempt = $this->approvedPaymentAwaitingOrder($cartId);
        if ($attempt === null) {
            Tools::redirect(self::CHECKOUT_FAILURE_URL);

            return;
        }

        $currency = new Currency((int) $cart->id_currency);
        $amount = (float) ($attempt['total'] ?? 0);
        $formattedAmount = $amount > 0 && isset($this->context->currentLocale)
            ? $this->context->currentLocale->formatPrice($amount, (string) $currency->iso_code)
            : '';

        parent::initContent();
        // Plain values only: the template escapes each one (no pre-rendered HTML).
        $this->context->smarty->assign(['mp_received' => [
            'title' => $this->module->l('We received your payment'),
            'message' => $this->module->l('Your order is being processed. You will receive the confirmation by e-mail, so there is no need to pay again.'),
            'help' => $this->module->l('If you do not receive the confirmation, please contact the store.'),
            'amount_label' => $this->module->l('Amount'),
            'amount' => $formattedAmount,
            'back_label' => $this->module->l('Back to store'),
            'back_url' => __PS_BASE_URI__,
        ]]);
        $this->setTemplate('module:mercadopago/views/templates/front/payment-received.tpl');
    }

    /**
     * Persist the MP transaction and create the PrestaShop order while holding the same
     * cart-scoped lock used by webhook processing.
     */
    protected function persistTransactionAndCreateOrder(
        AbstractOrderService $orderService,
        Cart $cart,
        array $mpOrderResponse,
        ?int $orderState = null,
    ): Order {
        return $orderService->withOrderCreationLock(
            (int) $cart->id,
            function () use ($orderService, $cart, $mpOrderResponse, $orderState): Order {
                $orderResponseForPersistence = $mpOrderResponse;
                $orderResponseForPersistence['total_amount'] = $orderService
                    ->getCapturedTransactionAmount($cart);
                $transactionId = $orderService->saveCreateOrderData(
                    $cart,
                    $orderResponseForPersistence
                );
                if ($transactionId <= 0) {
                    throw new RuntimeException('Could not persist checkout transaction');
                }

                $orderCreationStarted = false;
                $newOrderCreationGuarded = false;
                try {
                    // The persisted request amount may be reused for a new
                    // attempt, but an idempotent API replay must prove that its
                    // own canonical amount still matches that captured request
                    // before any ownership reservation or local order creation.
                    $orderService->assertCapturedOrderResponseMatches(
                        $cart,
                        $mpOrderResponse
                    );

                    // Persist the exact reservation before the local financial
                    // transaction. If a transient pre-order failure rolls back,
                    // webhooks must wait for checkout retry instead of creating
                    // a full-price order from the now-neutral cart.
                    if (!$orderService->reserveTransactionOrderCreationForAttempt(
                        (int) $cart->id,
                        $transactionId
                    )) {
                        throw new RuntimeException('Could not reserve checkout order ownership');
                    }

                    // validateOrder() can emit hooks and e-mails before it
                    // returns. Persist the manual-reconciliation marker in
                    // autocommit mode, before START TRANSACTION, so a process
                    // death cannot roll it back together with the order and
                    // then repeat those side effects on retry.
                    if ((int) Order::getIdByCartId((int) $cart->id) <= 0) {
                        if (!$orderService->markTransactionOrderCreationUncertainForAttempt(
                            (int) $cart->id,
                            $transactionId
                        )) {
                            throw new RuntimeException('Could not guard checkout order creation');
                        }
                        $newOrderCreationGuarded = true;
                    }

                    return $orderService->withFinancialStateTransaction(
                        $cart,
                        function (Cart $lockedCart) use (
                            &$orderCreationStarted,
                            $newOrderCreationGuarded,
                            $orderService,
                            $mpOrderResponse,
                            $orderResponseForPersistence,
                            $orderState,
                            $transactionId
                        ): Order {
                            $orderService->prepareCapturedCartRuleForOrder(
                                $lockedCart,
                                $mpOrderResponse
                            );

                            $needsNewOrder = (int) Order::getIdByCartId(
                                (int) $lockedCart->id
                            ) <= 0;
                            if ($needsNewOrder && !$newOrderCreationGuarded) {
                                throw new RuntimeException('Checkout order presence changed before validation');
                            }
                            $orderCreationStarted = $needsNewOrder;
                            $order = $this->getOrCreateOrder(
                                $lockedCart,
                                $orderResponseForPersistence,
                                $orderState,
                                $transactionId
                            );
                            $orderService->assertCapturedOrderMatches(
                                $lockedCart,
                                $order
                            );
                            if (!$orderService->updateTransactionOrderIdForAttempt(
                                (int) $order->id,
                                (int) $lockedCart->id,
                                $transactionId
                            )) {
                                throw new RuntimeException('Could not link checkout order');
                            }

                            $orderService->finalizeCapturedCartRuleForOrder($lockedCart);

                            return $order;
                        }
                    );
                } catch (FinancialCartMismatchException $exception) {
                    // Once validateOrder() started, preserve the uncertain
                    // marker because hooks/e-mails may already have escaped.
                    // A mismatch detected before that point is safe to classify
                    // as an invalid financial snapshot. An exact-attempt order
                    // may already exist after an interrupted webhook link; keep
                    // its reservation so notification retry can finish linking.
                    $existingOrderId = (int) Order::getIdByCartId((int) $cart->id);
                    $hasExactExistingOrder = false;
                    if ($existingOrderId > 0) {
                        $existingOrder = new Order($existingOrderId);
                        $hasExactExistingOrder = (bool) $existingOrder->id
                            && OrderAttemptReference::matches(
                                $existingOrder,
                                $transactionId,
                                (int) $cart->id
                            );
                    }

                    // The transaction callback updates this flag by reference.
                    // @phpstan-ignore-next-line
                    if (!$orderCreationStarted && !$hasExactExistingOrder) {
                        if (!$orderService->invalidateTransactionOrderCreationForAttempt(
                            (int) $cart->id,
                            $transactionId
                        )) {
                            throw new RuntimeException('Could not invalidate mismatched checkout attempt', 0, $exception);
                        }
                    }

                    throw $exception;
                } catch (Throwable $exception) {
                    // The transaction callback updates this flag by reference.
                    // @phpstan-ignore-next-line
                    if ($orderCreationStarted) {
                        if (!$newOrderCreationGuarded) {
                            try {
                                $orderService->markTransactionOrderCreationUncertainForAttempt(
                                    (int) $cart->id,
                                    $transactionId
                                );
                            } catch (Throwable) {
                                PrestaShopLogger::addLog(
                                    'Unable to mark an uncertain Mercado Pago order creation attempt',
                                    3
                                );
                            }
                        }
                    } elseif ($newOrderCreationGuarded) {
                        try {
                            $orderService->restoreTransactionOrderCreationReservationForAttempt(
                                (int) $cart->id,
                                $transactionId
                            );
                        } catch (Throwable) {
                            PrestaShopLogger::addLog(
                                'Unable to restore a retryable Mercado Pago order creation attempt',
                                3
                            );
                        }
                    }

                    throw $exception;
                }
            }
        );
    }

    /**
     * Returns the PrestaShop order for the cart, creating it via validateOrder if it does not exist yet.
     *
     * By default the order is created with PS_OS_PREPARATION (pending) since payment confirmation
     * is asynchronous via webhook. Subclasses with synchronous status (e.g. Yape) can pass an
     * explicit state to override this default.
     *
     * @param Cart $cart
     * @param array $mpOrderResponse
     * @param int|null $orderState initial PS order state; defaults to PS_OS_PREPARATION when null
     *
     * @return Order
     *
     * @throws Exception
     */
    protected function getOrCreateOrder(
        Cart $cart,
        array $mpOrderResponse,
        ?int $orderState = null,
        int $transactionId = 0,
    ): Order {
        $orderId = (int) Order::getIdByCartId((int) $cart->id);

        if ($orderId <= 0) {
            $pendingState = (int) Configuration::get('MERCADOPAGO_STATUS_7');
            $resolvedState = $orderState ?? ($pendingState > 0 ? $pendingState : (int) Configuration::get('PS_OS_PREPARATION'));
            $amount = (float) ($mpOrderResponse['total_amount'] ?? $cart->getOrderTotal());
            $customer = new Customer((int) $cart->id_customer);

            if (!method_exists($this->module, 'validateOrder')) {
                throw new RuntimeException('Mercado Pago module cannot validate the order');
            }

            $this->module->validateOrder(
                (int) $cart->id,
                $resolvedState,
                $amount,
                $this->module->displayName,
                null,
                [],
                (int) $cart->id_currency,
                false,
                $customer->secure_key,
                null,
                OrderAttemptReference::fromAttempt($transactionId, (int) $cart->id)
            );

            $orderId = (int) Order::getIdByCartId((int) $cart->id);
        }

        if ($orderId <= 0) {
            throw new Exception('Order could not be created');
        }

        return new Order($orderId);
    }
}
