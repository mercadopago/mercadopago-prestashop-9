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

namespace MercadoPago\Repository;

if (!defined('_PS_VERSION_')) {
    exit;
}

class MPTransactionRepository
{
    private const NOTIFICATION_TRANSACTION_COLUMNS = '`id_mp_transaction`, `cart_id`, `order_id`, '
        . '`merchant_order_id`, `payment_status`, `payment_id`, `guard_preference_id`, '
        . '`guard_status`, `guard_reason`, `guard_payment_id`, `guard_order_id`';

    private const TABLE_TRANSACTIONS = 'mp_transactions';
    private const TABLE_MODULE = 'mp_module';
    private const ORDER_CREATION_RESERVATION_PREFIX = 'pending:';
    public const WEBHOOK_ORDER_CREATION_RESERVATION_PREFIX = 'webhook-pending:';
    public const ORDER_CREATION_INVALID_PREFIX = 'invalid-cart:';
    public const ORDER_CREATION_UNCERTAIN_PREFIX = 'uncertain-order:';
    private const INTEGER_FIELDS = ['cart_id', 'customer_id', 'mp_module_id', 'is_payment_test', 'received_webhook'];

    /** @var \Db|null Primary connection that owns the cart-order transaction. */
    private $cartOrderTransactionDb;

    private bool $cartOrderTransactionActive = false;

    private bool $orderReferenceCapacityLoaded = false;

    private ?int $orderReferenceCapacity = null;

    /**
     * Start a repeatable-read transaction and lock the mutable cart rows used
     * by PaymentModule::validateOrder().
     *
     * The module's named lock serializes its own checkout/webhook flows; these
     * row locks additionally block native PrestaShop cart writes until the
     * local order either commits or rolls back.
     */
    public function beginCartOrderTransactionAndLock(int $cartId): void
    {
        if ($cartId <= 0) {
            throw new \InvalidArgumentException('A persisted cart is required for order creation');
        }
        if ($this->cartOrderTransactionActive) {
            throw new \LogicException('A cart order transaction is already active');
        }

        $db = \Db::getInstance(true);
        if ($db->execute('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ', false) !== true) {
            throw new \RuntimeException('Could not configure the cart order transaction');
        }
        if ($db->execute('START TRANSACTION', false) !== true) {
            throw new \RuntimeException('Could not start the cart order transaction');
        }

        $this->cartOrderTransactionDb = $db;
        $this->cartOrderTransactionActive = true;

        try {
            $this->lockRows(
                $db,
                'SELECT cp.`id_cart` FROM `' . _DB_PREFIX_ . 'cart_product` cp FORCE INDEX (`PRIMARY`)'
                . ' WHERE cp.`id_cart` = ' . $cartId . ' FOR UPDATE'
            );
            $this->lockRows(
                $db,
                'SELECT ccr.`id_cart` FROM `' . _DB_PREFIX_ . 'cart_cart_rule` ccr FORCE INDEX (`PRIMARY`)'
                . ' WHERE ccr.`id_cart` = ' . $cartId . ' FOR UPDATE'
            );

            $cartRows = $this->lockRows(
                $db,
                'SELECT c.`id_cart`, c.`id_address_delivery`, c.`id_address_invoice`'
                . ' FROM `' . _DB_PREFIX_ . 'cart` c'
                . ' WHERE c.`id_cart` = ' . $cartId . ' LIMIT 1 FOR UPDATE'
            );
            $cartRow = $cartRows[0] ?? null;
            if (!is_array($cartRow) || (int) ($cartRow['id_cart'] ?? 0) !== $cartId) {
                throw new \RuntimeException('Could not lock the checkout cart');
            }

            $this->lockRows(
                $db,
                'SELECT cr.`id_cart_rule` FROM `' . _DB_PREFIX_ . 'cart_rule` cr'
                . ' INNER JOIN `' . _DB_PREFIX_ . 'cart_cart_rule` ccr'
                . ' ON ccr.`id_cart_rule` = cr.`id_cart_rule`'
                . ' WHERE ccr.`id_cart` = ' . $cartId . ' FOR UPDATE'
            );

            $addressIds = array_values(array_unique(array_filter([
                (int) ($cartRow['id_address_delivery'] ?? 0),
                (int) ($cartRow['id_address_invoice'] ?? 0),
            ])));
            if ($addressIds !== []) {
                $this->lockRows(
                    $db,
                    'SELECT a.`id_address` FROM `' . _DB_PREFIX_ . 'address` a'
                    . ' WHERE a.`id_address` IN (' . implode(', ', $addressIds) . ') FOR UPDATE'
                );
            }

            $customizations = $this->lockRows(
                $db,
                'SELECT c.`id_customization` FROM `' . _DB_PREFIX_ . 'customization` c'
                . ' WHERE c.`id_cart` = ' . $cartId . ' FOR UPDATE'
            );
            $customizationIds = array_values(array_unique(array_filter(array_map(
                static fn (array $row): int => (int) ($row['id_customization'] ?? 0),
                $customizations
            ))));
            if ($customizationIds !== []) {
                $this->lockRows(
                    $db,
                    'SELECT cd.`id_customization` FROM `' . _DB_PREFIX_ . 'customized_data` cd'
                    . ' WHERE cd.`id_customization` IN (' . implode(', ', $customizationIds) . ') FOR UPDATE'
                );
            }

            $this->lockRows(
                $db,
                'SELECT o.`id_order` FROM `' . _DB_PREFIX_ . 'orders` o FORCE INDEX (`id_cart`)'
                . ' WHERE o.`id_cart` = ' . $cartId . ' FOR UPDATE'
            );
        } catch (\Throwable $exception) {
            try {
                $this->rollbackCartOrderTransaction();
            } catch (\Throwable) {
                // Preserve the lock/read failure that explains why checkout stopped.
            }

            throw $exception;
        }
    }

