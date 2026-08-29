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

namespace MercadoPago\Service\Order;

 use MercadoPago\Client\MPApi;
 use MercadoPago\Repository\MPTransactionRepository;
 use MercadoPago\Service\Configuration\ConfigurationDataService;
 use \Customer;
 use \Tools;

if (!defined('_PS_VERSION_')) {
    exit;
}


class AbstractOrderService
{
    const ORDER_TYPE_ONLINE = 'online';

    public $mpApi;

    protected string $checkout = '';

    protected $transactionRepository;

    protected $cart_rule = null;

    public function __construct()
    {
        $this->mpApi = new MPApi();
        $this->transactionRepository = new MPTransactionRepository();
    }

    /**
     * Configuration key used to read the discount percentage for this payment method.
     * Override in each concrete service. Default '' disables the discount feature.
     */
    protected function getDiscountConfigKey(): string
    {
        return '';
    }

    /**
     * True when the configured discount for this payment method is > 0.
     */
    protected function isDiscountEnabled(): bool
    {
        $key = $this->getDiscountConfigKey();
        if ($key === '') {
            return false;
        }
        return (float) \Configuration::get($key) > 0;
    }

    /**
     * @param  $cart
     * @return array
     */
    public function getCommonMPOrder($cart)
    {
        $mpOrder = array(
            'external_reference' => (string) $cart->id,
            'notification_url' => $this->getNotificationUrl($cart),
        );

        $isTestUser = $this->isTestUser();
        if (!$isTestUser) {
            $mpOrder['sponsor_id'] = $this->getSponsorId();
        }
        return $mpOrder;
    }

    /**
     * Get notification url
     *
     * @param  $cart
     * @return string|void
     */
    public function getNotificationUrl($cart)
    {
        $customer = new Customer((int) $cart->id_customer);

        if (!strrpos($this->getSiteUrl(), 'localhost')) {
            $notification_url = \Tools::getShopDomainSsl(true, true) . __PS_BASE_URI__ .
                '?fc=module&module=mercadopago&controller=notification&' .
                'checkout=' . $this->checkout . '&customer=' . $customer->secure_key .
                '&notification=ipn&source_news=ipn';

            return $notification_url;
        }
    }

    public function getStatementDescriptor()
    {
        if (\Configuration::get('MERCADOPAGO_INVOICE_NAME') == null) {
            return '';
        }
        return \Configuration::get('MERCADOPAGO_INVOICE_NAME');
    }

