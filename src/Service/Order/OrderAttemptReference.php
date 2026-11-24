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

namespace MercadoPago\Service\Order;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Identifies the exact mp_transactions row that created a PrestaShop order.
 */
final class OrderAttemptReference
{
    private const PREFIX = 'MP-';
    private const HASH_LENGTH = 24;

    /** Length of every attempt reference; the core order-reference columns must hold it. */
    public static function length(): int
    {
        return strlen(self::PREFIX) + self::HASH_LENGTH;
    }

    public static function fromAttempt(int $transactionId, int $cartId): string
    {
        if ($transactionId <= 0 || $cartId <= 0) {
            throw new \InvalidArgumentException('Transaction and cart ids must be positive');
        }

        if (!defined('_COOKIE_KEY_') || trim((string) _COOKIE_KEY_) === '') {
            throw new \RuntimeException('PrestaShop cookie key is unavailable');
        }

        $attemptDigest = hash_hmac(
            'sha256',
            $cartId . ':' . $transactionId,
            (string) _COOKIE_KEY_
        );

        return self::PREFIX . strtoupper(substr($attemptDigest, 0, self::HASH_LENGTH));
    }

    public static function matches(\Order $order, int $transactionId, int $cartId): bool
    {
        if ($order->id <= 0 || $transactionId <= 0 || $cartId <= 0) {
            return false;
        }

        return hash_equals(
            self::fromAttempt($transactionId, $cartId),
            (string) $order->reference
        );
    }
}
