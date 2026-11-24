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
 * @author    PrestaShop SA and Contributors
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */
declare(strict_types=1);

namespace MercadoPago\Service\Preference;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Module-authored order-creation cause whose message carries only configuration and schema
 * values (order state, column width, reference length), never payment activity identifiers.
 * Only these messages are logged verbatim; any other cause text has its digits masked.
 */
final class OrderCreationDiagnosticException extends \RuntimeException
{
}
