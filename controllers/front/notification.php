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

if (!defined('_PS_VERSION_')) {
    exit;
}

use MercadoPago\Service\Notification\Exception\NotificationNotFoundException;
use MercadoPago\Service\Notification\Exception\NotificationProcessingException;
use MercadoPago\Service\Notification\Exception\NotificationSameStateException;
use MercadoPago\Service\Notification\Exception\NotificationSkippedException;
use MercadoPago\Service\Notification\PaymentNotificationService;

class MercadoPagoNotificationModuleFrontController extends ModuleFrontController
{
    public $ajax = true;

    public function postProcess()
    {
        $rawBody = (string) file_get_contents('php://input');
        $notification = [];
        if ($rawBody !== '') {
            $notification = json_decode($rawBody, true) ?? [];
        }

        $service = new PaymentNotificationService();

        if (($notification['transaction_type'] ?? '') === 'pp_order') {
            try {
                $service->processOrderNotification($notification);
                $this->respond(201);
            } catch (NotificationSameStateException $e) {
                $this->respond(202);
            } catch (NotificationSkippedException $e) {
                $this->respond(202);
            } catch (NotificationNotFoundException $e) {
                \PrestaShopLogger::addLog('[MP Notification] Not found: ' . $e->getMessage(), 2, null, 'MPNotification', 0, true);
                $this->respond(404);
            } catch (NotificationProcessingException $e) {
                \PrestaShopLogger::addLog('[MP Notification] Error: ' . $e->getMessage(), 3, null, 'MPNotification', 0, true);
                $this->respond(422);
            } catch (\Exception $e) {
                \PrestaShopLogger::addLog('[MP Notification] Unexpected: ' . $e->getMessage(), 3, null, 'MPNotification', 0, true);
                $this->respond(422);
            }
        }

        [$notificationId, $topic] = $this->resolveNotificationParams($notification);

        if (!$notificationId || !in_array($topic, ['payment', 'merchant_order'], true)) {
            $this->respond(200);
        }

        $secureKey = (string) \Tools::getValue('customer');

        if ($secureKey !== '' && !$service->isActiveCustomerKey($secureKey)) {
            \PrestaShopLogger::addLog(
                '[MP Notification] Invalid or missing customer key',
                2, null, 'MPNotification', 0, true
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
            $this->respond(202);
        } catch (NotificationSkippedException $e) {
            $this->respond(202);
        } catch (NotificationNotFoundException $e) {
            \PrestaShopLogger::addLog(
                '[MP Notification] Not found ' . $notificationId . ': ' . $e->getMessage(),
                2, null, 'MPNotification', 0, true
            );
            $this->respond(404);
        } catch (NotificationProcessingException $e) {
            \PrestaShopLogger::addLog(
                '[MP Notification] Processing error for ' . $notificationId . ': ' . $e->getMessage(),
                3, null, 'MPNotification', 0, true
            );
            $this->respond(422);
        } catch (\Exception $e) {
            \PrestaShopLogger::addLog(
                '[MP Notification] Unexpected error for ' . $notificationId . ': ' . $e->getMessage(),
                3, null, 'MPNotification', 0, true
            );
            $this->respond(422);
        }
    }

    private function processMerchantOrderNotification(
        PaymentNotificationService $service,
        string $merchantOrderId,
        string $secureKey
    ): void {
        $mpApi = new \MercadoPago\Client\MPApi();
        $result = $mpApi->getMerchantOrder($merchantOrderId);
        $httpStatus = $result['status'] ?? 0;
        $merchantOrder = $result['response'] ?? [];

        if ($httpStatus < 200 || $httpStatus >= 300) {
            \PrestaShopLogger::addLog(
                '[MP Notification] Could not fetch merchant order ' . $merchantOrderId
                . ' — HTTP ' . $httpStatus,
                2,
                null,
                'MPNotification',
                0,
                true
            );
            throw new NotificationProcessingException(
                'Could not fetch merchant order ' . $merchantOrderId . ' — HTTP ' . $httpStatus
            );
        }

        if (empty($merchantOrder['payments'])) {
            return;
        }

        $cartId = (int) ($merchantOrder['external_reference'] ?? 0);
        $orderTotal = 0.0;
        $cart = null;
        if ($cartId > 0) {
            $cart = new \Cart($cartId);
            if ($cart->id) {
                $orderTotal = (float) $cart->getOrderTotal(true, \Cart::BOTH);
            }
        }

        $aggregatedStatus = $service->resolveAggregatedStatus($merchantOrder['payments'], $orderTotal);
        $aggregatedAmount = $service->resolveAggregatedAmount($merchantOrder['payments']);

        $primaryPaymentId = '';
        $fallbackPaymentId = '';
        foreach ($merchantOrder['payments'] as $payment) {
            $pid = (string) ($payment['id'] ?? '');
            if ($pid === '') {
                continue;
            }
            if ($fallbackPaymentId === '') {
                $fallbackPaymentId = $pid;
            }
            if (($payment['status'] ?? '') === 'approved') {
                $primaryPaymentId = $pid;
                break;
            }
        }

        $paymentIdToProcess = $primaryPaymentId !== '' ? $primaryPaymentId : $fallbackPaymentId;

        if ($paymentIdToProcess !== '') {
            $service->processPaymentNotificationWithAggregate(
                $paymentIdToProcess,
                $secureKey,
                $aggregatedStatus,
                $aggregatedAmount,
                $cartId
            );
        }

        $psOrderId = (int) \Order::getIdByCartId($cartId);
        $service->syncOrderPayments($psOrderId, $merchantOrder['payments']);
    }

    private function resolveNotificationParams(array $parsedBody = []): array
    {
        $notificationId = (string) \Tools::getValue('id');
        $topic = (string) \Tools::getValue('topic') ?: (string) \Tools::getValue('type');

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
}