    /**
     * Get site url
     *
     * @return void
     */
    public function getSiteUrl()
    {
        $url = \Tools::htmlentitiesutf8(('https://' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . __PS_BASE_URI__);
        return $url;
    }

    /**
     * Get sponsor_id for order
     *
     * @return string
     */
    public function getSponsorId()
    {
        $sponsor_id = $this->getCountryConfigs(\Configuration::get('MERCADOPAGO_COUNTRY_LINK'))['sponsor_id'];
        return $sponsor_id;
    }

    /**
     * Get currency_id for order
     *
     * @return string
     */
    public function getCurrencyId()
    {
        return \Context::getContext()->currency->iso_code;
    }

    /**
     * Get country configs for order
     *
     * @param string $country
     * @return array
     */
    public static function getAllCountryConfigs(): array
    {
        return array(
            'MCO' => array(
                'site_id'    => 'MCO',
                'sponsor_id' => '237788769',
                'currency'   => 'COP',
            ),
            'MLA' => array(
                'site_id'    => 'MLA',
                'sponsor_id' => '237788409',
                'currency'   => 'ARS',
            ),
            'MLB' => array(
                'site_id'    => 'MLB',
                'sponsor_id' => '236914421',
                'currency'   => 'BRL',
            ),
            'MLC' => array(
                'site_id'    => 'MLC',
                'sponsor_id' => '237788173',
                'currency'   => 'CLP',
            ),
            'MLM' => array(
                'site_id'    => 'MLM',
                'sponsor_id' => '237793014',
                'currency'   => 'MXN',
            ),
            'MLU' => array(
                'site_id'    => 'MLU',
                'sponsor_id' => '241729464',
                'currency'   => 'UYU',
            ),
            'MLV' => array(
                'site_id'    => 'MLV',
                'sponsor_id' => '237789083',
                'currency'   => 'VEF',
            ),
            'MPE' => array(
                'site_id'    => 'MPE',
                'sponsor_id' => '237791025',
                'currency'   => 'PEN',
            )
        );
    }

    public function getCountryConfigs($country)
    {
        return self::getAllCountryConfigs()[strtoupper($country)];
    }

    /**
     * Get items description
     *
     * @return array|string
     */
    public function getPreferenceDescription($cart)
    {
        $items = array();
        $products = $cart->getProducts();

        foreach ($products as $product) {
            $items[] = $product['name'] . ' x ' . $product['quantity'];
        }

        $items = implode(', ', $items);

        return $items;
    }

    /**
     * Get customer email
     *
     * @return array
     * @throws PrestaShopException
     */
    public function getCustomerEmail()
    {
        $customer_fields = \Context::getContext()->customer->getFields();
        $customer_email = $customer_fields['email'];
        return $customer_email;
    }

    /**
     * Get customer data for custom checkout
     *
     * @return array
     * @throws PrestaShopException
     */
    public function getCustomCustomerData($cart)
    {
        $customer = \Context::getContext()->customer;
        if (empty($customer->firstname) && empty($customer->lastname)) {
            return null;
        }

        $is_logged = \Context::getContext()->customer->isLogged();
        $customer_fields = $customer->getFields();
        $address_invoice = new \Address((int) $cart->id_address_invoice);

        return [
            'first_name' => $customer_fields['firstname'],
            'last_name' => $customer_fields['lastname'],
            'phone' => [
                'area_code' => '-',
                'number' => $address_invoice->phone,
            ],
            'address' => [
                'zip_code' => $address_invoice->postcode,
                'street_name' => $this->buildStreetName($address_invoice),
                'street_number' => '-',
            ],
            'registration_date' => $is_logged ? $customer_fields['date_add'] : ' ',
            'registered_user' => $is_logged ? 'yes' : 'no',
        ];
    }

    /**
     * build street name
     *
     * @param object $address_data
     * @return string
     */
    public function buildStreetName($address_data)
    {
        $address = $address_data->address1 . ' - ' .
        $address_data->address2 . ' - ' .
        $address_data->city . ' - ' .
        $address_data->country;

        return $address;
    }

     /**
     * Get shippment address
     *
     * @return array
     */
    public function getShipmentAddress($cart)
    {
        $address_shipment = new \Address((int) $cart->id_address_delivery);

        $shipment = array(
            'receiver_address' => array(
                'zip_code' => $address_shipment->postcode,
                'street_name' => $this->buildStreetName($address_shipment),
                'street_number' => '-',
                'apartment' => '-',
                'floor' => '-',
                'city_name' => $address_shipment->city,
            ),
        );

        return $shipment;
    }

    /**
     * Get all cart items
     *
     * @param  $cart
     * @param  bool $custom
     * @param  null $percent
     * @return array
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    public function getCartItems($cart, $percent = null)
    {
        $items = $this->getProductItems($cart, $percent);
        $round = $this->getRound();

        $this->addWrappingItem($items, $cart, $round);
        $this->addDiscountItem($items, $cart, $round);
        $this->addShippingItem($items, $cart, $round);
        $this->addPriceDifferenceItem($items, $cart, $round);

        return $items;
    }

    private function getProductItems($cart, $percent)
    {
        $items = [];
        $products = $cart->getProducts();
        $round = $this->getRound();
        $categoryId = (string) \Configuration::get('MERCADOPAGO_STORE_CATEGORY');

        foreach ($products as $product) {
            $image = \Image::getCover($product['id_product']);
            $image_product = new \Product($product['id_product'], false, \Context::getContext()->language->id);
            $link = new \Link();
            $link_image = $link->getImageLink($image_product->link_rewrite, $image['id_image'], '');

            $product_price = $product['price_wt'];
            if ($percent !== null) {
                $product_price = (float) $product_price - ($product_price * ($percent / 100));
            }

            $items[] = [
                'id' => (string) $product['id_product'],
                'title' => $product['name'],
                'quantity' => $product['quantity'],
                'unit_price' => $round ? (string) \Tools::ps_round($product_price) : (string) $product_price,
                'picture_url' => ('https://' ? 'https://' : 'http://') . $link_image,
                'category_id' => $categoryId,
                'description' => strip_tags($product['description_short']),
            ];
        }

        return $items;
    }

    private function addWrappingItem(&$items, $cart, $round)
    {
        $wrapping_cost = (float) $cart->getOrderTotal(true, \Cart::ONLY_WRAPPING);
        if ($wrapping_cost <= 0) {
            return;
        }

        $items[] = [
            'title' => 'Wrapping',
            'quantity' => 1,
            'unit_price' => $round ? (string) \Tools::ps_round($wrapping_cost) : (string) $wrapping_cost,
            'category_id' => (string) \Configuration::get('MERCADOPAGO_STORE_CATEGORY'),
            'description' => 'Wrapping service used by store',
        ];
    }

    private function addDiscountItem(&$items, $cart, $round)
    {
        $discounts = (float) $cart->getOrderTotal(true, \Cart::ONLY_DISCOUNTS);
        if ($discounts <= 0) {
            return;
        }

        $items[] = [
            'title' => 'Discount',
            'quantity' => 1,
            'unit_price' => $round ? (string) \Tools::ps_round(-$discounts) : (string) -$discounts,
            'category_id' => (string) \Configuration::get('MERCADOPAGO_STORE_CATEGORY'),
            'description' => 'Discount provided by store',
        ];
    }

    private function addShippingItem(&$items, $cart, $round)
    {
        $shipping_cost = (float) $cart->getOrderTotal(true, \Cart::ONLY_SHIPPING);
        if ($shipping_cost <= 0) {
            return;
        }

        $items[] = [
            'title' => 'Shipping',
            'quantity' => 1,
            'unit_price' => $round ? (string) \Tools::ps_round($shipping_cost) : (string) $shipping_cost,
            'category_id' => (string) \Configuration::get('MERCADOPAGO_STORE_CATEGORY'),
            'description' => 'Shipping service used by store',
        ];
    }

    private function addPriceDifferenceItem(&$items, $cart, $round)
    {
        $cartTotal = $round ? \Tools::ps_round($cart->getOrderTotal(true)) : $cart->getOrderTotal();
        $itemsTotal = array_reduce(
            $items,
            function ($accumulator, $item) {
                return $accumulator + ($item['unit_price'] * $item['quantity']);
            },
            0
        );

        $itemsTotal = $round ? \Tools::ps_round($itemsTotal) : \Tools::ps_round($itemsTotal, 2);
        $priceDiff = $cartTotal - $itemsTotal;

        if ($priceDiff <= 0) {
            return;
        }

        $items[] = [
            'title' => 'Difference',
            'quantity' => 1,
            'unit_price' => $round ? (string) \Tools::ps_round($priceDiff) : (string) $priceDiff,
            'category_id' => (string) \Configuration::get('MERCADOPAGO_STORE_CATEGORY'),
            'description' => 'Adjustment for the Mercado Pago price to be the same as the store',
        ];
    }

    /**
     * Get round
     */
    public function getRound(): bool
    {
        $round = false;
        $localization = \Configuration::get('MERCADOPAGO_COUNTRY_LINK');

        if ($localization == 'MCO' || $localization == 'MLC') {
            $round = true;
        }

        return $round;
    }

    /**
     * Create and set ticket discount on CartRule()
     *
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    public function setCartRule(\Cart $cart, $discount): void
    {
        if ((float) $discount <= 0) {
            return;
        }

        $mp_code = 'MPDISCOUNT' . $cart->id;
        $store_name = \Configuration::get('PS_LANG_DEFAULT');
        $discount_name = 'Mercado Pago discount applied to cart ' . $cart->id;

        $existingId = (int) \Db::getInstance()->getValue(
            'SELECT id_cart_rule FROM ' . _DB_PREFIX_ . 'cart_rule WHERE code = \'' . pSQL($mp_code) . '\''
        );

        $cart_rule = new \CartRule($existingId ?: null);
        $cart_rule->date_from = date('Y-m-d H:i:s');
        $cart_rule->date_to = date('Y-m-d H:i:s', mktime(0, 0, 0, (int) date("m"), (int) date("d"), (int) date("Y") + 10));
        $cart_rule->name[$store_name] = $discount_name;
        $cart_rule->quantity = 1;
        $cart_rule->code = $mp_code;
        $cart_rule->quantity_per_user = 1;
        $cart_rule->reduction_percent = $discount;
        $cart_rule->reduction_amount = 0;
        $cart_rule->active = true;
        $cart_rule->save();

        if (!$existingId) {
            $cart->addCartRule($cart_rule->id);
        }
        $this->cart_rule = $cart_rule->id;
    }

    /**
     * Disable cart rule when buyer completes purchase
     *
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    public function disableCartRule(): void
    {
        if (!$this->isDiscountEnabled()) {
            return;
        }

        $cart_rule = new \CartRule($this->cart_rule);
        $cart_rule->active = false;
        $cart_rule->save();
    }

    /**
     * Delete cart rule from database when an error occurs during payment.
     * Falls back to disableCartRule if deletion fails.
     *
     * @return bool
     */
    public function deleteCartRule(): bool
    {
        if (!$this->isDiscountEnabled()) {
            return false;
        }

        if (!$this->cart_rule) {
            return false;
        }

        $resultRule = \Db::getInstance()->delete('cart_rule', 'id_cart_rule = ' . (int) $this->cart_rule);
        $resultLang = \Db::getInstance()->delete('cart_rule_lang', 'id_cart_rule = ' . (int) $this->cart_rule);
        $resultCart = \Db::getInstance()->delete('cart_cart_rule', 'id_cart_rule = ' . (int) $this->cart_rule);

        if (!$resultRule || !$resultLang || !$resultCart) {
            \PrestaShopLogger::addLog('Failed to delete cart_rule ' . $this->cart_rule . ' from database', 3);
            $this->disableCartRule();
            return false;
        }

        return true;
    }

    /**
     * Disable cart rule and remove it from the cart (used on payment failure)
     */
    public function removeCartRuleFromCart(\Cart $cart): void
    {
        if ($this->cart_rule) {
            $cart->removeCartRule($this->cart_rule);
            $this->disableCartRule();
        }
    }

    public function clearCartAfterOrder(\Context $context)
    {
        $currentCart = $context->cart;
        if (!$currentCart || !$currentCart->id) {
            return;
        }
        $newCart = new \Cart();
        $newCart->id_customer = (int) $currentCart->id_customer;
        $newCart->id_shop_group = (int) $currentCart->id_shop_group;
        $newCart->id_shop = (int) $currentCart->id_shop;
        $newCart->id_lang = (int) $currentCart->id_lang;
        $newCart->id_currency = (int) $currentCart->id_currency;
        $newCart->id_address_delivery = (int) $currentCart->id_address_delivery;
        $newCart->id_address_invoice = (int) $currentCart->id_address_invoice;
        $newCart->save();
        $context->cart = new \Cart($newCart->id);
        $context->cookie->__set('id_cart', (int) $newCart->id);
        $context->cookie->write();
    }

    /**
     * Get seller data
     *
     * @return array
     */
    public function getSellerData()
    {
        $seller = $this->isValidAccessToken();
        $seller_data = array();
        if ($seller) {
            $seller_data = array(
                'id' => (string) $seller['id'],
                'email' => (string) $seller['email'],
                'first_name' => (string) $seller['first_name'],
                'last_name' => (string) $seller['last_name'],
                'identification' => array(
                    'type' => (string) $seller['identification']['type'],
                    'number' => (string) $seller['identification']['number'],
                ),
                'address' => array(
                    'zip_code' => (string) $seller['address']['zip_code'],
                    'street_name' => (string) $seller['address']['address'],
                    'street_number' => '-',
                    'city' => (string) $seller['address']['city'],
                    'state' => (string) $seller['address']['state'],
                    'country' => (string) $seller['country_id'],
                    'neighborhood' => '-',
                    'complement' => '-',
                    'floor' => '-',
                ),
                'phone' => array(
                    'area_code' => (string) $seller['phone']['area_code'],
                    'number' => (string) $seller['phone']['number'],
                ),
                'referral_url' => (string) $seller['permalink'],
                'registration_date' => (string) $seller['registration_date'],
            );
        }
        $seller_data['website'] = \Tools::getShopDomainSsl(true, true);
        return $seller_data;
    }

    /**
     * Is valid access token
     *
     * @return boolean
     * @throws Exception
     */
    public function isValidAccessToken()
    {
        $response = $this->mpApi->getUserInfo();
        if ($response['status'] > 202) {
            \PrestaShopLogger::addLog('API valid_access_token error: ' . $response['response']['message'], 1, null, null, null, true);
            return false;
        }
        return $response['response'];
    }

    /**
     * Is test user
     *
     * @return boolean
     */
    public function isTestUser()
    {
        $response = $this->mpApi->getUserInfo();
        if ($response['status'] > 202) {
            \PrestaShopLogger::addLog('API is_test_user error: ' . $response['response']['message'], 1, null, null, null, true);
            return false;
        }
        $tags = $response['response']['tags'] ?? [];
        $isTestUser = is_array($tags) && in_array('test_user', $tags, true);

        return $isTestUser;
    }

    /**
     * Get location data from shipping address
     *
     * @param \Cart $cart
     * @return array|null
     */
    public function getLocationData($cart)
    {
        $address = new \Address((int) $cart->id_address_delivery);

        if (!$address || !$address->id) {
            return null;
        }

        $countryIso = \Country::getIsoById((int) $address->id_country);
        $stateId = $countryIso;

        if ((int) $address->id_state > 0) {
            $state = new \State((int) $address->id_state);
            $stateId = $countryIso . '-' . $state->iso_code;
        }

        return array(
            'state_id' => $stateId,
            'source' => 'shipment',
        );
    }

    /**
     * Redirect if any errors occurs
     */
    public function redirectError(): void
    {
        \Tools::redirect('index.php?controller=order&step=3&typeReturn=failure');
    }

    public function saveCreateOrderData($cart, $orderResponseOrNotificationUrl = null, $maybeOrderResponse = null)
    {
        try {
            list($orderResponse, $notificationUrl) = $this->parseOrderResponse($orderResponseOrNotificationUrl, $maybeOrderResponse);
            
            $paymentData = $this->extractPaymentData($orderResponse);
            $idCart = (int) $cart->id;
            $idCustomer = (int) $cart->id_customer;
            $amount = (string) \Tools::ps_round((float) $cart->getOrderTotal(), 2);
            $notificationUrl = $notificationUrl ?: $orderResponse['notification_url'] ?? $this->getNotificationUrl($cart);
            $isTest = !(new ConfigurationDataService())->isProductionMode();
            
            $existingId = $this->getExistingTransactionId($orderResponse);
            $module = \Module::getInstanceByName('mercadopago');
            $version = ($module && !empty($module->version)) ? (string) $module->version : '1.0.0';
            $mpModuleId = $this->transactionRepository->getOrCreateModuleId($version);
            $apiOrderId = isset($orderResponse['id']) ? (string) $orderResponse['id'] : null;
            $psOrderId = $this->getOrderIdFromCart($idCart);
            $customerIdForTransaction = $this->getCollectorIdFromResponse($orderResponse);
            if ($customerIdForTransaction === null) {
                $customerIdForTransaction = $this->getCollectorIdFromSeller();
            }

            $data = $this->buildTransactionData(
                $idCart,
                (int) $customerIdForTransaction,
                $apiOrderId,
                $psOrderId,
                $amount,
                $mpModuleId,
                $notificationUrl,
                $isTest,
                $paymentData,
                $orderResponse
            );

            return $this->saveTransactionData($data, $existingId);
        } catch (\Exception $e) {
            return false;
        }
    }

    public function updateTransactionOrderId($psOrderId, $idCart)
    {
        if ((int) $psOrderId <= 0 || (int) $idCart <= 0) {
            return false;
        }
        return $this->transactionRepository->updateMerchantOrderIdByCartId((int) $idCart, (int) $psOrderId);
    }

    private function parseOrderResponse($orderResponseOrNotificationUrl, $maybeOrderResponse)
    {
        if (is_array($orderResponseOrNotificationUrl)) {
            return [$orderResponseOrNotificationUrl, null];
        }
        
        $notificationUrl = $orderResponseOrNotificationUrl;
        $orderResponse = is_array($maybeOrderResponse) ? $maybeOrderResponse : [];
        return [$orderResponse, $notificationUrl];
    }

    private function extractPaymentData($orderResponse)
    {
        $firstPayment = $this->getFirstPayment($orderResponse);
        
        return [
            'status' => $this->getStatus($orderResponse),
            'payment_method' => $this->getPaymentMethod($firstPayment, $orderResponse),
            'payment_type' => $this->getPaymentType($firstPayment, $orderResponse),
            'mp_payment_id' => $this->getMpPaymentId($firstPayment, $orderResponse),
            'installments' => $this->getInstallments($firstPayment, $orderResponse),
        ];
    }

    private function getFirstPayment($orderResponse)
    {
        if (empty($orderResponse['payments']) || !is_array($orderResponse['payments'])) {
            return null;
        }
        
        return is_array($orderResponse['payments'][0]) ? $orderResponse['payments'][0] : null;
    }

    private function getStatus($orderResponse)
    {
        $status = (string) ($orderResponse['status'] ?? 'pending');
        return $status !== '' ? $status : 'pending';
    }

    private function getPaymentMethod($firstPayment, $orderResponse)
    {
        $method = (string) (
            ($firstPayment['payment_method']['id'] ?? null)
            ?? ($firstPayment['payment_method_id'] ?? null)
            ?? ($orderResponse['payment_method_id'] ?? null)
            ?? 'unknown'
        );
        return $method !== '' ? $method : 'unknown';
    }

    private function getPaymentType($firstPayment, $orderResponse)
    {
        $type = (string) (
            ($firstPayment['payment_method']['type'] ?? null)
            ?? ($firstPayment['payment_type_id'] ?? null)
            ?? ($orderResponse['payment_type_id'] ?? null)
            ?? 'unknown'
        );
        return $type !== '' ? $type : 'unknown';
    }

    private function getMpPaymentId($firstPayment, $orderResponse)
    {
        $fromReferences = isset($firstPayment['references']['payment_id'])
            ? (string) $firstPayment['references']['payment_id']
            : null;
        return (string) (
            $fromReferences
            ?? ($firstPayment['id'] ?? null)
            ?? ($orderResponse['mp_payment_id'] ?? null)
            ?? ($orderResponse['payment_id'] ?? null)
            ?? ''
        );
    }

    private function getInstallments($firstPayment, $orderResponse)
    {
        $installments = null;
        
        if (isset($firstPayment['payment_method']['installments'])) {
            $installments = (int) $firstPayment['payment_method']['installments'];
        } elseif (isset($firstPayment['installments'])) {
            $installments = (int) $firstPayment['installments'];
        } elseif (isset($orderResponse['installments'])) {
            $installments = (int) $orderResponse['installments'];
        }
        
        return ($installments !== null && $installments > 0) ? $installments : null;
    }

    private function getExistingTransactionId($orderResponse)
    {
        $apiOrderId = isset($orderResponse['id']) ? (string) $orderResponse['id'] : null;
        $isMockOrder = $apiOrderId !== null && strpos($apiOrderId, 'MOCK-ORDER-') === 0;
        if ($isMockOrder || $apiOrderId === null || $apiOrderId === '') {
            return 0;
        }
        return $this->transactionRepository->findExistingTransactionIdByOrderId($apiOrderId);
    }

    private function getOrderIdFromCart($idCart)
    {
        try {
            $orderIdFromCart = \Order::getIdByCartId((int) $idCart);
            return ($orderIdFromCart && $orderIdFromCart > 0) ? (int) $orderIdFromCart : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    private function getCollectorIdFromResponse($orderResponse)
    {
        if (!isset($orderResponse['collector_id']) || $orderResponse['collector_id'] === '' || $orderResponse['collector_id'] === null) {
            return null;
        }
        return (int) $orderResponse['collector_id'];
    }

    private function getCollectorIdFromSeller()
    {
        $seller = $this->isValidAccessToken();
        if (!$seller || !isset($seller['id']) || $seller['id'] === '' || $seller['id'] === null) {
            return null;
        }
        return (int) $seller['id'];
    }

    private function buildTransactionData($idCart, $idCustomer, $apiOrderId, $psOrderId, $amount, $mpModuleId, $notificationUrl, $isTest, $paymentData, $orderResponse)
    {
        return [
            'cart_id' => (int) $idCart,
            'order_id' => $apiOrderId ? pSQL($apiOrderId) : null,
            'customer_id' => (int) $idCustomer,
            'total' => (float) $amount,
            'mp_module_id' => (int) $mpModuleId,
            'notification_url' => $notificationUrl ? pSQL((string) $notificationUrl) : null,
            'is_payment_test' => (int) $isTest,
            'received_webhook' => 0,
            'payment_id' => $paymentData['mp_payment_id'] !== '' ? pSQL($paymentData['mp_payment_id']) : null,
            'payment_method' => pSQL($paymentData['payment_method']),
            'payment_type' => pSQL($paymentData['payment_type']),
            'payment_status' => pSQL($paymentData['status']),
            'payment_amount' => pSQL($amount),
            'merchant_order_id' => null,
        ];
    }

    private function saveTransactionData($data, $existingId)
    {
        if ($existingId > 0) {
            return $this->transactionRepository->update($data, $existingId);
        }

        return $this->transactionRepository->insert($data);
    }

    protected function getPlatformData()
    {
        return array(
            'environment' => array(
                'platform_version' => _PS_VERSION_,
                'module_version' => MP_VERSION,
            ),
            'specific_data' => new \stdClass(),
        );
    }

    protected function getShipmentData($cart)
    {
        $shipmentAddress = $this->getShipmentAddress($cart);
        return array(
            'address' => array(
                'zip_code' => $shipmentAddress['receiver_address']['zip_code'],
                'street_name' => $shipmentAddress['receiver_address']['street_name'],
                'street_number' => $shipmentAddress['receiver_address']['street_number'],
                'city' => $shipmentAddress['receiver_address']['city_name'],
                'complement' => $shipmentAddress['receiver_address']['apartment'],
                'floor' => $shipmentAddress['receiver_address']['floor'],
            ),
        );
    }

    protected function getBillingData($cart)
    {
        $addressInvoice = new \Address((int) $cart->id_address_invoice);
        return array(
            'address' => array(
                'zip_code' => $addressInvoice->postcode,
                'street_name' => $addressInvoice->address1 . ' - ' . $addressInvoice->address2,
                'street_number' => '-',
                'city' => $addressInvoice->city,
                'country' => $addressInvoice->country,
            ),
        );
    }
}
