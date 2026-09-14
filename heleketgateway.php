<?php
/**
 * Heleket Payment Gateway Module for WHMCS 9.x
 *
 * Accepts cryptocurrency payments via the Heleket payment processor.
 *
 * @see https://heleket.com
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require 'heleketgateway/vendor/autoload.php';

use WHMCS\Database\Capsule;

function heleketgateway_MetaData(): array
{
    return [
        'DisplayName' => 'Heleket',
        'APIVersion' => '1.1',
        'DisableLocalCreditCardInput' => true,
        'failedEmail' => 'Credit Card Payment Failed',
        'successEmail' => 'Invoice Payment Confirmation',
        'pendingEmail' => 'Credit Card Payment Pending',
        'TokenisedStorage' => false,
    ];
}

function heleketgateway_config(): array
{
    $hours = ['1' => '1 Hour'];
    for ($i = 2; $i <= 12; $i++) {
        $hours[$i] = $i . ' Hours';
    }

    return [
        'FriendlyName' => [
            'Type' => 'System',
            'Value' => 'Heleket',
        ],
        'apiKey' => [
            'FriendlyName' => 'API key',
            'Type' => 'password',
            'Description' => 'Enter your API key here',
        ],
        'payoutApiKey' => [
            'FriendlyName' => 'Payout API key',
            'Type' => 'password',
            'Description' => 'Required for refunds only. Heleket signs refund '
                . 'requests with the payout key, not the payment key. '
                . 'Generate it in Settings -> API (requires 2FA).',
        ],
        'merchantUuid' => [
            'FriendlyName' => 'Merchant ID',
            'Type' => 'text',
            'Description' => 'Enter your Merchant ID here',
        ],
        'subtract' => [
            'FriendlyName' => 'Subtract',
            'Type' => 'dropdown',
            'Options' => range(0, 100),
            'Description' => 'Percentage of the acceptance fee charged to the client',
        ],
        'lifetime' => [
            'FriendlyName' => 'Invoice Lifetime',
            'Type' => 'dropdown',
            'Options' => $hours,
            'Description' => '',
        ],
        'comissionMode' => [
            'FriendlyName' => 'Comission',
            'Type' => 'yesno',
            'Description' => 'Take into account commission on the client side?',
        ],
        'payNowLabel' => [
            'FriendlyName' => 'Pay Now Button Text',
            'Type' => 'text',
            'Default' => 'Pay Heleket',
            'Description' => 'Enter the text for the payment button',
        ],
        'convertToAdminCurrency' => [
            'FriendlyName' => 'Convert fiat to admin default currency',
            'Type' => 'yesno',
            'Description' => 'Convert fiat currency to default currency (Administration panel only)',
        ],
    ];
}

/**
 * Return a translated UI string for the Heleket gateway.
 *
 * @param string $key One of: changeCoin, changeCoinTitle
 * @return string
 */
