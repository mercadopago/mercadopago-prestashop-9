{**
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
 *}
{extends file='page.tpl'}

{* Same structure as the theme's order confirmation, for an approved payment whose order is still being created. *}
{block name='page_content'}
  <section id="mp-payment-received" class="card mp-payment-received">
    <div class="card-block">
      <h3 class="h1 card-title">
        <i class="material-icons rtl-no-flip done" aria-hidden="true">&#xE876;</i>{$mp_received.title|escape:'htmlall':'UTF-8'}
      </h3>
      <p>{$mp_received.message|escape:'htmlall':'UTF-8'}</p>
      {if $mp_received.amount}
        <p class="mp-payment-received-amount">
          <strong>{$mp_received.amount_label|escape:'htmlall':'UTF-8'}:</strong> {$mp_received.amount|escape:'htmlall':'UTF-8'}
        </p>
      {/if}
      <p class="text-muted">{$mp_received.help|escape:'htmlall':'UTF-8'}</p>
      <a class="btn btn-primary" href="{$mp_received.back_url|escape:'htmlall':'UTF-8'}">{$mp_received.back_label|escape:'htmlall':'UTF-8'}</a>
    </div>
  </section>
{/block}
