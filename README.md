# Heleket Gateway for WHMCS 9

A payment gateway module for accepting crypto payments via [Heleket](https://heleket.com) in [WHMCS](https://www.whmcs.com) **9.x**.

Supports BTC, ETH, USDT (TRC20/ERC20/BEP20), TON, LTC, BNB, TRX and others — the available currencies are defined by your Heleket merchant cabinet settings.

---

## Features

- Invoice creation via the Heleket Hosted Payment Page (customer picks the coin and network)
- Webhook handling with signature verification (MD5, official Heleket algorithm)
- **"Change coin / network"** link under the pay button, localised into 26 WHMCS languages
- Refunds straight from the WHMCS admin area (requires the Payout API key)
- Overpayment (`paid_over`) reconciliation with exchange-rate conversion
- Optional conversion of the payment into the admin default currency
- Pass the Heleket fee to the customer (`subtract`, 0–100%)
- Configurable invoice lifetime (1–12 hours)
- Everything is logged to **Utilities → Logs → Module Log**

---

## Requirements

- WHMCS **9.x**
- PHP **8.2+** with `curl` and `bcmath`
- Your WHMCS install must be reachable over HTTPS from the public internet (Heleket delivers webhooks to it)

---

## Installation

1. **Download** `heleketgateway-whmcs9.zip` from [Releases](../../releases), or clone this repository.

2. **Copy** the three entries into your WHMCS gateways directory:

   ```
   <whmcs>/modules/gateways/heleketgateway.php
   <whmcs>/modules/gateways/heleketgateway/
   <whmcs>/modules/gateways/callback/heleketgateway.php
   ```

   The `heleketgateway/` folder already contains `vendor/` with the Heleket PHP SDK — no `composer install` is needed.

3. **Activate** the gateway:
   `Setup → Payments → Payment Gateways → All Payment Gateways → Heleket`

On first use the module creates its own table, `mod_heleketgateway_state` (a per-invoice counter for the "change coin" flow). No other WHMCS tables are touched.

---

## Configuration

### 1. Get your Heleket keys

In the Heleket cabinet: **Settings → API**. You will need:

- **Merchant ID** — your merchant UUID
- **API key** — the payment key, used to sign payment requests and to verify webhooks
- **Payout API key** — only if you want refunds; generating it requires 2FA

### 2. Fill in the settings

`Setup → Payments → Payment Gateways → Heleket`:

| Field | Description |
|---|---|
| **API key** | Payment API key from Heleket |
| **Payout API key** | Required for refunds only — Heleket signs refund requests with the payout key, not the payment key |
| **Merchant ID** | Your merchant UUID from Heleket |
| **Subtract** | Percentage of the acceptance fee charged to the client (0–100). `100` means the customer pays the entire fee |
| **Invoice Lifetime** | How long the payment link stays active, 1–12 hours |
| **Comission** | Take the commission into account on the client side when reconciling an overpayment |
| **Pay Now Button Text** | Label of the payment button, default `Pay Heleket` |
| **Convert fiat to admin default currency** | Convert the received amount into the WHMCS default currency before it is applied to the invoice |

### 3. Webhook URL — no setup required

The module passes `url_callback` in every payment-creation request, so Heleket knows where to deliver notifications without any manual setup in its cabinet:

```
https://your-whmcs-domain.com/modules/gateways/callback/heleketgateway.php
```

---

## Payment flow

1. The customer opens the invoice and clicks the Heleket button
2. They are redirected to the Heleket page and pick a coin and network
3. They send the payment to the generated address
4. Heleket notifies the callback URL via webhook
5. Once the status is final and `paid` / `paid_over`, the payment is applied to the invoice

Orders are submitted as `whmcs_{invoiceId}`. If the customer uses the **"Change coin / network"** link, a fresh Heleket payment is created as `whmcs_{invoiceId}_r{N}` — the counter lives in `mod_heleketgateway_state`, so simply refreshing the invoice page never spawns new payments. The callback strips both the prefix and the `_r{N}` suffix before resolving the WHMCS invoice.

---

## Heleket status handling

| Heleket status | Module action |
|---|---|
| `paid`, `paid_over` (with `is_final`) | Payment applied to the invoice, transaction logged as `Success` |
| `wrong_amount`, `wrong_amount_waiting` | Not credited — the customer paid less than requested; Heleket keeps waiting for a top-up |
| `process`, `check`, `confirm_check`, `confirmations_waiting` | Nothing applied yet, webhook answered with `OK` |
| `fail`, `cancel`, `system_fail`, `locked` | Not credited, invoice stays unpaid |

`paid_over` amounts are normalised before they hit the invoice: for USD invoices the module uses `payment_amount_usd`, otherwise it converts the payer currency through the Heleket exchange-rate API (honouring the **Comission** setting). Differences within $1 are rounded to the invoiced amount, and the credited amount is never lower than the invoice total.

---

## Refunds

`Invoice → Transactions → Refund` in the WHMCS admin area.

- Requires the **Payout API key** — the payment key cannot sign a refund
- The destination address is the payer wallet (`from`) taken from `payment/info`
- A refund is impossible for **p2p payments** (the customer paid from a Heleket balance, so no blockchain address exists) — the module reports this instead of failing silently
- Leaving the amount empty refunds the whole payment

> Refunds are implemented against the documented Heleket API but have not been verified against a live merchant account. Test with a real refund before relying on them.

---

## Security

- All outgoing requests are signed: `md5(base64(json) + api_key)`
- Incoming webhooks are verified with the same algorithm, compared with `hash_equals`; an invalid signature is rejected with `HTTP 403`
- The payload is encoded with `JSON_UNESCAPED_UNICODE` — Heleket signs it that way, and without the flag any non-ASCII field would break verification on a genuine payment
- Transaction IDs are `{invoiceId}_{txid}` (falling back to the payment UUID for p2p payments), so WHMCS's own duplicate-payment guard stays effective

---

## Diagnostics

Everything is written to the WHMCS module log:

`Utilities → Logs → Module Log` → filter by `Heleket`

| Log entry | Meaning |
|---|---|
| `link` | Payment creation failed — the message holds the Heleket API error |
| `link-amount-update` | The invoice total changed and the module tried to re-create the payment |
| `refund`, `refund-info` | Refund attempt and payer-address lookup |

A webhook rejected with `Hash Verification Failure` means the API key in the module settings does not match the merchant the payment was created for.

---

## Building the ZIP

```bash
./build.sh
```

Produces `heleketgateway-whmcs9.zip` with the same layout as the release archive.

---

## Support

- Heleket API documentation: <https://heleket.com/docs>
- Heleket cabinet: <https://dash.heleket.com>

The bundled `heleket/api-php-sdk` is MIT-licensed; the module itself is published by Heleket.com (`whmcs.json` declares `proprietary`).