function heleketgateway_translate(string $key): string
{
    static $strings = [
        'arabic'        => ['changeCoin' => 'تغيير العملة / الشبكة',   'changeCoinTitle' => 'إنشاء دفعة جديدة لاختيار عملة رقمية أو شبكة أخرى'],
        'azerbaijani'   => ['changeCoin' => 'Valyutanı / şəbəkəni dəyiş', 'changeCoinTitle' => 'Başqa kriptovalyuta və ya şəbəkə seçmək üçün yeni ödəniş yarat'],
        'catalan'       => ['changeCoin' => 'Canviar moneda / xarxa',  'changeCoinTitle' => 'Crear un nou pagament per escollir una altra criptomoneda o xarxa'],
        'chinese'       => ['changeCoin' => '更换币种 / 网络',           'changeCoinTitle' => '创建新支付以选择其他加密货币或网络'],
        'croatian'      => ['changeCoin' => 'Promijeni valutu / mrežu','changeCoinTitle' => 'Stvori novu uplatu za odabir druge kriptovalute ili mreže'],
        'czech'         => ['changeCoin' => 'Změnit minci / síť',     'changeCoinTitle' => 'Vytvořit novou platbu pro výběr jiné kryptoměny nebo sítě'],
        'danish'        => ['changeCoin' => 'Skift mønt / netværk',    'changeCoinTitle' => 'Opret en ny betaling for at vælge en anden kryptovaluta eller netværk'],
        'dutch'         => ['changeCoin' => 'Munt / netwerk wijzigen', 'changeCoinTitle' => 'Maak een nieuwe betaling aan om een andere cryptomunt of netwerk te kiezen'],
        'english'       => ['changeCoin' => 'Change coin / network',   'changeCoinTitle' => 'Create a new payment to choose a different cryptocurrency or network'],
        'estonian'      => ['changeCoin' => 'Vaheta münti / võrku',    'changeCoinTitle' => 'Loo uus makse, et valida teine krüptovaluuta või võrk'],
        'farsi'         => ['changeCoin' => 'تغییر ارز / شبکه',        'changeCoinTitle' => 'ایجاد پرداخت جدید برای انتخاب ارز دیجیتال یا شبکه دیگر'],
        'french'        => ['changeCoin' => 'Changer de monnaie / réseau', 'changeCoinTitle' => 'Créer un nouveau paiement pour choisir une autre cryptomonnaie ou réseau'],
        'german'        => ['changeCoin' => 'Coin / Netzwerk ändern',  'changeCoinTitle' => 'Neue Zahlung erstellen, um eine andere Kryptowährung oder ein anderes Netzwerk zu wählen'],
        'hebrew'        => ['changeCoin' => 'שנה מטבע / רשת',          'changeCoinTitle' => 'צור תשלום חדש כדי לבחור מטבע קריפטו או רשת אחרת'],
        'hungarian'     => ['changeCoin' => 'Érme / hálózat váltása',  'changeCoinTitle' => 'Új fizetés létrehozása más kriptovaluta vagy hálózat választásához'],
        'italian'       => ['changeCoin' => 'Cambia moneta / rete',    'changeCoinTitle' => 'Crea un nuovo pagamento per scegliere una criptovaluta o rete diversa'],
        'macedonian'    => ['changeCoin' => 'Промени монета / мрежа',  'changeCoinTitle' => 'Креирај ново плаќање за избор на друга криптовалута или мрежа'],
        'norwegian'     => ['changeCoin' => 'Endre mynt / nettverk',   'changeCoinTitle' => 'Opprett en ny betaling for å velge en annen kryptovaluta eller nettverk'],
        'portuguese-br' => ['changeCoin' => 'Alterar moeda / rede',    'changeCoinTitle' => 'Criar um novo pagamento para escolher outra criptomoeda ou rede'],
        'portuguese-pt' => ['changeCoin' => 'Alterar moeda / rede',    'changeCoinTitle' => 'Criar um novo pagamento para escolher outra criptomoeda ou rede'],
        'romanian'      => ['changeCoin' => 'Schimbă moneda / rețeaua','changeCoinTitle' => 'Creează o nouă plată pentru a alege o altă criptomonedă sau rețea'],
        'russian'       => ['changeCoin' => 'Сменить монету / сеть',   'changeCoinTitle' => 'Создать новый платёж, чтобы выбрать другую криптовалюту или сеть'],
        'spanish'       => ['changeCoin' => 'Cambiar moneda / red',    'changeCoinTitle' => 'Crear un nuevo pago para elegir otra criptomoneda o red'],
        'swedish'       => ['changeCoin' => 'Byt mynt / nätverk',      'changeCoinTitle' => 'Skapa en ny betalning för att välja en annan kryptovaluta eller nätverk'],
        'turkish'       => ['changeCoin' => 'Para birimi / ağı değiştir', 'changeCoinTitle' => 'Farklı bir kripto para veya ağ seçmek için yeni ödeme oluştur'],
        'ukranian'      => ['changeCoin' => 'Змінити монету / мережу', 'changeCoinTitle' => 'Створити новий платіж, щоб обрати іншу криптовалюту або мережу'],
    ];

    $lang = '';

    if (!empty($_SESSION['Language'])) {
        $lang = strtolower($_SESSION['Language']);
    }

    if ($lang === '' && !empty($GLOBALS['_LANG']['locale'])) {
        $localeMap = [
            'ar_AR' => 'arabic',     'az_AZ' => 'azerbaijani', 'ca_ES' => 'catalan',
            'zh_CN' => 'chinese',    'hr_HR' => 'croatian',    'cs_CZ' => 'czech',
            'da_DK' => 'danish',     'nl_NL' => 'dutch',       'en_001'=> 'english',
            'et_EE' => 'estonian',   'fa_IR' => 'farsi',       'fr_FR' => 'french',
            'de_DE' => 'german',     'he_IL' => 'hebrew',      'hu_HU' => 'hungarian',
            'it_IT' => 'italian',    'mk_MK' => 'macedonian',  'nb_NO' => 'norwegian',
            'pt_BR' => 'portuguese-br', 'pt_PT' => 'portuguese-pt',
            'ro_RO' => 'romanian',   'ru_RU' => 'russian',     'es_ES' => 'spanish',
            'sv_SE' => 'swedish',    'tr_TR' => 'turkish',     'uk_UA' => 'ukranian',
        ];
        $lang = $localeMap[$GLOBALS['_LANG']['locale']] ?? '';
    }

    if ($lang === '') {
        $lang = 'english';
    }

    return $strings[$lang][$key] ?? $strings['english'][$key] ?? '';
}

