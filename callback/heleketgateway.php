<?php
/**
 * Heleket Payment Gateway — Callback Handler for WHMCS 9.x
 *
 * Processes payment notifications (webhooks) from the Heleket API
 * and applies payments to the corresponding WHMCS invoices.
 *
 * @see https://heleket.com
 */

// Only these three are part of the documented WHMCS callback contract. The 8.x
// build also pulled in includes/functions.php, which is not in WHMCS's own
// sample callback — a failed require_once there kills the webhook before any
// processing happens.
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

use WHMCS\Database\Capsule;

$json = file_get_contents('php://input');
$data = json_decode($json, true);

$gatewayModuleName = basename(__FILE__, '.php');

require __DIR__ . '/../' . $gatewayModuleName . '/vendor/autoload.php';

$gatewayParams = getGatewayVariables($gatewayModuleName);

if (!$gatewayParams['type']) {
    http_response_code(403);
    die('Module Not Activated');
}

// Verify webhook signature.
//
// JSON_UNESCAPED_UNICODE is required: Heleket signs the payload with it (see
// their webhook documentation). Without the flag any non-ASCII field — an
// additional_data with Cyrillic, for example — encodes to \uXXXX escapes here
// but not on their side, the signature mismatches, and a real payment is
// rejected with a 403 and never credited.
$sign = (string) ($data['sign'] ?? '');
unset($data['sign']);

$expectedSign = md5(
    base64_encode(json_encode($data, JSON_UNESCAPED_UNICODE)) . $gatewayParams['apiKey']
);

if (!hash_equals($expectedSign, $sign)) {
    http_response_code(403);
    die('Hash Verification Failure');
}

// `wrong_amount` means the client paid LESS than requested. It must never be
// treated as a successful payment: crediting it would settle the invoice in
// full for a partial payment and trigger provisioning. Do not re-add it here.
$success = !empty($data['is_final'])
    && in_array($data['status'] ?? '', ['paid', 'paid_over'], true);

// Extract WHMCS invoice ID from the order_id.
// Strips prefixes (whmcs_, whmcs_upd_) and renewal suffix (_r{N}).
$invoiceId = preg_replace('/^whmcs(?:_upd)?_/', '', $data['order_id'] ?? '');
$invoiceId = preg_replace('/_r\d+$/', '', $invoiceId);

// txid is absent for p2p payments (the payer sent funds from their Heleket
// balance, so there is no blockchain hash). Fall back to the payment uuid —
// an empty transaction id silently disables WHMCS's duplicate-payment guard.
$paymentReference = (string) ($data['txid'] ?? '');
if ($paymentReference === '') {
    $paymentReference = (string) ($data['uuid'] ?? '');
}

$transactionId = $paymentReference !== '' ? ($invoiceId . '_' . $paymentReference) : '';

$invoiceId         = checkCbInvoiceID($invoiceId, $gatewayParams['name']);
$comission         = $gatewayParams['comissionMode'];
$convertToAdmin    = $gatewayParams['convertToAdminCurrency'];
$transactionStatus = $success ? 'Success' : 'Failure';

if ($success && $transactionId) {
    checkCbTransID($transactionId);
    logTransaction($gatewayParams['name'], $data, $transactionStatus);
}

if ($success) {
    $paymentAmount = $data['amount'];

    if ($data['status'] === 'paid_over' && isset($data['currency'])) {
        if (mb_strtoupper($data['currency']) === 'USD' && isset($data['payment_amount_usd'])) {
            $paymentAmount = $data['payment_amount_usd'];
        } else {
            $paymentAmount = ($comission === 'on')
                ? convert($data['payer_currency'], $data['currency'], $data['payment_amount'])
                : convert($data['payer_currency'], $data['currency'], $data['merchant_amount']);
        }

        $ACCURACY_LIMIT_IN_USD = 1.0;
        $paidInUSD   = convert($data['currency'], 'USD', $paymentAmount);
        $neededInUSD = convert($data['currency'], 'USD', $data['amount']);

        if (abs($neededInUSD - $paidInUSD) <= $ACCURACY_LIMIT_IN_USD) {
            $paymentAmount = $data['amount'];
        }

        if ($paymentAmount < $data['amount']) {
            $paymentAmount = $data['amount'];
        }
    }

    if ($convertToAdmin === 'on') {
        $defaultCurrency = Capsule::table('tblcurrencies')
            ->where('default', 1)
            ->first();

        $paymentAmount = convert($data['currency'], $defaultCurrency->code, $paymentAmount);
    }

    addInvoicePayment(
        $invoiceId,
        $transactionId,
        $paymentAmount,
        '0.00',
        $gatewayModuleName
    );
}

/**
 * Convert an amount between currencies using the Heleket exchange-rate API.
 *
 * @param string $from   Source currency code
 * @param string $to     Target currency code
 * @param float  $amount Amount to convert
 * @return float|null
 */
function convert($from, $to, $amount)
{
    if (mb_strtoupper($from) === mb_strtoupper($to)) {
        return $amount;
    }

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL            => "https://api.heleket.com/v1/exchange-rate/$from/list",
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $response = curl_exec($curl);
    curl_close($curl);

    if ($response === false) {
        return null;
    }

    $result = json_decode($response, true);

    if (empty($result['result'])) {
        return null;
    }

    foreach ($result['result'] as $item) {
        if ($item['to'] === mb_strtoupper($to)) {
            return bcmul($item['course'], $amount, 2);
        }
    }

    return null;
}

die('OK');
