<?php
// ====================================================================
// CORE.PHP — SHARED HELPERS (loaded by config.php)
// Koner kono table/ALTER ekhane nei: sob table database.sql e ache.
// ====================================================================

if (session_status() === PHP_SESSION_NONE) {
    // Session 24 ghonta cholbe (Telegram verify bar bar lagbe na)
    ini_set('session.gc_maxlifetime', '172800');
    session_set_cookie_params([
        'lifetime' => 86400,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
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


// ====================================================================
// PRICING — reseller nijer sub-reseller der price set korle (Price Edit)
// ====================================================================
/** Parent er set kora price: sub-specific age, na thakle "All subs" (sub_user_id = 0). */
function km_parent_price(PDO $pdo, int $parentId, int $subId, int $planId): ?float {
    $st = $pdo->prepare("SELECT price FROM sub_reseller_pricing WHERE parent_id = ? AND plan_id = ? AND sub_user_id IN (?, 0) ORDER BY sub_user_id DESC LIMIT 1");
    $st->execute([$parentId, $planId, $subId]);
    $v = $st->fetchColumn();
    return $v === false ? null : (float)$v;
}

/** planId => price (ei sub er jonno parent er set kora sob price). */
function km_parent_prices_map(PDO $pdo, int $parentId, int $subId): array {
    $st = $pdo->prepare("SELECT plan_id, price, sub_user_id FROM sub_reseller_pricing WHERE parent_id = ? AND sub_user_id IN (?, 0) ORDER BY sub_user_id ASC");
    $st->execute([$parentId, $subId]);
    $map = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $map[(int)$r['plan_id']] = (float)$r['price']; // specific (sub_user_id>0) pore ashe, override kore
    return $map;
}

/** Ekta user ekta plan e final koto dam dey (admin custom + discount + parent price shoho). */
function km_deal_price_for_user(PDO $pdo, array $u, int $planId, int $depth = 0): ?float {
    $st = $pdo->prepare("SELECT pl.reseller_price, pl.user_price,
                                COALESCE(pl.discount_percent,0) AS plan_discount, COALESCE(p.discount_percent,0) AS prod_discount,
                                COALESCE(upp.custom_price,0) AS custom_price, COALESCE(upp.custom_discount,0) AS custom_discount
                         FROM product_plans pl JOIN products p ON pl.product_id = p.id
                         LEFT JOIN user_plan_pricing upp ON (upp.plan_id = pl.id AND upp.user_id = ?)
                         WHERE pl.id = ? LIMIT 1");
    $st->execute([(int)$u['id'], $planId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;

    $isReseller = in_array(strtolower(trim((string)($u['role'] ?? 'reseller'))), ['reseller', 'admin'], true);
    $base = $isReseller ? (float)$r['reseller_price'] : (float)($r['user_price'] ?: $r['reseller_price']);
    if ((float)$r['custom_price'] > 0) $base = (float)$r['custom_price'];

    // Admin kono custom price na dile, parent er set kora price ei final
    if ((float)$r['custom_price'] <= 0 && !empty($u['created_by']) && $depth < 5) {
        $ov = km_parent_price($pdo, (int)$u['created_by'], (int)$u['id'], $planId);
        if ($ov !== null) return round($ov, 2);
    }

    $disc = (int)$r['custom_discount'] > 0 ? (int)$r['custom_discount'] : (int)($r['plan_discount'] > 0 ? $r['plan_discount'] : $r['prod_discount']);
    return $disc > 0 ? max(0.01, round($base - ($base * ($disc / 100)), 2)) : round($base, 2);
}


/** Ekta user er sob plan er final price (planId => price), ek query-te. Price Edit page er jonno. */
function km_prices_for_user(PDO $pdo, array $u): array {
    $st = $pdo->prepare("SELECT pl.id AS plan_id, pl.reseller_price, pl.user_price,
                                COALESCE(pl.discount_percent,0) AS plan_discount, COALESCE(p.discount_percent,0) AS prod_discount,
                                COALESCE(upp.custom_price,0) AS custom_price, COALESCE(upp.custom_discount,0) AS custom_discount
                         FROM product_plans pl JOIN products p ON pl.product_id = p.id
                         LEFT JOIN user_plan_pricing upp ON (upp.plan_id = pl.id AND upp.user_id = ?)");
    $st->execute([(int)$u['id']]);
    $isReseller = in_array(strtolower(trim((string)($u['role'] ?? 'reseller'))), ['reseller', 'admin'], true);
    $parentMap = !empty($u['created_by']) ? km_parent_prices_map($pdo, (int)$u['created_by'], (int)$u['id']) : [];
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $pid = (int)$r['plan_id'];
        $base = $isReseller ? (float)$r['reseller_price'] : (float)($r['user_price'] ?: $r['reseller_price']);
        if ((float)$r['custom_price'] > 0) $base = (float)$r['custom_price'];
        if ((float)$r['custom_price'] <= 0 && isset($parentMap[$pid])) { $out[$pid] = round($parentMap[$pid], 2); continue; }
        $disc = (int)$r['custom_discount'] > 0 ? (int)$r['custom_discount'] : (int)($r['plan_discount'] > 0 ? $r['plan_discount'] : $r['prod_discount']);
        $out[$pid] = $disc > 0 ? max(0.01, round($base - ($base * ($disc / 100)), 2)) : round($base, 2);
    }
    return $out;
}

// ====================================================================
// TRANSACTION LEDGER (AUTO / MANUAL / ADMIN_ADD / ADMIN_DEDUCT) + BONUS
// ====================================================================
function km_trx_label(string $type): array {
    switch (strtoupper($type)) {
        case 'AUTO':         return ['Auto Deposit',  'emerald'];
        case 'ADMIN_ADD':    return ['Admin Added',   'cyan'];
        case 'ADMIN_DEDUCT': return ['Admin Deducted', 'rose'];
        default:             return ['Manual Deposit', 'amber'];
    }
}

function km_calc_bonus(PDO $pdo, float $usd): float {
    try {
        $st = $pdo->prepare("SELECT bonus_amount, bonus_type FROM deposit_bonuses WHERE min_amount <= ? ORDER BY min_amount DESC LIMIT 1");
        $st->execute([$usd]);
        $b = $st->fetch(PDO::FETCH_ASSOC);
        if (!$b) return 0.0;
        return $b['bonus_type'] === 'percent' ? round($usd * ((float)$b['bonus_amount'] / 100), 2) : round((float)$b['bonus_amount'], 2);
    } catch (Throwable $e) { return 0.0; }
}

function km_record_trx(PDO $pdo, int $userId, string $type, float $amount, string $method, string $trxId, string $status = 'approved', string $sender = '', string $note = '', float $bonus = 0.0): void {
    $st = $pdo->prepare("INSERT INTO transactions (user_id, method, amount, sender_num, trx_id, status, type, bonus_amount, note) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $st->execute([$userId, $method, $amount, $sender, $trxId, $status, strtoupper($type), $bonus, $note]);
}

// ====================================================================
// 24-HOUR TRUSTED SESSION (Telegram OTP ekbar verify => 24 ghonta login thake)
// ====================================================================
const KM_TRUST_SECONDS = 86400;

function km_issue_trust(PDO $pdo, int $userId, $chatId, string $tgUser = ''): void {
    try {
        $token = bin2hex(random_bytes(32));
        $pdo->prepare("DELETE FROM trusted_sessions WHERE expires_at < NOW()")->execute();
        $pdo->prepare("INSERT INTO trusted_sessions (user_id, token_hash, tg_chat_id, tg_username, ip_address, expires_at) VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL " . KM_TRUST_SECONDS . " SECOND))")
            ->execute([$userId, hash('sha256', $token), $chatId, $tgUser, $_SERVER['REMOTE_ADDR'] ?? '']);
        $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        setcookie('km_trust', $token, ['expires' => time() + KM_TRUST_SECONDS, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
    } catch (Throwable $e) {}
}

function km_try_trusted_login(PDO $pdo): bool {
    $token = (string)($_COOKIE['km_trust'] ?? '');
    if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) return false;
    try {
        $st = $pdo->prepare("SELECT ts.tg_chat_id, ts.tg_username, u.id, u.username, u.status, u.telegram_chat_id
                             FROM trusted_sessions ts JOIN users u ON u.id = ts.user_id
                             WHERE ts.token_hash = ? AND ts.expires_at > NOW() LIMIT 1");
        $st->execute([hash('sha256', $token)]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r || $r['status'] !== 'active') return false;
        if (!empty($r['telegram_chat_id']) && (string)$r['telegram_chat_id'] !== (string)$r['tg_chat_id']) return false;

        session_regenerate_id(true);
        $_SESSION['auth_logged_in'] = true;
        $_SESSION['auth_user_id']   = (int)$r['id'];
        $_SESSION['auth_username']  = $r['username'];
        $_SESSION['tg_verified']    = true;
        $_SESSION['tg_chat_id']     = $r['tg_chat_id'];
        $_SESSION['tg_username']    = $r['tg_username'];
        return true;
    } catch (Throwable $e) { return false; }
}

function km_clear_trust(PDO $pdo): void {
    $token = (string)($_COOKIE['km_trust'] ?? '');
    if ($token !== '') {
        try { $pdo->prepare("DELETE FROM trusted_sessions WHERE token_hash = ?")->execute([hash('sha256', $token)]); } catch (Throwable $e) {}
    }
    setcookie('km_trust', '', ['expires' => time() - 3600, 'path' => '/']);
}

require_once __DIR__ . '/reset_engine.php';