/**
 * Renewal-counter storage.
 *
 * WHMCS 9 treats non-draft invoices as immutable, and adding custom columns to
 * tblinvoices at runtime (as the 8.x build did) is fragile across upgrades.
 * Keep the counter in the module's own table instead.
 */
function heleketgateway_stateTable(): string
{
    $table = 'mod_heleketgateway_state';

    static $ensured = false;
    if (!$ensured) {
        $ensured = true;
        if (!Capsule::schema()->hasTable($table)) {
            Capsule::schema()->create($table, function ($t) {
                $t->unsignedInteger('invoiceid')->primary();
                $t->unsignedInteger('renew_count')->default(0);
            });
        }
    }

    return $table;
}

function heleketgateway_renewCount(int $invoiceId): int
{
    $row = Capsule::table(heleketgateway_stateTable())
        ->where('invoiceid', $invoiceId)
        ->first();

    return (int)($row->renew_count ?? 0);
}

function heleketgateway_bumpRenewCount(int $invoiceId): int
{
    $next = heleketgateway_renewCount($invoiceId) + 1;

    Capsule::table(heleketgateway_stateTable())->updateOrInsert(
        ['invoiceid' => $invoiceId],
        ['renew_count' => $next]
    );

    return $next;
}

/**
 * Look up the wallet address the customer paid from.
 *
 * A payment record carries two addresses and they are NOT interchangeable:
 *
 *   address -> the invoice deposit address, i.e. where the customer sent funds
 *   from    -> the customer's own wallet, i.e. where a refund has to go
 *
 * `from` is null when the payment was made p2p (funds moved inside Heleket
 * without a blockchain transaction) — then there is no payer address at all.
 *
 * @param \Heleket\Api\Payment $payment Client built with the PAYMENT api key
 */
function heleketgateway_lookupPayerAddress(\Heleket\Api\Payment $payment, string $orderId): ?string
{
    try {
        $info = $payment->info(['order_id' => $orderId]);
    } catch (\Exception $e) {
        logModuleCall('Heleket', 'refund-info', ['order_id' => $orderId], $e->getMessage());
        return null;
    }

    if (is_array($info) && !empty($info['from']) && is_string($info['from'])) {
        return $info['from'];
    }

    return null;
}