    public function commitCartOrderTransaction(): void
    {
        if (!$this->cartOrderTransactionActive || !$this->cartOrderTransactionDb) {
            throw new \LogicException('No cart order transaction is active');
        }
        if ($this->cartOrderTransactionDb->execute('COMMIT', false) !== true) {
            throw new \RuntimeException('Could not commit the cart order transaction');
        }

        $this->cartOrderTransactionActive = false;
        $this->cartOrderTransactionDb = null;
    }

    public function rollbackCartOrderTransaction(): void
    {
        if (!$this->cartOrderTransactionActive || !$this->cartOrderTransactionDb) {
            return;
        }

        $db = $this->cartOrderTransactionDb;
        $this->cartOrderTransactionActive = false;
        $this->cartOrderTransactionDb = null;
        if ($db->execute('ROLLBACK', false) !== true) {
            throw new \RuntimeException('Could not roll back the cart order transaction');
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function lockRows(\Db $db, string $sql): array
    {
        $rows = $db->executeS($sql, true, false);
        if (!is_array($rows)) {
            throw new \RuntimeException('Could not lock the checkout cart financial state');
        }

        return $rows;
    }

    public function getOrCreateModuleId($version)
    {
        $db = \Db::getInstance();
        $prefix = _DB_PREFIX_;

        $row = $db->getRow(
            'SELECT `id_mp_module` FROM `' . $prefix . self::TABLE_MODULE . '` WHERE `version` = \'' . pSQL((string) $version) . '\'',
            false
        );
        if ($row && isset($row['id_mp_module']) && (int) $row['id_mp_module'] > 0) {
            return (int) $row['id_mp_module'];
        }

        $lastRow = $db->getRow(
            'SELECT `id_mp_module` FROM `' . $prefix . self::TABLE_MODULE . '` ORDER BY `id_mp_module` DESC',
            false
        );
        if ($lastRow && isset($lastRow['id_mp_module']) && (int) $lastRow['id_mp_module'] > 0) {
            $db->execute(
                'UPDATE `' . $prefix . self::TABLE_MODULE . '` SET `updated` = 1 WHERE `id_mp_module` = ' . (int) $lastRow['id_mp_module'],
                false
            );
        }

        $db->execute(
            'INSERT INTO `' . $prefix . self::TABLE_MODULE . '` (`version`, `created_at`) VALUES (\'' . pSQL((string) $version) . '\', NOW())',
            false
        );
        $newId = (int) $db->Insert_ID();

        return $newId > 0 ? $newId : 1;
    }

    public function findExistingTransactionIdByOrderId($orderId)
    {
        if ($orderId === null || $orderId === '') {
            return 0;
        }

        $db = \Db::getInstance();
        $existingRow = $db->getRow(
            'SELECT `id_mp_transaction` FROM `' . _DB_PREFIX_ . self::TABLE_TRANSACTIONS . '` WHERE `order_id` = \'' . pSQL((string) $orderId) . '\' ORDER BY `id_mp_transaction` DESC',
            false
        );
        if ($existingRow === false && $db->getNumberError() !== 0) {
            throw new \RuntimeException('Could not read checkout transaction by order id');
        }

        if ($existingRow && isset($existingRow['id_mp_transaction']) && (int) $existingRow['id_mp_transaction'] > 0) {
            return (int) $existingRow['id_mp_transaction'];
        }

        return 0;
    }

    public function savePixData(int $cartId, string $qrCode, string $qrCodeBase64, string $expiration): bool
    {
        $sql = 'UPDATE `' . _DB_PREFIX_ . self::TABLE_TRANSACTIONS . '`'
            . ' SET `pix_qr_code` = \'' . pSQL($qrCode) . '\','
            . ' `pix_qr_code_base64` = \'' . pSQL($qrCodeBase64) . '\','
            . ' `pix_expiration` = \'' . pSQL($expiration) . '\''
            . ' WHERE `cart_id` = ' . (int) $cartId
            . ' ORDER BY `id_mp_transaction` DESC LIMIT 1';

        return (bool) \Db::getInstance()->execute($sql, false);
    }

    public function getPixDataByCartId(int $cartId): ?array
    {
        $row = \Db::getInstance()->getRow(
            'SELECT `pix_qr_code`, `pix_qr_code_base64`, `pix_expiration`'
            . ' FROM `' . _DB_PREFIX_ . self::TABLE_TRANSACTIONS . '`'
            . ' WHERE `cart_id` = ' . (int) $cartId
            . ' AND `pix_qr_code_base64` IS NOT NULL'
            . ' ORDER BY `id_mp_transaction` DESC',
            false
        );

        if (!$row || empty($row['pix_qr_code_base64'])) {
            return null;
        }

        return [
            'qr_code' => (string) $row['pix_qr_code'],
            'qr_code_base64' => (string) $row['pix_qr_code_base64'],
            'expiration' => (string) $row['pix_expiration'],
        ];
    }

    public function saveTicketData(int $cartId, string $ticketUrl, string $digitableLine): bool
    {
        $sql = 'UPDATE `' . _DB_PREFIX_ . self::TABLE_TRANSACTIONS . '`'
            . ' SET `ticket_url` = \'' . pSQL($ticketUrl) . '\','
            . ' `digitable_line` = \'' . pSQL($digitableLine) . '\''
            . ' WHERE `cart_id` = ' . (int) $cartId
            . ' ORDER BY `id_mp_transaction` DESC LIMIT 1';

        $db = \Db::getInstance();
        $result = (bool) $db->execute($sql, false);
        $affected = $db->Affected_Rows();

        if ($result && $affected === 0) {
            \PrestaShopLogger::addLog(
                'saveTicketData: no rows updated for cart_id ' . $cartId,
                2
            );
        }

        return $result && $affected > 0;
    }

    public function getTicketDataByCartId(int $cartId): ?array
    {
        $row = \Db::getInstance()->getRow(
            'SELECT `ticket_url`, `digitable_line`'
            . ' FROM `' . _DB_PREFIX_ . self::TABLE_TRANSACTIONS . '`'
            . ' WHERE `cart_id` = ' . (int) $cartId
            . ' AND `ticket_url` IS NOT NULL'
            . ' ORDER BY `id_mp_transaction` DESC',
            false
        );

        if (!$row || empty($row['ticket_url'])) {
            return null;
        }

        return [
            'ticket_url' => (string) $row['ticket_url'],
            'digitable_line' => (string) $row['digitable_line'],
        ];
    }

    public function savePseData(int $cartId, string $paymentUrl): bool
    {
        $sql = 'UPDATE `' . _DB_PREFIX_ . self::TABLE_TRANSACTIONS . '`'
            . ' SET `pse_payment_url` = \'' . pSQL($paymentUrl) . '\''
            . ' WHERE `cart_id` = ' . (int) $cartId
            . ' ORDER BY `id_mp_transaction` DESC LIMIT 1';

        $db = \Db::getInstance();
        $result = (bool) $db->execute($sql, false);
        $affected = $db->Affected_Rows();

        if ($result && $affected === 0) {
            \PrestaShopLogger::addLog(
                'savePseData: no rows updated for cart_id ' . $cartId,
                2
            );
        }

        return $result && $affected > 0;
    }

    public function getPseDataByCartId(int $cartId): ?array
    {
        $row = \Db::getInstance()->getRow(
            'SELECT `pse_payment_url`'
            . ' FROM `' . _DB_PREFIX_ . self::TABLE_TRANSACTIONS . '`'
            . ' WHERE `cart_id` = ' . (int) $cartId
            . ' AND `pse_payment_url` IS NOT NULL'
            . ' ORDER BY `id_mp_transaction` DESC',
            false
        );

        if (!$row || empty($row['pse_payment_url'])) {
            return null;
        }

        return [
            'payment_url' => (string) $row['pse_payment_url'],
        ];
    }

    public function updateMerchantOrderIdByCartId($idCart, $psOrderId)
    {
        $sql = 'UPDATE `' . _DB_PREFIX_ . self::TABLE_TRANSACTIONS . '` SET `merchant_order_id` = \'' . pSQL((string) $psOrderId) . '\''
            . ' WHERE `cart_id` = ' . (int) $idCart;

        return (bool) \Db::getInstance()->execute($sql, false);
    }

    /**
     * Verify that one concrete transaction owns one concrete PrestaShop order.
     *
     * The three identifiers are intentionally part of the same query. Looking up
     * only merchant_order_id or cart_id would allow a row from another checkout
     * attempt to authorize the transition.
     */
    public function isTransactionLinkedToOrder(int $transactionId, int $cartId, int $psOrderId): ?bool
    {
        if ($transactionId <= 0 || $cartId <= 0 || $psOrderId <= 0) {
            return false;
        }

        $db = \Db::getInstance();
        $row = $db->getRow(
            'SELECT `id_mp_transaction` FROM `' . _DB_PREFIX_ . self::TABLE_TRANSACTIONS . '`'
            . ' WHERE `id_mp_transaction` = ' . $transactionId
            . ' AND `cart_id` = ' . $cartId
            . ' AND `merchant_order_id` = \'' . pSQL((string) $psOrderId) . '\'',
            false
        );

        if ($row === false && $db->getNumberError() !== 0) {
            return null;
        }

        return (int) ($row['id_mp_transaction'] ?? 0) === $transactionId;
    }

    /**
     * Bind an order discovered from the cart only while the transaction is still
     * unbound. A conflicting merchant_order_id is never overwritten.
     */
    public function linkMerchantOrderId(int $transactionId, int $cartId, int $psOrderId): ?bool
    {
        if ($transactionId <= 0 || $cartId <= 0 || $psOrderId <= 0) {
            return false;
        }

        $db = \Db::getInstance();
        $reservation = self::ORDER_CREATION_RESERVATION_PREFIX . $transactionId;
        $webhookReservation = self::WEBHOOK_ORDER_CREATION_RESERVATION_PREFIX . $transactionId;
        $uncertainMarker = self::ORDER_CREATION_UNCERTAIN_PREFIX . $transactionId;
        $executed = $db->execute(
            'UPDATE `' . _DB_PREFIX_ . self::TABLE_TRANSACTIONS . '`'
            . ' SET `merchant_order_id` = \'' . pSQL((string) $psOrderId) . '\''
            . ' WHERE `id_mp_transaction` = ' . $transactionId
            . ' AND `cart_id` = ' . $cartId
            . ' AND (`merchant_order_id` IS NULL OR `merchant_order_id` = \'\' OR `merchant_order_id` = \'0\''
            . ' OR `merchant_order_id` = \'' . pSQL($reservation) . '\''
            . ' OR `merchant_order_id` = \'' . pSQL($webhookReservation) . '\''
            . ' OR `merchant_order_id` = \'' . pSQL($uncertainMarker) . '\')',
            false
        );

        if ($executed !== true) {
            return null;
        }

        return $db->Affected_Rows() > 0;
    }

    /**
     * Reserve order creation for one exact transaction before validateOrder() runs.
     *
     * The reservation is durable so a retry can finish the exact link when order
     * creation succeeded but the final ownership write failed transiently.
     */
    public function reserveOrderCreation(int $transactionId, int $cartId): ?bool
    {
        if ($transactionId <= 0 || $cartId <= 0) {
            return false;
        }

        $reservation = self::ORDER_CREATION_RESERVATION_PREFIX . $transactionId;
        $db = \Db::getInstance();
        $executed = $db->execute(
            'UPDATE `' . _DB_PREFIX_ . self::TABLE_TRANSACTIONS . '`'
            . ' SET `merchant_order_id` = \'' . pSQL($reservation) . '\''
            . ' WHERE `id_mp_transaction` = ' . $transactionId
            . ' AND `cart_id` = ' . $cartId
            . ' AND (`merchant_order_id` IS NULL OR `merchant_order_id` = \'\' OR `merchant_order_id` = \'0\''
            . ' OR `merchant_order_id` = \'' . pSQL($reservation) . '\')',
            false
        );

        if ($executed !== true) {
            return null;
        }

        return $this->isOrderCreationReserved($transactionId, $cartId);
    }

    /** Restore a webhook reservation only when validateOrder() never started. */
    public function restoreWebhookOrderCreationReservation(int $transactionId, int $cartId): ?bool
    {
        if ($transactionId <= 0 || $cartId <= 0) {
            return false;
        }

        $webhookReservation = self::WEBHOOK_ORDER_CREATION_RESERVATION_PREFIX . $transactionId;
        $uncertainMarker = self::ORDER_CREATION_UNCERTAIN_PREFIX . $transactionId;
        $db = \Db::getInstance();
        $executed = $db->execute(
            'UPDATE `' . _DB_PREFIX_ . self::TABLE_TRANSACTIONS . '`'
            . ' SET `merchant_order_id` = \'' . pSQL($webhookReservation) . '\', `guard_updated_at` = NOW()'
            . ' WHERE `id_mp_transaction` = ' . $transactionId
            . ' AND `cart_id` = ' . $cartId
            . ' AND `merchant_order_id` = \'' . pSQL($uncertainMarker) . '\'',
            false
        );

        if ($executed !== true) {
            return null;
        }

        return $this->isWebhookOrderCreationReserved($transactionId, $cartId);
    }

    /**
     * Reserve notification-created order creation without claiming a checkout
     * reservation. This marker is safe to retry when validateOrder() fails
     * before an order is created.
     */
    public function reserveWebhookOrderCreation(int $transactionId, int $cartId): ?bool
    {
        if ($transactionId <= 0 || $cartId <= 0) {
            return false;
        }

        $reservation = self::WEBHOOK_ORDER_CREATION_RESERVATION_PREFIX . $transactionId;
        $db = \Db::getInstance();
        $executed = $db->execute(
            'UPDATE `' . _DB_PREFIX_ . self::TABLE_TRANSACTIONS . '`'
            . ' SET `merchant_order_id` = \'' . pSQL($reservation) . '\', `guard_updated_at` = NOW()'
            . ' WHERE `id_mp_transaction` = ' . $transactionId
            . ' AND `cart_id` = ' . $cartId
            . ' AND (`merchant_order_id` IS NULL OR `merchant_order_id` = \'\' OR `merchant_order_id` = \'0\''
            . ' OR `merchant_order_id` = \'' . pSQL($reservation) . '\')',
            false
        );

        if ($executed !== true) {
            return null;
        }

        return $this->isWebhookOrderCreationReserved($transactionId, $cartId);
    }

    /**
     * Durably block webhook order creation when the remote response no longer
     * matches the cart snapshot captured by checkout.
     */
    public function markOrderCreationInvalid(int $transactionId, int $cartId): ?bool
    {
        if ($transactionId <= 0 || $cartId <= 0) {
            return false;
        }

        $reservation = self::ORDER_CREATION_RESERVATION_PREFIX . $transactionId;
        $webhookReservation = self::WEBHOOK_ORDER_CREATION_RESERVATION_PREFIX . $transactionId;
        $invalidMarker = self::ORDER_CREATION_INVALID_PREFIX . $transactionId;
        $uncertainMarker = self::ORDER_CREATION_UNCERTAIN_PREFIX . $transactionId;
        $db = \Db::getInstance();
        $executed = $db->execute(
            'UPDATE `' . _DB_PREFIX_ . self::TABLE_TRANSACTIONS . '`'
            . ' SET `merchant_order_id` = \'' . pSQL($invalidMarker) . '\''
            . ' WHERE `id_mp_transaction` = ' . $transactionId
            . ' AND `cart_id` = ' . $cartId
            . ' AND (`merchant_order_id` IS NULL OR `merchant_order_id` = \'\' OR `merchant_order_id` = \'0\''
            . ' OR `merchant_order_id` = \'' . pSQL($reservation) . '\''
            . ' OR `merchant_order_id` = \'' . pSQL($webhookReservation) . '\''
            . ' OR `merchant_order_id` = \'' . pSQL($uncertainMarker) . '\''
            . ' OR `merchant_order_id` = \'' . pSQL($invalidMarker) . '\')',
            false
        );

        if ($executed !== true) {
            return null;
        }

        $row = $this->findByIdAndCart($transactionId, $cartId);

        return is_array($row)
            && (string) ($row['merchant_order_id'] ?? '') === $invalidMarker;
    }

    public static function hasInvalidOrderCreationMarker(array $transaction): bool
    {
        $transactionId = (int) ($transaction['id_mp_transaction'] ?? 0);

        return $transactionId > 0
            && (string) ($transaction['merchant_order_id'] ?? '')
                === self::ORDER_CREATION_INVALID_PREFIX . $transactionId;
    }

    /**
     * A durable reservation means checkout must retry or finish the exact
     * attempt. A webhook may recover an already-created order with the matching
     * opaque reference, but must not create a new order from the live cart.
     */
    public static function hasPendingOrderCreationMarker(array $transaction): bool
    {
        $transactionId = (int) ($transaction['id_mp_transaction'] ?? 0);

        return $transactionId > 0
            && (string) ($transaction['merchant_order_id'] ?? '')
                === self::ORDER_CREATION_RESERVATION_PREFIX . $transactionId;
    }

    /**
     * Mark an attempt for manual reconciliation after validateOrder may have
     * emitted hooks or e-mails that a database rollback cannot compensate.
     */
    public function markOrderCreationUncertain(int $transactionId, int $cartId): ?bool
    {
        if ($transactionId <= 0 || $cartId <= 0) {
            return false;
        }

        $reservation = self::ORDER_CREATION_RESERVATION_PREFIX . $transactionId;
        $webhookReservation = self::WEBHOOK_ORDER_CREATION_RESERVATION_PREFIX . $transactionId;
        $uncertainMarker = self::ORDER_CREATION_UNCERTAIN_PREFIX . $transactionId;
        $db = \Db::getInstance();
        $executed = $db->execute(
            'UPDATE `' . _DB_PREFIX_ . self::TABLE_TRANSACTIONS . '`'
            . ' SET `merchant_order_id` = \'' . pSQL($uncertainMarker) . '\', `guard_updated_at` = NOW()'
            . ' WHERE `id_mp_transaction` = ' . $transactionId
            . ' AND `cart_id` = ' . $cartId
            . ' AND (`merchant_order_id` IS NULL OR `merchant_order_id` = \'\' OR `merchant_order_id` = \'0\''
            . ' OR `merchant_order_id` = \'' . pSQL($reservation) . '\''
            . ' OR `merchant_order_id` = \'' . pSQL($webhookReservation) . '\''
            . ' OR `merchant_order_id` = \'' . pSQL($uncertainMarker) . '\')',
            false
        );

        if ($executed !== true) {
            return null;
        }

        $row = $this->findByIdAndCart($transactionId, $cartId);

        return is_array($row)
            && (string) ($row['merchant_order_id'] ?? '') === $uncertainMarker;
    }

    public static function hasUncertainOrderCreationMarker(array $transaction): bool
    {
        $transactionId = (int) ($transaction['id_mp_transaction'] ?? 0);

        return $transactionId > 0
            && (string) ($transaction['merchant_order_id'] ?? '')
                === self::ORDER_CREATION_UNCERTAIN_PREFIX . $transactionId;
    }

    /**
     * Restore a guarded attempt only when validateOrder never started and the
     * exact transaction still owns its uncertain marker.
     */
    public function restoreOrderCreationReservation(int $transactionId, int $cartId): ?bool
    {
        if ($transactionId <= 0 || $cartId <= 0) {
            return false;
        }

        $reservation = self::ORDER_CREATION_RESERVATION_PREFIX . $transactionId;
        $uncertainMarker = self::ORDER_CREATION_UNCERTAIN_PREFIX . $transactionId;
        $db = \Db::getInstance();
        $executed = $db->execute(
            'UPDATE `' . _DB_PREFIX_ . self::TABLE_TRANSACTIONS . '`'
            . ' SET `merchant_order_id` = \'' . pSQL($reservation) . '\''
            . ' WHERE `id_mp_transaction` = ' . $transactionId
            . ' AND `cart_id` = ' . $cartId
            . ' AND `merchant_order_id` = \'' . pSQL($uncertainMarker) . '\'',
            false
        );

        if ($executed !== true) {
            return null;
        }

        return $this->isOrderCreationReserved($transactionId, $cartId);
    }

    /**
     * Read materialized split-order totals from the primary connection that
     * owns the financial transaction. PrestaShopCollection may select a replica
     * and cannot observe uncommitted validateOrder writes.
     *
     * @return array<int, array{id_order: mixed, total_paid_tax_incl: mixed}>
     */
    public function findOrderTotalsByReferenceAndCart(string $reference, int $cartId): array
    {
        if ($reference === '' || $cartId <= 0) {
            throw new \InvalidArgumentException('A persisted order reference and cart are required');
        }

        $rows = \Db::getInstance(true)->executeS(
            'SELECT `id_order`, `total_paid_tax_incl`'
            . ' FROM `' . _DB_PREFIX_ . 'orders`'
            . ' WHERE `reference` = \'' . pSQL($reference) . '\''
            . ' AND `id_cart` = ' . $cartId
            . ' ORDER BY `id_order` ASC',
            true,
            false
        );
        if (!is_array($rows)) {
            throw new \RuntimeException('Could not read the materialized checkout orders');
        }

        return $rows;
    }

    /**
     * Reuse the native invoice-payment join for the same cart and order reference.
     * Both ids are persisted primary keys, never request-derived SQL fragments.
     * INSERT IGNORE makes same-state retries preserve existing associations.
     */
    public function linkOrderPaymentToInvoices(int $psOrderId, int $orderPaymentId): bool
    {
        if ($psOrderId <= 0 || $orderPaymentId <= 0) {
            return false;
        }

        return \Db::getInstance(true)->execute(
            'INSERT IGNORE INTO `' . _DB_PREFIX_ . 'order_invoice_payment`'
            . ' (`id_order_invoice`, `id_order_payment`, `id_order`)'
            . ' SELECT oi.`id_order_invoice`, ' . $orderPaymentId . ', oi.`id_order`'
            . ' FROM `' . _DB_PREFIX_ . 'order_invoice` oi'
            . ' INNER JOIN `' . _DB_PREFIX_ . 'orders` sibling ON sibling.`id_order` = oi.`id_order`'
            . ' INNER JOIN `' . _DB_PREFIX_ . 'orders` selected ON selected.`id_order` = ' . $psOrderId
            . ' INNER JOIN `' . _DB_PREFIX_ . 'order_payment` op ON op.`id_order_payment` = ' . $orderPaymentId
            . ' AND op.`order_reference` = selected.`reference`'
            . ' WHERE sibling.`reference` = selected.`reference`'
            . ' AND sibling.`id_cart` = selected.`id_cart`',
            false
        );
    }

    /**
     * Prove that the durable order-creation reservation belongs to this transaction.
     */
    public function isOrderCreationReserved(int $transactionId, int $cartId): ?bool
    {
        if ($transactionId <= 0 || $cartId <= 0) {
            return false;
        }

        $db = \Db::getInstance();
        $reservation = self::ORDER_CREATION_RESERVATION_PREFIX . $transactionId;
        $row = $db->getRow(
            'SELECT `id_mp_transaction` FROM `' . _DB_PREFIX_ . self::TABLE_TRANSACTIONS . '`'
            . ' WHERE `id_mp_transaction` = ' . $transactionId
            . ' AND `cart_id` = ' . $cartId
            . ' AND `merchant_order_id` = \'' . pSQL($reservation) . '\'',
            false
        );

        if ($row === false && $db->getNumberError() !== 0) {
            return null;
        }

        return (int) ($row['id_mp_transaction'] ?? 0) === $transactionId;
    }

    /**
     * Prove that a retryable notification reservation belongs to this exact
     * transaction, without accepting checkout-owned reservations.
     */
    public function isWebhookOrderCreationReserved(int $transactionId, int $cartId): ?bool
    {
        if ($transactionId <= 0 || $cartId <= 0) {
            return false;
        }

        $db = \Db::getInstance();
        $reservation = self::WEBHOOK_ORDER_CREATION_RESERVATION_PREFIX . $transactionId;
        $row = $db->getRow(
            'SELECT `id_mp_transaction` FROM `' . _DB_PREFIX_ . self::TABLE_TRANSACTIONS . '`'
            . ' WHERE `id_mp_transaction` = ' . $transactionId
            . ' AND `cart_id` = ' . $cartId
            . ' AND `merchant_order_id` = \'' . pSQL($reservation) . '\'',
            false
        );

        if ($row === false && $db->getNumberError() !== 0) {
            return null;
        }

        return (int) ($row['id_mp_transaction'] ?? 0) === $transactionId;
    }

    /**
     * Smallest declared length of the core columns that store an order reference
     * (orders.reference and order_payment.order_reference), or null when it cannot be read.
     *
     * PrestaShop 9 declares both as VARCHAR(255); shops migrated without the 9.0.0
     * upgrade script can keep VARCHAR(9). PrestaShop's connection runs with an empty
     * sql_mode, so a longer reference is truncated silently instead of failing.
     */
    public function orderReferenceCapacity(): ?int
    {
        if ($this->orderReferenceCapacityLoaded) {
            return $this->orderReferenceCapacity;
        }
        $this->orderReferenceCapacityLoaded = true;

        $rows = \Db::getInstance()->executeS(
            'SELECT `CHARACTER_MAXIMUM_LENGTH` AS `capacity` FROM `information_schema`.`COLUMNS`'
            . ' WHERE `TABLE_SCHEMA` = DATABASE()'
            . ' AND ((`TABLE_NAME` = \'' . pSQL(_DB_PREFIX_ . 'orders') . '\' AND `COLUMN_NAME` = \'reference\')'
            . ' OR (`TABLE_NAME` = \'' . pSQL(_DB_PREFIX_ . 'order_payment') . '\' AND `COLUMN_NAME` = \'order_reference\'))',
            true,
            false
        );
        if (!is_array($rows)) {
            return $this->orderReferenceCapacity = null;
        }

        $capacities = array_filter(
            array_map(static fn (mixed $row): int => is_array($row) ? (int) ($row['capacity'] ?? 0) : 0, $rows),
            static fn (int $capacity): bool => $capacity > 0
        );

        return $this->orderReferenceCapacity = $capacities === [] ? null : min($capacities);
    }

    public function update(array $data, $id)
    {
        $setParts = [];
        foreach ($data as $key => $value) {
            if ($value === null) {
                $setParts[] = '`' . pSQL($key) . '` = NULL';
            } elseif (in_array($key, self::INTEGER_FIELDS, true)) {
                $setParts[] = '`' . pSQL($key) . '` = ' . (int) $value;
            } elseif ($key === 'total') {
                $setParts[] = '`' . pSQL($key) . '` = ' . (float) $value;
            } else {
                $setParts[] = '`' . pSQL($key) . '` = \'' . pSQL($value) . '\'';
            }
        }

        $sql = 'UPDATE `' . _DB_PREFIX_ . self::TABLE_TRANSACTIONS . '` SET ' . implode(', ', $setParts)
            . ' WHERE `id_mp_transaction` = ' . (int) $id;

        return (bool) \Db::getInstance()->execute($sql, false);
    }

    /**
     * Refresh replayed checkout metadata without erasing durable webhook/order state.
     */
    public function updateCheckoutMetadata(array $data, int $transactionId): bool
    {
        unset(
            $data['payment_status'],
            $data['received_webhook'],
            $data['merchant_order_id'],
            $data['payment_id'],
            $data['total'],
            $data['payment_amount']
        );

        return $this->update($data, $transactionId);
    }

    /**
     * Find the most recent transaction row by MP payment_id.
     *
     * @param string $paymentId
     *
     * @return array|null
     */
    public function findByPaymentId(string $paymentId): ?array
    {
        $row = \Db::getInstance()->getRow(
            'SELECT ' . self::NOTIFICATION_TRANSACTION_COLUMNS
            . ' FROM `' . _DB_PREFIX_ . self::TABLE_TRANSACTIONS . '`'
            . ' WHERE `payment_id` = \'' . pSQL($paymentId) . '\''
            . ' ORDER BY `id_mp_transaction` DESC',
            false
        );

        return $row ?: null;
    }

    /**
     * Find the most recent transaction row by cart_id.
     *
     * @param int $cartId
     *
     * @return array|null
     */
    public function findByCartId(int $cartId): ?array
    {
        $row = \Db::getInstance()->getRow(
            'SELECT ' . self::NOTIFICATION_TRANSACTION_COLUMNS
            . ' FROM `' . _DB_PREFIX_ . self::TABLE_TRANSACTIONS . '`'
            . ' WHERE `cart_id` = ' . (int) $cartId
            . ' ORDER BY `id_mp_transaction` DESC',
            false
        );

        return $row ?: null;
    }

    /**
     * Resolve one deferred checkout attempt by the immutable preference id returned by MP.
     *
     * The cart can have multiple preferences, so selecting its newest transaction is not enough
     * to authorize a browser return. Empty and ambiguous results fail closed; database failures
     * are surfaced to the caller instead of being confused with a missing attempt.
     */
    public function findUniqueDeferredAttempt(int $cartId, string $preferenceId): ?array
    {
        if ($cartId <= 0 || preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $preferenceId) !== 1) {
            return null;
        }

        $db = \Db::getInstance();
        $rows = $db->executeS(
            'SELECT ' . self::NOTIFICATION_TRANSACTION_COLUMNS
            . ' FROM `' . _DB_PREFIX_ . self::TABLE_TRANSACTIONS . '`'
            . ' WHERE `cart_id` = ' . $cartId
            . ' AND `order_id` = \'' . pSQL($preferenceId) . '\''
            . ' ORDER BY `id_mp_transaction` DESC LIMIT 2',
            true,
            false
        );

        if ($rows === false || $db->getNumberError() !== 0) {
            throw new \RuntimeException('Could not read deferred checkout attempt');
        }

        if (!is_array($rows) || count($rows) !== 1 || !is_array($rows[0])) {
            return null;
        }

        return $rows[0];
    }

    /**
     * Serialize notifications for one cart and refresh the transaction state.
     *
     * The named lock is connection-scoped and must be released by the caller.
     * Payment API calls happen before this method, so the critical section only
     * contains local database/order mutations.
     */
    public function lockAndRefreshTransaction(array $transaction, int $timeoutSeconds = 5): ?array
    {
        $transactionId = (int) ($transaction['id_mp_transaction'] ?? 0);
        $cartId = (int) ($transaction['cart_id'] ?? 0);
        if ($transactionId <= 0 || $cartId <= 0) {
            return null;
        }

        if (!$this->acquireNotificationLock($cartId, $timeoutSeconds)) {
            return null;
        }

        try {
            $row = $this->findByIdAndCart($transactionId, $cartId);
            if ($row !== null) {
                return $row;
            }
        } catch (\Throwable $th) {
            $this->releaseNotificationLock($cartId);
            throw $th;
        }

        $this->releaseNotificationLock($cartId);

        return null;
    }

    /**
     * Acquire the cart-scoped lock shared by checkout order creation and webhook processing.
     *
     * Checkout acquires this before exposing mp_transactions and keeps it until validateOrder()
     * finishes. A webhook that resolves the new transaction therefore cannot conclude that the
     * order is definitively absent while checkout is still creating it.
     */
    public function acquireNotificationLock(int $cartId, int $timeoutSeconds = 5): bool
    {
        if ($cartId <= 0) {
            return false;
        }

        $timeoutSeconds = max(0, min(10, $timeoutSeconds));
        $acquired = \Db::getInstance()->getValue(
            'SELECT GET_LOCK(\'' . pSQL($this->notificationLockName($cartId)) . '\', '
            . $timeoutSeconds . ')',
            false
        );

        return (int) $acquired === 1;
    }

    public function releaseNotificationLock(int $cartId): void
    {
        if ($cartId <= 0) {
            return;
        }

        \Db::getInstance()->getValue(
            'SELECT RELEASE_LOCK(\'' . pSQL($this->notificationLockName($cartId)) . '\')',
            false
        );
    }

    public function findByIdAndCart(int $transactionId, int $cartId): ?array
    {
        $db = \Db::getInstance();
        $row = $db->getRow(
            'SELECT ' . self::NOTIFICATION_TRANSACTION_COLUMNS
            . ' FROM `' . _DB_PREFIX_ . self::TABLE_TRANSACTIONS . '`'
            . ' WHERE `id_mp_transaction` = ' . $transactionId
            . ' AND `cart_id` = ' . $cartId,
            false
        );

        if ($row === false && $db->getNumberError() !== 0) {
            throw new \RuntimeException('Could not read checkout transaction');
        }

        return $row ?: null;
    }

    private function notificationLockName(int $cartId): string
    {
        return 'mercadopago:notification:cart:' . $cartId;
    }

    /**
     * Update payment_status and mark received_webhook = 1.
     *
     * When $expectedCurrentStatus is provided, the UPDATE includes
     * AND payment_status = '$expectedCurrentStatus' (optimistic locking).
     * Returns false if no row was affected — i.e. another concurrent process
     * already changed the status before this write landed.
     *
     * @param int $id id_mp_transaction
     * @param string $status new MP payment status
     * @param string|null $expectedCurrentStatus null disables optimistic locking; an empty string is a valid expected value
     *
     * @return bool|null true when updated, false on a compare-and-set miss, null when the query fails
     */
    public function updatePaymentStatus(int $id, string $status, ?string $expectedCurrentStatus = null): ?bool
    {
        $db = \Db::getInstance();

        $sql = 'UPDATE `' . _DB_PREFIX_ . self::TABLE_TRANSACTIONS . '`'
            . ' SET `payment_status` = \'' . pSQL($status) . '\','
            . ' `received_webhook` = 1'
            . ' WHERE `id_mp_transaction` = ' . (int) $id;

        if ($expectedCurrentStatus !== null) {
            $sql .= ' AND `payment_status` = \'' . pSQL($expectedCurrentStatus) . '\'';
        }

        $executed = $db->execute($sql, false);
        if (!$executed) {
            return null;
        }

        return $db->Affected_Rows() > 0;
    }

    /**
     * Atomically rotate the canonical payment id and its status.
     *
     * Merchant-order recovery can replace a failed/pending payment with a
     * later approved payment. Both values form one optimistic-lock state: if
     * either expected value changed, neither new value may be persisted.
     */
    public function compareAndSetPaymentRotation(
        int $transactionId,
        string $paymentId,
        string $status,
        string $expectedPaymentId,
        string $expectedStatus,
    ): ?bool {
        if ($transactionId <= 0
            || $paymentId === ''
            || $expectedPaymentId === ''
            || $paymentId === $expectedPaymentId
        ) {
            return false;
        }

        $db = \Db::getInstance();
        $executed = $db->execute(
            'UPDATE `' . _DB_PREFIX_ . self::TABLE_TRANSACTIONS . '`'
            . ' SET `payment_id` = \'' . pSQL($paymentId) . '\','
            . ' `payment_status` = \'' . pSQL($status) . '\','
            . ' `received_webhook` = 1'
            . ' WHERE `id_mp_transaction` = ' . $transactionId
            . ' AND `payment_id` = \'' . pSQL($expectedPaymentId) . '\''
            . ' AND `payment_status` = \'' . pSQL($expectedStatus) . '\'',
            false
        );

        if ($executed !== true) {
            return null;
        }

        return $db->Affected_Rows() > 0;
    }

    public function markWebhookReceived(int $transactionId): bool
    {
        return (bool) \Db::getInstance()->execute(
            'UPDATE `' . _DB_PREFIX_ . self::TABLE_TRANSACTIONS . '`'
            . ' SET `received_webhook` = 1'
            . ' WHERE `id_mp_transaction` = ' . $transactionId,
            false
        );
    }

    public function markWebhookReceivedByPaymentId(string $paymentId): void
    {
        \Db::getInstance()->execute(
            'UPDATE `' . _DB_PREFIX_ . self::TABLE_TRANSACTIONS . '`'
            . ' SET `received_webhook` = 1'
            . ' WHERE `payment_id` = \'' . pSQL($paymentId) . '\'',
            false
        );
    }

    public function updatePaymentMetadata(int $transactionId, array $payment): void
    {
        $paymentId = (string) ($payment['id'] ?? '');
        $paymentType = (string) ($payment['payment_type_id'] ?? '');
        $paymentMethod = (string) ($payment['payment_method_id'] ?? '');
        $paymentAmount = (float) ($payment['transaction_details']['total_paid_amount'] ?? $payment['transaction_amount'] ?? 0);
        $notificationUrl = (string) ($_SERVER['REQUEST_URI'] ?? '');

        $setParts = [];
        if ($paymentId !== '') {
            $setParts[] = '`payment_id` = \'' . pSQL($paymentId) . '\'';
        }
        if ($paymentType !== '') {
            $setParts[] = '`payment_type` = \'' . pSQL($paymentType) . '\'';
        }
        if ($paymentMethod !== '') {
            $setParts[] = '`payment_method` = \'' . pSQL($paymentMethod) . '\'';
        }
        if ($paymentAmount > 0) {
            $setParts[] = '`total` = ' . (float) $paymentAmount;
        }
        if ($notificationUrl !== '') {
            $setParts[] = '`notification_url` = \'' . pSQL($notificationUrl) . '\'';
        }

        if (empty($setParts)) {
            return;
        }

        \Db::getInstance()->execute(
            'UPDATE `' . _DB_PREFIX_ . self::TABLE_TRANSACTIONS . '`'
            . ' SET ' . implode(', ', $setParts)
            . ' WHERE `id_mp_transaction` = ' . $transactionId,
            false
        );
    }

    public function insert(array $data): int
    {
        $fields = array_keys($data);
        $values = array_values($data);
        $fieldsSql = '`' . implode('`, `', array_map('pSQL', $fields)) . '`';

        $valuesSql = [];
        foreach ($fields as $index => $field) {
            $value = $values[$index];
            if ($value === null) {
                $valuesSql[] = 'NULL';
            } elseif (in_array($field, self::INTEGER_FIELDS, true)) {
                $valuesSql[] = (int) $value;
            } elseif ($field === 'total') {
                $valuesSql[] = (float) $value;
            } else {
                $valuesSql[] = '\'' . pSQL($value) . '\'';
            }
        }

        $sql = 'INSERT INTO `' . _DB_PREFIX_ . self::TABLE_TRANSACTIONS . '` (' . $fieldsSql . ') VALUES (' . implode(', ', $valuesSql) . ')';

        $db = \Db::getInstance();
        if ($db->execute($sql, false) !== true) {
            return 0;
        }

        $transactionId = (int) $db->Insert_ID();

        return $transactionId > 0 ? $transactionId : 0;
    }
}
