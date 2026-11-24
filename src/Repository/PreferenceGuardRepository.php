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

namespace MercadoPago\Repository;

if (!defined('_PS_VERSION_')) {
    exit;
}

/** Additive guard storage on mp_transactions; immutable snapshots never use total/payment_amount. */
class PreferenceGuardRepository
{
    private const SNAPSHOT_COLUMNS = [
        'snapshot_total' => 'preference_original_total', 'snapshot_hash' => 'preference_items_sha256',
        'snapshot_json' => 'preference_snapshot', 'snapshot_shop' => 'preference_shop_id',
        'snapshot_currency' => 'preference_currency_id', 'snapshot_currency_iso' => 'preference_currency_iso',
    ];

    public function connection(): \PDO
    {
        $connection = \Db::getInstance(true)->getLink();
        if (!$connection instanceof \PDO) {
            throw new \RuntimeException('Preference guard requires the primary PDO connection');
        }

        return $connection;
    }

    public static function columns(): array
    {
        return ['guard_preference_id' => 'VARCHAR(100) NULL', 'preference_original_total' => 'DECIMAL(20,6) NULL',
            'preference_items_sha256' => 'CHAR(64) NULL', 'preference_snapshot' => 'LONGTEXT NULL',
            'preference_shop_id' => 'INT UNSIGNED NULL', 'preference_currency_id' => 'INT UNSIGNED NULL',
            'preference_currency_iso' => 'VARCHAR(3) NULL', 'guard_status' => 'VARCHAR(24) NULL',
            'guard_reason' => 'VARCHAR(32) NULL', 'guard_payment_id' => 'VARCHAR(100) NULL',
            'guard_order_id' => 'INT UNSIGNED NULL', 'guard_review_payment_id' => 'VARCHAR(100) NULL',
            'guard_review_context' => 'LONGTEXT NULL', 'guard_updated_at' => 'DATETIME NULL'];
    }

    private function query(string $sql, array $parameters = []): \PDOStatement
    {
        $statement = $this->connection()->prepare($sql);
        if (!$statement || !$statement->execute($parameters)) {
            throw new \RuntimeException('Preference guard persistence failed');
        }

        return $statement;
    }

    public function find(string $preferenceId): ?array
    {
        $rows = $this->query('SELECT * FROM `' . _DB_PREFIX_ . 'mp_transactions` WHERE `order_id` = ? LIMIT 2', [$preferenceId])->fetchAll(\PDO::FETCH_ASSOC);
        if (count($rows) > 1) {
            throw new \DomainException('Ambiguous preference transactions');
        }
        if ($rows === []) {
            return null;
        }
        $row = $rows[0];
        foreach (self::SNAPSHOT_COLUMNS as $key => $column) {
            $row[$key] = $row[$column] ?? null;
        }
        foreach (['snapshot_shop', 'snapshot_currency'] as $key) {
            $row[$key] = $row[$key] !== null ? (int) $row[$key] : null;
        }

        return $row;
    }

    /**
     * Latest Checkout Pro attempt of the cart whose approved payment is still waiting for an order:
     * a webhook reservation, the uncertain marker, or a guard block recorded for an approved payment.
     * The guard only reaches those states after confirming the payment as approved through the API.
     * Attempts idle for longer than $maxAgeHours are ignored so a later refund cannot lock the cart. The age counts
     * from guard_updated_at, which the webhook reservation and the uncertain marker renew, so an old preference paid
     * now is still recognised.
     */
    public function findApprovedAwaitingOrder(int $cartId, int $maxAgeHours): ?array
    {
        if ($cartId <= 0 || $maxAgeHours <= 0) {
            return null;
        }
        $rows = $this->query(
            'SELECT `id_mp_transaction`, `cart_id`, `order_id`, `total`, `merchant_order_id`, `guard_status`,'
            . ' `guard_reason`, `guard_payment_id`'
            . ' FROM `' . _DB_PREFIX_ . 'mp_transactions`'
            . ' WHERE `cart_id` = ? AND `guard_preference_id` IS NOT NULL'
            . ' AND (`merchant_order_id` LIKE ? OR `merchant_order_id` LIKE ?'
            . ' OR (`guard_status` = \'blocked\' AND `guard_payment_id` IS NOT NULL AND `guard_payment_id` <> \'\'))'
            . ' AND COALESCE(`guard_updated_at`, `updated_at`, `created_at`) >= (NOW() - INTERVAL ? HOUR)'
            . ' ORDER BY `id_mp_transaction` DESC LIMIT 1',
            [
                $cartId,
                MPTransactionRepository::WEBHOOK_ORDER_CREATION_RESERVATION_PREFIX . '%',
                MPTransactionRepository::ORDER_CREATION_UNCERTAIN_PREFIX . '%',
                $maxAgeHours,
            ]
        )->fetchAll(\PDO::FETCH_ASSOC);

        return $rows[0] ?? null;
    }