/**
 * Process a refund initiated from the WHMCS admin area.
 *
 * WHMCS passes a single $params array — see the Refunds page of the gateway
 * developer docs. $params carries invoiceid, transid, amount and currency.
 *
 * Heleket signs refunds with the PAYOUT api key, not the payment key: a refund
 * is a payout-family operation on their side. payment/info still needs the
 * payment key, so two clients are built here.
 *
 * NOT YET VERIFIED against a live merchant account — test with a real refund
 * before relying on it.
 *
 * @param array $params WHMCS gateway parameters
 * @return array status / rawdata / transid
 */
function heleketgateway_refund(array $params): array
{
    $apiKey       = $params['apiKey'];
    $payoutApiKey = $params['payoutApiKey'] ?? '';
    $merchantUuid = $params['merchantUuid'];
    $invoiceId    = $params['invoiceid'];
    $amount       = $params['amount'] ?? null;
    $orderId      = 'whmcs_' . $invoiceId;

    if ($payoutApiKey === '') {
        return [
            'status'  => 'error',
            'rawdata' => 'Refunds require the Payout API key. Add it in Setup -> Payments '
                       . '-> Payment Gateways -> Heleket.',
        ];
    }

    $paymentClient = \Heleket\Api\Client::payment($apiKey, $merchantUuid);
    $refundClient  = \Heleket\Api\Client::payment($payoutApiKey, $merchantUuid);

    $address = heleketgateway_lookupPayerAddress($paymentClient, $orderId);

    if ($address === null) {
        logModuleCall(
            'Heleket',
            'refund',
            ['invoiceId' => $invoiceId, 'orderId' => $orderId, 'amount' => $amount],
            'Payer address (from) not available - cannot refund'
        );

        return [
            'status'  => 'error',
            'rawdata' => 'Cannot refund: the payer wallet address is unknown. This happens when '
                       . 'the invoice was never paid, or was paid p2p from a Heleket balance, '
                       . 'in which case there is no wallet to refund to.',
        ];
    }

    $request = [
        'order_id'    => $orderId,
        'address'     => $address,
        'is_subtract' => true,
    ];

    // Heleket refunds the whole payment when amount is omitted.
    if ($amount !== null && (float)$amount > 0) {
        $request['amount'] = (string)$amount;
    }

    $logged = ['invoiceId' => $invoiceId, 'orderId' => $orderId,
               'amount' => $amount, 'address' => $address];

    try {
        $result = $refundClient->refund($request);
    } catch (\Exception $e) {
        logModuleCall('Heleket', 'refund', $logged, $e->getMessage() . ' (HTTP ' . $e->getCode() . ')');

        return ['status' => 'error', 'rawdata' => $e->getMessage()];
    }

    logModuleCall('Heleket', 'refund', $logged, is_string($result) ? $result : json_encode($result));

    return [
        'status'  => 'success',
        'rawdata' => is_string($result) ? $result : json_encode($result),
        'transid' => $params['transid'] ?? '',
    ];
}

/**
 * Generate payment button HTML and handle payment creation via Heleket API.
 *
 * @param array $params WHMCS gateway parameters
 * @return string HTML output
 * @throws \Heleket\Api\RequestBuilderException
 */
