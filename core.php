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


// SNZ Pay (snzpay.digital) payment verification — POST JSON
// body: license_key, client_name, trx_id, method(BKASH/NAGAD/ROCKET/BINANCE/BANGLA_QR), amount
// success => status=success + data.amount | error => reason ba message
function snz_verify_payment(string $apiUrl, string $licenseKey, string $trxId, string $method, float $amount, string $clientName = ''): array {
    $allowed = ['BKASH', 'NAGAD', 'ROCKET', 'BINANCE', 'BANGLA_QR'];
    $m = strtoupper(str_replace([' ', '-'], '_', trim($method)));
    if (!in_array($m, $allowed, true)) {
        return ['ok' => false, 'amount' => 0.0, 'message' => 'Unsupported payment method for SNZ Pay.'];
    }
    if ($apiUrl === '') $apiUrl = 'https://snzpay.digital/api/verify_payment.php';

    // client_name: website domain / bot username / app name (SNZ Pay brand key er shathe dorkar)
    if ($clientName === '') {
        $host = defined('SITE_URL') ? (string)parse_url((string)SITE_URL, PHP_URL_HOST) : '';
        $clientName = $host !== '' ? $host : (defined('SITE_NAME') ? (string)SITE_NAME : 'keymanager');
    }
    $payload = [
        'license_key' => trim($licenseKey),
        'client_name' => $clientName,
        'trx_id'      => strtoupper(trim($trxId)),
        'method'      => $m,
        'amount'      => $amount
    ];
    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $res     = curl_exec($ch);
    $http    = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        return ['ok' => false, 'amount' => 0.0, 'message' => 'SNZ Pay connection error. Please try again.'];
    }
    $result = json_decode($res ?: '', true);
    if (!is_array($result)) {
        return ['ok' => false, 'amount' => 0.0, 'message' => 'Invalid response from SNZ Pay (HTTP ' . $http . ').'];
    }
    if (($result['status'] ?? '') === 'success') {
        $verified = isset($result['data']['amount']) && is_numeric($result['data']['amount']) ? (float)$result['data']['amount'] : $amount;
        return ['ok' => true, 'amount' => $verified, 'message' => (string)($result['message'] ?? 'Payment Verified')];
    }
    return ['ok' => false, 'amount' => 0.0, 'message' => (string)($result['reason'] ?? ($result['message'] ?? 'SNZ Pay Verification Failed! Invalid TrxID or Amount.'))];
}

require_once __DIR__ . '/reset_engine.php';