    public function saveSnapshot(int $transactionId, string $preferenceId, array $snapshot): void
    {
        $sets = ['`guard_preference_id` = ?', '`guard_status` = \'open\'', '`guard_updated_at` = NOW()'];
        $values = [$preferenceId];
        foreach (self::SNAPSHOT_COLUMNS as $key => $column) {
            if (!isset($snapshot[$key])) {
                throw new \DomainException('Incomplete preference snapshot');
            }
            $sets[] = '`' . $column . '` = ?';
            $values[] = $snapshot[$key];
        }
        $values[] = $transactionId;
        $values[] = $preferenceId;
        $statement = $this->query('UPDATE `' . _DB_PREFIX_ . 'mp_transactions` SET ' . implode(', ', $sets)
            . ' WHERE `id_mp_transaction` = ? AND `order_id` = ? AND `guard_status` IS NULL', $values);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('Preference snapshot is immutable or unavailable');
        }
    }

    public function link(int $transactionId, string $paymentId, int $orderId): void
    {
        $statement = $this->query('UPDATE `' . _DB_PREFIX_ . 'mp_transactions`'
            . ' SET `guard_status` = \'linked\', `guard_payment_id` = ?, `guard_order_id` = ?, `guard_updated_at` = NOW()'
            . ' WHERE `id_mp_transaction` = ? AND `guard_status` = \'open\'', [$paymentId, $orderId, $transactionId]);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('Could not link preference guard');
        }
    }

    /** Final check on persisted rows, before the creation transaction is committed. */
    public function materializedMatches(array $transaction, \Order $order): bool
    {
        $orders = $this->query('SELECT `id_order`,`module`,`id_shop`,`id_currency`,`total_paid_tax_incl` FROM `' . _DB_PREFIX_
            . 'orders` WHERE `id_cart` = ? AND `reference` = ?', [(int) $transaction['cart_id'], $order->reference])->fetchAll(\PDO::FETCH_ASSOC);
        $total = 0.0;
        foreach ($orders as $row) {
            if ($row['module'] !== 'mercadopago' || (int) $row['id_shop'] !== (int) $transaction['snapshot_shop']
                || (int) $row['id_currency'] !== (int) $transaction['snapshot_currency']
            ) {
                return false;
            }
            $total += (float) $row['total_paid_tax_incl'];
        }
        if ($orders === [] || \MercadoPago\Service\Preference\PreferenceSnapshot::money($total, $transaction['snapshot_currency_iso']) !== $transaction['snapshot_total']) {
            return false;
        }
        $actual = $this->query(
            'SELECT d.`product_id`,d.`product_attribute_id`,SUM(d.`product_quantity`) AS quantity FROM `' . _DB_PREFIX_
            . 'order_detail` d INNER JOIN `' . _DB_PREFIX_ . 'orders` o ON o.id_order=d.id_order'
            . ' WHERE o.`id_cart` = ? AND o.`reference` = ? GROUP BY d.`product_id`,d.`product_attribute_id`',
            [(int) $transaction['cart_id'], $order->reference]
        )->fetchAll(\PDO::FETCH_ASSOC);
        $normalize = static function (array $products, string $productKey, string $attributeKey): array {
            $result = [];
            foreach ($products as $product) {
                $key = (int) $product[$productKey] . ':' . (int) $product[$attributeKey];
                $result[$key] = ($result[$key] ?? 0) + (int) $product['quantity'];
            }
            ksort($result);

            return $result;
        };
        $snapshot = json_decode($transaction['snapshot_json'], true, 32, JSON_THROW_ON_ERROR);

        return $normalize($actual, 'product_id', 'product_attribute_id')
            === $normalize($snapshot['products'], 'id_product', 'id_product_attribute');
    }

    /** One review-only transaction per canonical payment, without inventing a preference/snapshot. */
    public function review(?array $transaction, array $payment, string $preferenceId, string $reason): void
    {
        $paymentId = (string) $payment['id'];
        $context = json_encode(['preference_id' => $preferenceId, 'cart_id' => (int) ($payment['external_reference'] ?? 0),
            'transaction_id' => (int) ($transaction['id_mp_transaction'] ?? 0)], JSON_THROW_ON_ERROR);
        // A review-only row uses cart_id=0 so legacy latest-cart lookups cannot select it.
        $statement = $this->query(
            'INSERT IGNORE INTO `' . _DB_PREFIX_ . 'mp_transactions`'
            . ' (`cart_id`,`customer_id`,`mp_module_id`,`guard_status`,`guard_reason`,`guard_payment_id`,`guard_review_payment_id`,`guard_review_context`,`guard_updated_at`)'
            . ' VALUES (0,0,?,\'blocked\',?,?,?,?,NOW())',
            [(int) ($transaction['mp_module_id'] ?? 0), $reason, $paymentId, $paymentId, $context]
        );
        if ($statement->rowCount() === 1) {
            $reviewId = (int) $this->connection()->lastInsertId();
            if (\PrestaShopLogger::addLog(
                '[MercadoPago] Approved Checkout Pro payment requires manual reconciliation. Consult mp_transactions guard queue.',
                3,
                null,
                'MPTransaction',
                $reviewId,
                true
            ) !== true
            ) {
                throw new \RuntimeException('Could not persist reconciliation alert');
            }
        }
        $record = $this->query('SELECT `id_mp_transaction` FROM `' . _DB_PREFIX_ . 'mp_transactions` WHERE `guard_review_payment_id` = ?', [$paymentId])->fetchColumn();
        if (!$record) {
            throw new \RuntimeException('Could not persist reconciliation case');
        }
        if ($transaction !== null) {
            $statement = $this->query(
                'UPDATE `' . _DB_PREFIX_ . 'mp_transactions` SET `guard_status` = \'blocked\', `guard_reason` = ?, `guard_payment_id` = ?, `guard_updated_at` = NOW()'
                . ' WHERE `id_mp_transaction` = ? AND (`guard_status` IS NULL OR `guard_status` = \'open\')',
                [$reason, $paymentId, (int) $transaction['id_mp_transaction']]
            );
            if (in_array($transaction['guard_status'] ?? null, [null, 'open'], true) && $statement->rowCount() !== 1) {
                throw new \RuntimeException('Could not block the preference transaction for review');
            }
        }
    }
}