function heleketgateway_link(array $params): string
{
    $apiKey       = $params['apiKey'];
    $merchantUuid = $params['merchantUuid'];
    $langPayNow   = $params['payNowLabel'] ?? 'Pay Heleket';
    $invoiceId    = $params['invoiceid'];
    $amount       = $params['amount'];
    $currencyCode = $params['currency'];
    $lifetime     = $params['lifetime'] ?? 12;
    $systemUrl    = $params['systemurl'];
    $returnUrl    = $params['returnurl'];
    $moduleName   = $params['paymentmethod'];
    $whmcsVersion = $params['whmcsVersion'];

    $data = [
        'amount'              => (string)$amount,
        'currency'            => $currencyCode,
        'order_id'            => 'whmcs_' . $invoiceId,
        'url_return'          => $returnUrl,
        'url_callback'        => $systemUrl . '/modules/gateways/callback/' . $moduleName . '.php',
        'is_payment_multiple' => true,
        'lifetime'            => (string)(3600 * (int)$lifetime),
        'is_refresh'          => false,
        'whmcs_version'       => $whmcsVersion,
        'plugin_name'         => 'whmcs_9',
    ];

    // ------------------------------------------------------------------
    // Coin/network change: store a counter in the DB so page refreshes
    // do not keep generating new Heleket invoices.
    // ------------------------------------------------------------------
    $renewCount = heleketgateway_renewCount($invoiceId);

    if (!empty($_GET['hk_new'])) {
        $renewCount = heleketgateway_bumpRenewCount($invoiceId);
    }

    if ($renewCount > 0) {
        $data['order_id']   = 'whmcs_' . $invoiceId . '_r' . $renewCount;
        $data['is_refresh'] = false;
    } else {
        $data['is_refresh'] = true;
    }

    if (isset($params['subtract'])) {
        $data['subtract'] = $params['subtract'];
    }

    $payment = \Heleket\Api\Client::payment($apiKey, $merchantUuid);

    try {
        $paymentCreate = $payment->create($data);
    } catch (\Exception $e) {
        logModuleCall('Heleket', 'link', ['invoiceId' => $invoiceId], $e->getMessage());
        return 'Error processing payment: ' . $e->getMessage();
    }

    // ------------------------------------------------------------------
    // Amount-change protection: if the invoice total was modified after
    // the Heleket payment was created (e.g. by a hook), create a new
    // payment — but only when the client hasn't chosen a coin yet.
    // ------------------------------------------------------------------
    if (
        empty($_GET['hk_new'])
        && isset($paymentCreate['amount'])
        && bccomp((string)$amount, (string)$paymentCreate['amount'], 2) !== 0
    ) {
        try {
            $existingInfo = $payment->info(['order_id' => $data['order_id']]);
        } catch (\Exception $e) {
            $existingInfo = null;
        }

        $canReplace = $existingInfo
            && ($existingInfo['status'] ?? '') === 'process'
            && empty($existingInfo['network']);

        if ($canReplace) {
            $renewCount = heleketgateway_bumpRenewCount($invoiceId);

            $data['order_id']   = 'whmcs_' . $invoiceId . '_r' . $renewCount;
            $data['is_refresh'] = false;

            try {
                $paymentCreate = $payment->create($data);
            } catch (\Exception $e) {
                logModuleCall('Heleket', 'link-amount-update', ['invoiceId' => $invoiceId], $e->getMessage());
            }
        }
    }

    // ------------------------------------------------------------------
    // Build HTML output
    // ------------------------------------------------------------------
    if (empty($paymentCreate['url'])) {
        logModuleCall('Heleket', 'link', ['invoiceId' => $invoiceId], $paymentCreate);
        return 'Error processing payment: gateway did not return a payment URL.';
    }

    $htmlOutput  = '<form action="' . htmlspecialchars($paymentCreate['url'], ENT_QUOTES) . '">';
    $htmlOutput .= '<input type="submit" value="' . htmlspecialchars($langPayNow, ENT_QUOTES) . '"/>';
    $htmlOutput .= '</form>';

    $changeCoinText  = heleketgateway_translate('changeCoin');
    $changeCoinTitle = heleketgateway_translate('changeCoinTitle');

    $currentUri  = strtok($_SERVER['REQUEST_URI'] ?? '', '?');
    $queryParams = $_GET;
    $queryParams['hk_new'] = '1';
    $renewUrl    = $currentUri . '?' . http_build_query($queryParams);

    $htmlOutput .= '<div style="margin-top:6px;text-align:center;">';
    $htmlOutput .= '<a href="' . htmlspecialchars($renewUrl, ENT_QUOTES) . '" '
        . 'style="font-size:12px;color:#888;text-decoration:underline;" '
        . 'title="' . htmlspecialchars($changeCoinTitle, ENT_QUOTES) . '">';
    $htmlOutput .= htmlspecialchars($changeCoinText, ENT_QUOTES);
    $htmlOutput .= '</a></div>';

    return $htmlOutput;
}
