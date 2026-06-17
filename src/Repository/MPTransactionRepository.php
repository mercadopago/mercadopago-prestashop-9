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
    private const TABLE_TRANSACTIONS = 'mp_transactions';
    private const TABLE_MODULE = 'mp_module';
    private const INTEGER_FIELDS = ['cart_id', 'customer_id', 'mp_module_id', 'is_payment_test', 'received_webhook'];

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
        $existingRow = \Db::getInstance()->getRow(
            'SELECT `id_mp_transaction` FROM `' . _DB_PREFIX_ . self::TABLE_TRANSACTIONS . '` WHERE `order_id` = \'' . pSQL((string) $orderId) . '\' ORDER BY `id_mp_transaction` DESC',
            false
        );
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
     * Find the most recent transaction row by MP payment_id.
     *
     * @param string $paymentId
     * @return array|null
     */
    public function findByPaymentId(string $paymentId): ?array
    {
        $row = \Db::getInstance()->getRow(
            'SELECT `id_mp_transaction`, `cart_id`, `merchant_order_id`, `payment_status`, `payment_id`'
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
     * @return array|null
     */
    public function findByCartId(int $cartId): ?array
    {
        $row = \Db::getInstance()->getRow(
            'SELECT `id_mp_transaction`, `cart_id`, `merchant_order_id`, `payment_status`, `payment_id`'
            . ' FROM `' . _DB_PREFIX_ . self::TABLE_TRANSACTIONS . '`'
            . ' WHERE `cart_id` = ' . (int) $cartId
            . ' ORDER BY `id_mp_transaction` DESC',
            false
        );

        return $row ?: null;
    }

    /**
     * Update payment_status and mark received_webhook = 1.
     *
     * When $expectedCurrentStatus is provided, the UPDATE includes
     * AND payment_status = '$expectedCurrentStatus' (optimistic locking).
     * Returns false if no row was affected — i.e. another concurrent process
     * already changed the status before this write landed.
     *
     * @param int    $id                    id_mp_transaction
     * @param string $status                new MP payment status
     * @param string $expectedCurrentStatus if non-empty, used as optimistic lock condition
     * @return bool true if the row was actually updated
     */
    public function updatePaymentStatus(int $id, string $status, string $expectedCurrentStatus = ''): bool
    {
        $db = \Db::getInstance();

        $sql = 'UPDATE `' . _DB_PREFIX_ . self::TABLE_TRANSACTIONS . '`'
            . ' SET `payment_status` = \'' . pSQL($status) . '\','
            . ' `received_webhook` = 1'
            . ' WHERE `id_mp_transaction` = ' . (int) $id;

        if ($expectedCurrentStatus !== '') {
            $sql .= ' AND `payment_status` = \'' . pSQL($expectedCurrentStatus) . '\'';
        }

        $db->execute($sql, false);

        return $db->Affected_Rows() > 0;
    }

    public function markWebhookReceived(int $transactionId): void
    {
        \Db::getInstance()->execute(
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

    public function insert(array $data)
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

        return (bool) \Db::getInstance()->execute($sql, false);
    }
}
