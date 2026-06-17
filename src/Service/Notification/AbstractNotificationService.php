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

if (!defined('_PS_VERSION_')) {
    exit;
}

use MercadoPago\Client\MPApi;
use MercadoPago\Repository\MPTransactionRepository;

abstract class AbstractNotificationService
{
    protected MPApi $mpApi;
    protected MPTransactionRepository $repository;

    public function __construct(?MPApi $mpApi = null, ?MPTransactionRepository $repository = null)
    {
        $this->mpApi = $mpApi ?? new MPApi();
        $this->repository = $repository ?? new MPTransactionRepository();
    }

    /**
     * Process a payment notification from Mercado Pago.
     *
     * @param string $paymentId MP payment ID received in the notification
     * @return bool true if the order state was successfully updated
     */
    abstract public function processPaymentNotification(string $paymentId, string $secureKey = ''): void;

    /**
     * Verify that the secure_key belongs to an active PrestaShop customer.
     * Used by the notification controller as a fast pre-validation before
     * touching the MP API. Ownership (key ↔ order/cart) is verified later inside
     * processPaymentNotification via isTransactionOwner().
     *
     * @param string $key value of the `customer` URL parameter
     * @return bool
     */
    public function isActiveCustomerKey(string $key): bool
    {
        if ($key === '') {
            return false;
        }

        return (bool) \Db::getInstance()->getValue(
            'SELECT `id_customer` FROM `' . _DB_PREFIX_ . 'customer`'
            . ' WHERE `secure_key` = \'' . pSQL($key) . '\''
            . ' AND `active` = 1',
            false
        );
    }
}
