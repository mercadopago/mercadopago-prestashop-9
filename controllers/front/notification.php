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

/*
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

if (!defined('_PS_VERSION_')) {
    exit;
}

use MercadoPago\Service\Notification\Exception\NotificationNotFoundException;
use MercadoPago\Service\Notification\Exception\NotificationProcessingException;
use MercadoPago\Service\Notification\Exception\NotificationRetryableException;
use MercadoPago\Service\Notification\Exception\NotificationSameStateException;
use MercadoPago\Service\Notification\Exception\NotificationSkippedException;
use MercadoPago\Service\Notification\PaymentNotificationService;
use MercadoPago\Service\Notification\PaymentNotificationServiceFactory;

class MercadoPagoNotificationModuleFrontController extends ModuleFrontController
{
    private const MAX_NOTIFICATION_BODY_BYTES = 1048576;

    public $ajax = true;

    public function postProcess()
    {
        if ($this->hasOversizedContentLength()) {
            $this->logOversizedBody();
            $this->respond(413);
        }

        // Cap the stream read as well: Transfer-Encoding: chunked requests do
        // not provide CONTENT_LENGTH, so they must not bypass the memory bound.
        $rawBody = (string) file_get_contents('php://input', false, null, 0, self::MAX_NOTIFICATION_BODY_BYTES + 1);
        $notification = [];
        if ($rawBody !== '') {
            if (strlen($rawBody) > self::MAX_NOTIFICATION_BODY_BYTES) {
                $this->logOversizedBody();
                $this->respond(413);
            }

            $notification = json_decode($rawBody, true);
            if (!is_array($notification)) {
                PrestaShopLogger::addLog(
                    '[MP Notification] Invalid JSON body',
                    2,
                    null,
                    'MPNotification',
                    0,
                    true
                );
                $this->respond(400);
            }
        }

        $service = PaymentNotificationServiceFactory::create($this->context);

        if (($notification['transaction_type'] ?? '') === 'pp_order') {
            $secureKey = (string) Tools::getValue('customer');
            if ($secureKey === '' || !$service->isActiveCustomerKey($secureKey)) {
                PrestaShopLogger::addLog(
                    '[MP Notification] Unauthenticated pp_order notification',
                    2,
                    null,
                    'MPNotification',
                    0,
                    true
                );
                $this->respond(401);
            }

            $this->respond($this->processPPOrderNotification($service, $notification, $secureKey));
        }

        [$notificationId, $topic] = $this->resolveNotificationParams($notification);

        if (!$notificationId || !in_array($topic, ['payment', 'merchant_order'], true)) {
            $this->respond(200);
        }

        $secureKey = (string) Tools::getValue('customer');

        if ($secureKey !== '' && !$service->isActiveCustomerKey($secureKey)) {
            PrestaShopLogger::addLog(
                '[MP Notification] Invalid or missing customer key',
                2,
                null,
                'MPNotification',
                0,
                true
            );
            $this->respond(422);
        }

        try {
            if ($topic === 'payment') {
                $service->processPaymentNotification($notificationId, $secureKey);
            } elseif ($topic === 'merchant_order') {
                $this->processMerchantOrderNotification($service, $notificationId, $secureKey);
            }
            $this->respond(201);
        } catch (NotificationSameStateException $e) {
            $this->logSkippedNotification('already_applied', $topic);
            $this->respond(202);
        } catch (NotificationSkippedException $e) {
            $this->logSkippedNotification($e->getReason(), $topic);
            $this->respond(202);
        } catch (NotificationNotFoundException $e) {
            PrestaShopLogger::addLog(
                '[MP Notification] Not found ' . $notificationId . ': ' . $e->getMessage(),
                2,
                null,
                'MPNotification',
                0,
                true
            );
            $this->respond(404);
        } catch (NotificationRetryableException $e) {
            // The underlying cause is logged by the service after its rollback. Payment activity
            // identifiers never reach this log: no notification id, and digits are masked (PPSP-1604).
            PrestaShopLogger::addLog(
                '[MP Notification] Retryable processing error: '
                . mb_substr((string) preg_replace(['/[\x00-\x1F\x7F]+/u', '/\d+/'], [' ', '#'], $e->getMessage()), 0, 300),
                2,
                null,
                'MPNotification',
                0,
                true
            );
            $this->respond(503);
        } catch (NotificationProcessingException $e) {
            PrestaShopLogger::addLog(
                '[MP Notification] Processing error for ' . $notificationId . ': ' . $e->getMessage(),
                3,
                null,
                'MPNotification',
                0,
                true
            );
            $this->respond(422);
        } catch (Exception $e) {
            PrestaShopLogger::addLog(
                '[MP Notification] Unexpected error for ' . $notificationId . ': ' . $e->getMessage(),
                3,
                null,
                'MPNotification',
                0,
                true
            );
            $this->respond(422);
        }
    }

    protected function processPPOrderNotification(
        PaymentNotificationService $service,
        array $notification,
        string $secureKey,
    ): int {
        try {
            $service->processOrderNotification($notification, $secureKey);

            return 201;
        } catch (NotificationSameStateException $e) {
            $this->logSkippedPPOrder('already_applied');

            return 202;
        } catch (NotificationSkippedException $e) {
            $this->logSkippedPPOrder($e->getReason());

            return 202;
        } catch (NotificationNotFoundException $e) {
            PrestaShopLogger::addLog(
                '[MP Notification] pp_order transaction not found',
                2,
                null,
                'MPNotification',
                0,
                true
            );

            return 404;
        } catch (NotificationRetryableException $e) {
            PrestaShopLogger::addLog(
                '[MP Notification] pp_order retryable processing error',
                2,
                null,
                'MPNotification',
                0,
                true
            );

            return 503;
        } catch (NotificationProcessingException $e) {
            PrestaShopLogger::addLog(
                '[MP Notification] pp_order rejected',
                3,
                null,
                'MPNotification',
                0,
                true
            );

            return 422;
        } catch (Throwable $th) {
            PrestaShopLogger::addLog(
                '[MP Notification] Unexpected pp_order processing error',
                3,
                null,
                'MPNotification',
                0,
                true
            );

            return 503;
        }
    }

    private function logSkippedPPOrder(string $reason): void
    {
        PrestaShopLogger::addLog(
            '[MP Notification] pp_order skipped reason=' . $reason,
            2,
            null,
            'MPNotification',
            0,
            true
        );
    }

    private function logSkippedNotification(string $reason, string $topic): void
    {
        PrestaShopLogger::addLog(
            '[MP Notification] notification skipped reason=' . $reason
            . ' topic=' . $topic,
            2,
            null,
            'MPNotification',
            0,
            true
        );
    }

    private function processMerchantOrderNotification(
        PaymentNotificationService $service,
        string $merchantOrderId,
        string $secureKey,
    ): void {
        $mpApi = $this->getMerchantOrderApi();
        $result = $mpApi->getMerchantOrder($merchantOrderId);
        $httpStatus = $result['status'] ?? 0;
        $merchantOrder = $result['response'] ?? [];

        if ($httpStatus < 200 || $httpStatus >= 300) {
            PrestaShopLogger::addLog(
                '[MP Notification] Could not fetch merchant order ' . $merchantOrderId
                . ' — HTTP ' . $httpStatus,
                2,
                null,
                'MPNotification',
                0,
                true
            );
            throw new NotificationProcessingException('Could not fetch merchant order ' . $merchantOrderId . ' — HTTP ' . $httpStatus);
        }

        $merchantOrderPayments = $merchantOrder['payments'] ?? null;
        if (!is_array($merchantOrderPayments) || $merchantOrderPayments === []) {
            return;
        }

        $externalReference = $merchantOrder['external_reference'] ?? null;
        $externalReference = is_int($externalReference) ? (string) $externalReference : $externalReference;
        if (!is_string($externalReference)
            || preg_match('/^[1-9][0-9]{0,9}$/D', $externalReference) !== 1
        ) {
            throw new NotificationProcessingException('Invalid merchant order external reference');
        }
        $cartId = (int) $externalReference;
        $orderTotal = 0.0;
        $cart = null;
        if ($cartId > 0) {
            $cart = new Cart($cartId);
            if ($cart->id) {
                $orderTotal = (float) $cart->getOrderTotal(true, Cart::BOTH);
            }
        }

        $primaryPaymentId = '';
        $fallbackPaymentId = '';
        $merchantOrderPaymentIds = [];
        $synchronizedPayments = [];
        foreach ($merchantOrderPayments as $payment) {
            if (!is_array($payment)) {
                continue;
            }
            $pid = $payment['id'] ?? null;
            $pid = is_int($pid) ? (string) $pid : $pid;
            if (!is_string($pid)
                || preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $pid) !== 1
                || isset($synchronizedPayments[$pid])
            ) {
                continue;
            }
            $payment['id'] = $pid;
            $synchronizedPayments[$pid] = $payment;
            $merchantOrderPaymentIds[] = $pid;
            if ($fallbackPaymentId === '') {
                $fallbackPaymentId = $pid;
            }
            if ($primaryPaymentId === '' && ($payment['status'] ?? '') === 'approved') {
                $primaryPaymentId = $pid;
            }
        }

        $synchronizedPayments = array_values($synchronizedPayments);
        if ($synchronizedPayments === []) {
            return;
        }

        $aggregatedStatus = $service->resolveAggregatedStatus($synchronizedPayments, $orderTotal);
        $aggregatedAmount = $service->resolveAggregatedAmountForStatus($synchronizedPayments, $aggregatedStatus);

        $paymentIdToProcess = $primaryPaymentId !== '' ? $primaryPaymentId : $fallbackPaymentId;

        if ($paymentIdToProcess !== '') {
            $service->processPaymentNotificationWithAggregate(
                $paymentIdToProcess,
                $secureKey,
                $aggregatedStatus,
                $aggregatedAmount,
                $cartId,
                $merchantOrderPaymentIds,
                $synchronizedPayments
            );
        }
    }

    protected function getMerchantOrderApi(): MercadoPago\Client\MPApi
    {
        return new MercadoPago\Client\MPApi();
    }

    private function resolveNotificationParams(array $parsedBody = []): array
    {
        $notificationId = (string) Tools::getValue('id');
        $topic = (string) Tools::getValue('topic') ?: (string) Tools::getValue('type');

        if ($notificationId === '' && !empty($parsedBody['data']['id'])) {
            $notificationId = (string) $parsedBody['data']['id'];
        }

        if ($topic === '' && !empty($parsedBody['type'])) {
            $topic = (string) $parsedBody['type'];
        }

        return [$notificationId !== '' ? $notificationId : null, $topic !== '' ? $topic : null];
    }

    private function respond(int $code): never
    {
        http_response_code($code);
        exit;
    }

    private function hasOversizedContentLength(): bool
    {
        $contentLength = $_SERVER['CONTENT_LENGTH'] ?? null;
        if (!is_string($contentLength) || preg_match('/^[0-9]+$/D', $contentLength) !== 1) {
            return false;
        }

        $contentLength = ltrim($contentLength, '0');
        $maximumLength = (string) self::MAX_NOTIFICATION_BODY_BYTES;

        return $contentLength !== ''
            && (strlen($contentLength) > strlen($maximumLength)
                || (strlen($contentLength) === strlen($maximumLength) && strcmp($contentLength, $maximumLength) > 0));
    }

    private function logOversizedBody(): void
    {
        PrestaShopLogger::addLog(
            '[MP Notification] Request body exceeds the accepted size',
            2,
            null,
            'MPNotification',
            0,
            true
        );
    }
}
