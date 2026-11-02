<?php
// ====================================================================
// CORE.PHP — SHARED HELPERS (loaded by config.php)
// Koner kono table/ALTER ekhane nei: sob table database.sql e ache.
// ====================================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Telegram API Helper
function tgApiRequest(string $method, array $data = []): array {
    $ch = curl_init(TG_API . $method);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($data),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 15
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res ?: '{}', true) ?: [];
}

// Activity Logging Helper
function logActivity(PDO $pdo, ?int $userId, string $username, string $action, string $details): void {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
        $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, username, action, details, ip_address) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$userId, $username, $action, $details, $ip]);
    } catch (Exception $e) {}
}

// License Key Generator Helper
function generateLicenseKey(): string {
    return strtoupper(bin2hex(random_bytes(4)) . '-' . bin2hex(random_bytes(4)) . '-' . bin2hex(random_bytes(4)));
}

// Provider (LicenseManager) base url + token helpers
function lm_provider_base(): string {
    if (defined('PROVIDER_API_BASE')) return rtrim(PROVIDER_API_BASE, '/');
    if (defined('LM_API_BASE'))       return rtrim(LM_API_BASE, '/');
    return 'https://licensemanager.shop/api/v1';
}

function lm_provider_token(): string {
    if (defined('PROVIDER_API_TOKEN')) return (string)PROVIDER_API_TOKEN;
    if (defined('LM_API_TOKEN'))       return (string)LM_API_TOKEN;
    return '';
}

// External Provider License Generator (LicenseManager API) — strict, real keys only
function generateFromExternalProvider($apiPlanId, int $quantity = 1): array {
    $token = lm_provider_token();
    if ($token === '') {
        return ['status' => 'error', 'message' => 'Provider API Token is not configured in config.php'];
    }

    $reqQty = max(1, min(100, $quantity));
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => lm_provider_base() . '/generate_key.php',
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(['product_id' => (int)$apiPlanId, 'quantity' => $reqQty]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 35,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        CURLOPT_HTTPHEADER     => ['X-API-Token: ' . $token, 'Accept: application/json'],
    ]);
    $body    = curl_exec($ch);
    $http    = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        return ['status' => 'error', 'message' => 'Provider connection error: ' . $curlErr];
    }

    $data = json_decode($body ?: '', true);
    if (!is_array($data)) {
        return ['status' => 'error', 'message' => 'Invalid response from provider API (HTTP ' . $http . ')'];
    }
    if ($http !== 200) {
        $m = $data['error'] ?? ($data['message'] ?? ('Provider server returned HTTP ' . $http));
        return ['status' => 'error', 'message' => (string)$m];
    }

    $extractedKeys = [];
    if (!empty($data['keys']) && is_array($data['keys'])) {
        foreach ($data['keys'] as $item) {
            if (is_array($item) && !empty($item['key'])) {
                $k = trim((string)$item['key']);
            } elseif (is_string($item)) {
                $k = trim($item);
            } else {
                continue;
            }
            if ($k !== '') $extractedKeys[] = $k;
        }
    } elseif (!empty($data['key']) && is_string($data['key'])) {
        $k = trim($data['key']);
        if ($k !== '') $extractedKeys[] = $k;
    }

    if (!empty($extractedKeys) && count($extractedKeys) === $reqQty) {
        foreach ($extractedKeys as $valKey) {
            if (strlen($valKey) < 5 || stripos($valKey, 'FAKE') !== false || stripos($valKey, 'DUMMY') !== false) {
                return ['status' => 'error', 'message' => 'Provider returned corrupt key data'];
            }
        }
        return [
            'status'         => 'success',
            'keys'           => $extractedKeys,
            'amount_charged' => $data['amount_charged'] ?? 0,
            'new_balance'    => $data['new_balance'] ?? 0
        ];
    }

    $errorMsg = $data['error'] ?? ($data['message'] ?? 'Provider failed to supply license keys (Stock Out)');
    return ['status' => 'error', 'message' => (string)$errorMsg];
}

// Live Provider Balance Fetcher
function lm_get_provider_balance(): string {
    $token = lm_provider_token();
    if ($token === '') return '$0.00';

    $ch = curl_init(lm_provider_base() . '/balance.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 6,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => ['X-API-Token: ' . $token, 'Accept: application/json']
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response ?: '', true);
    if (!empty($data['balance'])) {
        return '$' . number_format((float)$data['balance'], 2) . ' USD';
    }
    return '$0.00';
}


// SNZ Pay (snzpay.digital) payment verification — POST (form), official latest doc
// post: license_key, client_name, method, trx_id, amount (0 = amount check hobe na)
// success => status=success + data.{amount(BDT), trx_id, sender, method} | error => reason ba message
// client_name = site domain (Brand Key ekbar bind hoy, tai ekoi thakte hobe) — auto: SITE_URL er host.
function snz_client_name(): string {
    if (defined('SNZPAY_CLIENT_NAME') && SNZPAY_CLIENT_NAME !== '') return (string)SNZPAY_CLIENT_NAME;
    $host = defined('SITE_URL') ? (string)parse_url((string)SITE_URL, PHP_URL_HOST) : '';
    $host = strtolower(preg_replace('/^www\./i', '', $host));
    return $host !== '' ? $host : 'keymanager.shop';
}

function snz_verify_payment(string $apiUrl, string $licenseKey, string $trxId, string $method, float $amount = 0.0, string $clientName = ''): array {
    $allowed = ['bkash', 'nagad', 'rocket', 'upay', 'binance', 'bangla_qr'];
    $m = strtolower(str_replace([' ', '-'], '_', trim($method)));
    if (!in_array($m, $allowed, true)) {
        return ['ok' => false, 'amount' => 0.0, 'message' => 'Unsupported payment method for SNZ Pay.', 'data' => []];
    }
    if ($apiUrl === '') $apiUrl = 'https://snzpay.digital/api/verify_payment.php';
    if ($clientName === '') $clientName = snz_client_name();
    // user jodi "https://site.com/" likhe fele, domain-e normalize
    $clientName = strtolower(trim($clientName));
    $clientName = preg_replace('#^https?://#', '', $clientName);
    $clientName = rtrim($clientName, '/');

    $post = [
        'license_key' => trim($licenseKey),
        'method'      => $m,
        'trx_id'      => strtoupper(trim($trxId)),
        'amount'      => $amount,
        'client_name' => $clientName,
    ];
    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($post),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $res     = curl_exec($ch);
    $http    = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($res === false) {
        return ['ok' => false, 'amount' => 0.0, 'message' => 'SNZ Pay server not reachable: ' . $curlErr, 'data' => []];
    }
    $result = json_decode($res, true);
    if (!is_array($result)) {
        return ['ok' => false, 'amount' => 0.0, 'message' => 'Invalid response from SNZ Pay (HTTP ' . $http . ').', 'data' => []];
    }
    if (($result['status'] ?? '') === 'success') {
        $data = is_array($result['data'] ?? null) ? $result['data'] : [];
        $verified = isset($data['amount']) && is_numeric($data['amount']) ? (float)$data['amount'] : $amount;
        return ['ok' => true, 'amount' => $verified, 'message' => (string)($result['message'] ?? 'Payment Verified'), 'data' => $data];
    }
    return ['ok' => false, 'amount' => 0.0,
        'message' => (string)($result['reason'] ?? ($result['message'] ?? 'SNZ Pay Verification Failed! Invalid TrxID or Amount.')),
        'data' => $result];
}

require_once __DIR__ . '/reset_engine.php';
