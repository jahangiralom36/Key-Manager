<?php
// ====================================================================
// API.PHP — RESELLER, MOTHER PANEL & BOT AUTOMATION REST API ENGINE
// 100% GENUINE KEYS ONLY — ABSOLUTELY ZERO FAKE KEYS GUARANTEED
// Supports: balance, products, generate_key, key_history, reset_key
// ====================================================================
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(0);

ob_start();

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: X-API-Token, X-API-KEY, Authorization, Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/config.php';

// JSON body (Content-Type: application/json) ke o $_REQUEST er moto nao — customer er site JSON pathale-o kaj korbe
$__raw = file_get_contents('php://input');
if ($__raw !== false && $__raw !== '' && stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'json') !== false) {
    $__json = json_decode($__raw, true);
    if (is_array($__json)) {
        foreach ($__json as $__k => $__v) {
            if (is_scalar($__v)) $_REQUEST[$__k] = $__v;
        }
    }
}

// Helper function for clean JSON output
function apiOutput(array $data, int $httpCode = 200): void {
    if (ob_get_length()) {
        ob_end_clean();
    }
    http_response_code($httpCode);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// generateFromExternalProvider() + reset engine => core.php / reset_engine.php (config.php load kore)

// ==========================================================
// 1. BULLETPROOF API TOKEN EXTRACTION
// ==========================================================
$apiKey = '';

if (!empty($_SERVER['HTTP_X_API_TOKEN'])) {
    $apiKey = trim($_SERVER['HTTP_X_API_TOKEN']);
} elseif (!empty($_SERVER['HTTP_X_API_KEY'])) {
    $apiKey = trim($_SERVER['HTTP_X_API_KEY']);
} elseif (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
    if (preg_match('/Bearer\s+(.*)$/i', $_SERVER['HTTP_AUTHORIZATION'], $m)) {
        $apiKey = trim($m[1]);
    }
}

if (empty($apiKey) && function_exists('getallheaders')) {
    $headers = getallheaders();
    foreach ($headers as $k => $v) {
        $lower = strtolower($k);
        if ($lower === 'x-api-token' || $lower === 'x-api-key') {
            $apiKey = trim($v);
            break;
        }
    }
}

if (empty($apiKey)) {
    $apiKey = trim((string)($_REQUEST['api_key'] ?? ($_REQUEST['token'] ?? '')));
}

if (empty($apiKey)) {
    apiOutput([
        'status'  => 'error',
        'success' => false,
        'message' => 'API Token is required in headers (X-API-Token) or parameters (?api_key=)',
        'error'   => 'API Token is required'
    ], 401);
}

// ==========================================================
// 2. AUTHENTICATE RESELLER
// ==========================================================
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE api_key = ? LIMIT 1");
    $stmt->execute([$apiKey]);
    $reseller = $stmt->fetch();

    if (!$reseller) {
        apiOutput([
            'status'  => 'error',
            'success' => false,
            'message' => 'Token invalid or revoked.',
            'error'   => 'Token invalid or revoked'
        ], 401);
    }

    if (($reseller['status'] ?? 'active') !== 'active') {
        apiOutput([
            'status'  => 'error',
            'success' => false,
            'message' => 'Your account is suspended or inactive.',
            'error'   => 'Account suspended'
        ], 403);
    }

    if (isset($reseller['api_enabled']) && (int)$reseller['api_enabled'] === 0) {
        apiOutput([
            'status'  => 'error',
            'success' => false,
            'message' => 'API access is disabled for your account.',
            'error'   => 'API access disabled'
        ], 403);
    }
} catch (Throwable $e) {
    apiOutput([
        'status'  => 'error',
        'success' => false,
        'message' => 'Database authentication error: ' . $e->getMessage(),
        'error'   => $e->getMessage()
    ], 500);
}

$action = strtolower(trim((string)($_REQUEST['action'] ?? 'balance')));
if (in_array($action, ['reset', 'resetkey', 'reset_hwid', 'hwid_reset'], true)) $action = 'reset_key';

// ==========================================================
// 3. ACTION: BALANCE
// ==========================================================
if ($action === 'balance' || $action === 'check_balance') {
    apiOutput([
        'status'   => 'success',
        'success'  => true,
        'username' => $reseller['username'],
        'balance'  => number_format((float)$reseller['balance'], 2, '.', ''),
        'currency' => 'USD'
    ]);
}

// ==========================================================
// 4. ACTION: PRODUCTS
// ==========================================================
if ($action === 'products' || $action === 'get_products') {
    $products = [];
    try {
        $dbPlans = $pdo->query("SELECT pl.id as plan_id, pl.api_plan_id, pl.product_id,
                                       pl.reset_api_id as plan_reset_api_id, p.reset_api_id as prod_reset_api_id,
                                       p.name as product_name, pl.plan_title as validity, 
                                       pl.reseller_price, pl.user_price,
                                       COALESCE(pl.discount_percent, p.discount_percent, 0) as discount_percent
                                FROM product_plans pl 
                                JOIN products p ON pl.product_id = p.id 
                                WHERE p.is_active = 1 
                                ORDER BY p.name ASC, pl.duration_days ASC, pl.id ASC")->fetchAll();

        foreach ($dbPlans as $p) {
            $basePrice = (float)($p['reseller_price'] > 0 ? $p['reseller_price'] : $p['user_price']);
            $discount = (int)($p['discount_percent'] ?? 0);
            
            // Accurate discount deduction from base price
            $finalPrice = $basePrice;
            if ($discount > 0) {
                $finalPrice = max(0.01, round($basePrice - ($basePrice * ($discount / 100)), 2));
            }

            $products[] = [
                'id'            => (int)$p['plan_id'],
                'plan_id'       => (int)$p['plan_id'],
                'product_id'    => (int)$p['product_id'],
                'api_plan_id'   => (int)($p['api_plan_id'] ?: $p['plan_id']),
                'reset_api_id'  => lm_resolve_reset_api_id($p['plan_reset_api_id'], $p['prod_reset_api_id'], (string)$p['product_name'], 0, (string)$p['validity']),
                'product_name'  => $p['product_name'],
                'plan_title'    => $p['validity'],
                'validity'      => $p['validity'],
                'name'          => $p['product_name'] . ' (' . $p['validity'] . ')',
                'price'         => $finalPrice,
                'regular_price' => $basePrice,
                'discount'      => $discount
            ];
        }
    } catch (Throwable $e) {}

    apiOutput([
        'status'   => 'success',
        'success'  => true,
        'count'    => count($products),
        'products' => $products
    ]);
}

// ==========================================================
// 5. ACTION: GENERATE KEY (PURE GENUINE KEYS ONLY)
// ==========================================================
if ($action === 'generate_key') {
    $planId   = (int)($_REQUEST['plan_id'] ?? ($_REQUEST['product_id'] ?? 0));
    $quantity = max(1, min(50, (int)($_REQUEST['quantity'] ?? 1)));

    if ($planId <= 0) {
        apiOutput([
            'status'  => 'error',
            'success' => false,
            'message' => 'Please provide a valid plan_id or product_id.',
            'error'   => 'Invalid plan parameter'
        ], 400);
    }

    // Match plan from database
    $stmt = $pdo->prepare("SELECT p.id as real_prod_id, p.name as product_name, pl.id as real_plan_id,
                                  pl.plan_title, pl.reseller_price, pl.user_price, pl.api_plan_id,
                                  pl.reset_api_id as plan_reset_api_id, p.reset_api_id as prod_reset_api_id,
                                  COALESCE(pl.discount_percent, p.discount_percent, 0) as discount_percent
                           FROM product_plans pl 
                           JOIN products p ON pl.product_id = p.id 
                           WHERE pl.id = ? OR pl.api_plan_id = ? LIMIT 1");
    $stmt->execute([$planId, $planId]);
    $plan = $stmt->fetch();

    if (!$plan) {
        apiOutput([
            'status'  => 'error',
            'success' => false,
            'message' => 'Product plan not found in database.',
            'error'   => 'Plan not found'
        ], 404);
    }

    $baseUnit = (float)($plan['reseller_price'] > 0 ? $plan['reseller_price'] : $plan['user_price']);
    $planDiscount = (int)($plan['discount_percent'] ?? 0);
    
    // Accurate discount deduction from database base price
    $unitPrice = $baseUnit;
    if ($planDiscount > 0) {
        $unitPrice = max(0.01, round($baseUnit - ($baseUnit * ($planDiscount / 100)), 2));
    }

    $totalCost = round($unitPrice * $quantity, 2);
    $productFullName = $plan['product_name'] . ' (' . $plan['plan_title'] . ')';

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT balance FROM users WHERE id = ? FOR UPDATE");
        $stmt->execute([$reseller['id']]);
        $currentBal = (float)$stmt->fetchColumn();

        if ($currentBal < $totalCost) {
            $pdo->rollBack();
            apiOutput([
                'status'  => 'error',
                'success' => false,
                'message' => 'Insufficient balance! Needed: $' . number_format($totalCost, 2) . ' | Available: $' . number_format($currentBal, 2),
                'error'   => 'Insufficient balance'
            ], 400);
        }

        $keys = [];

        // STEP 1: Check manual inventory stock
        $stmtStock = $pdo->prepare("SELECT id, license_key FROM stock_keys WHERE plan_id = ? AND status = 'available' ORDER BY id ASC LIMIT ? FOR UPDATE");
        $stmtStock->bindValue(1, $plan['real_plan_id'], PDO::PARAM_INT);
        $stmtStock->bindValue(2, $quantity, PDO::PARAM_INT);
        $stmtStock->execute();
        $stockRows = $stmtStock->fetchAll();

        if (count($stockRows) === $quantity) {
            $stockIds = array_column($stockRows, 'id');
            $inClause = implode(',', array_map('intval', $stockIds));
            $pdo->exec("UPDATE stock_keys SET status = 'used', used_by = {$reseller['id']}, used_at = NOW() WHERE id IN ($inClause)");
            foreach ($stockRows as $sRow) {
                $rawK = trim((string)$sRow['license_key']);
                if (!empty($rawK)) $keys[] = $rawK;
            }
        } 
        
        // STEP 2: Fetch from external provider if manual stock is insufficient
        if (count($keys) !== $quantity) {
            $apiId = (int)($plan['api_plan_id'] ?? 0);
            if ($apiId > 0) {
                $apiRes = generateFromExternalProvider($apiId, $quantity);
                if (!empty($apiRes['status']) && $apiRes['status'] === 'success' && !empty($apiRes['keys']) && count($apiRes['keys']) === $quantity) {
                    $keys = array_values(array_filter(array_map('trim', (array)$apiRes['keys'])));
                } else {
                    $pdo->rollBack();
                    $errText = !empty($apiRes['message']) ? $apiRes['message'] : ("Stock Out: " . $productFullName);
                    apiOutput([
                        'status'  => 'error',
                        'success' => false,
                        'message' => $errText,
                        'error'   => 'Stock Out'
                    ], 400);
                }
            } else {
                $pdo->rollBack();
                apiOutput([
                    'status'  => 'error',
                    'success' => false,
                    'message' => "Stock Out: No manual stock or API provider configured for " . $productFullName,
                    'error'   => 'Stock Out'
                ], 400);
            }
        }

        // STRICT SAFETY CHECK: Absolutely ZERO fake keys allowed
        if (empty($keys) || count($keys) !== $quantity) {
            $pdo->rollBack();
            apiOutput([
                'status'  => 'error',
                'success' => false,
                'message' => "Stock Out: Real keys could not be provisioned for " . $productFullName,
                'error'   => 'Stock Out'
            ], 400);
        }

        foreach ($keys as $chkKey) {
            if (empty($chkKey) || strlen($chkKey) < 5 || stripos($chkKey, 'fake') !== false) {
                $pdo->rollBack();
                apiOutput([
                    'status'  => 'error',
                    'success' => false,
                    'message' => "Stock Out: Corrupted or invalid key received from provider.",
                    'error'   => 'Corrupt key received'
                ], 400);
            }
        }

        // Deduct balance ONLY when keys are 100% verified genuine
        $pdo->prepare("UPDATE users SET balance = balance - ? WHERE id = ?")->execute([$totalCost, $reseller['id']]);

        // Insert into licenses ledger
        $stmtIns = $pdo->prepare("INSERT INTO licenses (user_id, product_id, plan_id, reset_api_id, license_key, validity, price, source) VALUES (?, ?, ?, ?, ?, ?, ?, 'API')");
        foreach ($keys as $k) {
            $stmtIns->execute([$reseller['id'], $plan['real_prod_id'], $plan['real_plan_id'], lm_resolve_reset_api_id($plan['plan_reset_api_id'], $plan['prod_reset_api_id'], (string)$plan['product_name'], 0, (string)$plan['plan_title']), $k, $productFullName, $unitPrice]);
        }

        $pdo->commit();

        if (function_exists('logActivity')) {
            logActivity($pdo, $reseller['id'], $reseller['username'], 'API_KEY_GENERATE', "Generated $quantity real key(s) via API for " . $plan['product_name']);
        }

        apiOutput([
            'status'            => 'success',
            'success'           => true,
            'keys'              => $keys,
            'product'           => $plan['product_name'],
            'product_name'      => $productFullName,
            'validity'          => $plan['plan_title'],
            'reset_api_id'      => lm_resolve_reset_api_id($plan['plan_reset_api_id'], $plan['prod_reset_api_id'], (string)$plan['product_name'], 0, (string)$plan['plan_title']),
            'quantity'          => count($keys),
            'amount_charged'    => $totalCost,
            'remaining_balance' => number_format($currentBal - $totalCost, 2, '.', '')
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        apiOutput([
            'status'  => 'error',
            'success' => false,
            'message' => 'Internal server error: ' . $e->getMessage(),
            'error'   => $e->getMessage()
        ], 500);
    }
}

// ==========================================================
// 6. ACTION: KEY HISTORY
// ==========================================================
if ($action === 'key_history' || $action === 'history') {
    $limit = max(1, min(100, (int)($_REQUEST['limit'] ?? 20)));

    $stmt = $pdo->prepare("SELECT license_key as `key`, validity, created_at FROM licenses WHERE user_id = ? ORDER BY id DESC LIMIT ?");
    $stmt->bindValue(1, $reseller['id'], PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $historyKeys = $stmt->fetchAll(PDO::FETCH_ASSOC);

    apiOutput([
        'status'  => 'success',
        'success' => true,
        'count'   => count($historyKeys),
        'keys'    => $historyKeys
    ]);
}

// ==========================================================
// 7. ACTION: RESET KEY (shared engine — LicenseManager 2,3,4,9,11,12 + Angry Mod 99)
//    api_id dewa optional: na dile key er product theke auto-detect hobe.
// ==========================================================
if ($action === 'reset_key') {
    $key = trim((string)($_REQUEST['key'] ?? ($_REQUEST['license_key'] ?? ($_REQUEST['license'] ?? ($_REQUEST['serial'] ?? '')))));
    $customApiId = (int)($_REQUEST['api_id'] ?? ($_REQUEST['reset_api_id'] ?? 0));

    if ($key === '') {
        apiOutput([
            'status'  => 'error',
            'success' => false,
            'message' => 'License key is required.',
            'error'   => 'Missing license key'
        ], 400);
    }
    if (!lm_valid_key_format($key)) {
        apiOutput([
            'status'  => 'error',
            'success' => false,
            'message' => 'Invalid license key format.',
            'error'   => 'Invalid key'
        ], 400);
    }

    // 1) Key ta ei account (ba tar sub-account) er kina
    $matchedLicense = lm_find_license($pdo, $key, (int)$reseller['id']);
    $detectedApiId = $matchedLicense ? (int)$matchedLicense['resolved_reset_api_id'] : 0;

    if (!$matchedLicense) {
        // 2) Onno account er key hole block (security). Amader DB-te nai emon key (provider theke kena)
        //    hole panel er moto reset hobe — tobe api_id dite hobe.
        $other = lm_find_license($pdo, $key, null);
        if ($other) {
            apiOutput([
                'status'  => 'error',
                'success' => false,
                'message' => 'Security Error: This license key was not purchased from your account.',
                'error'   => 'Key not found in account'
            ], 403);
        }
        if ($customApiId <= 0) {
            apiOutput([
                'status'  => 'error',
                'success' => false,
                'message' => 'Key not found in our records. Please pass api_id (' . implode(', ', array_keys(lm_reset_apis())) . ') to reset it.',
                'error'   => 'api_id required'
            ], 400);
        }
    }

    // Candidate list: customer er api_id age, tarpor amader detect kora id (ekta fail korle arektа try)
    $candidates = array_values(array_unique(array_filter([$customApiId, $detectedApiId], fn($v) => (int)$v > 0)));
    if (empty($candidates)) {
        apiOutput([
            'status'  => 'error',
            'success' => false,
            'message' => 'Reset API could not be detected for this key. Please pass api_id (' . implode(', ', array_keys(lm_reset_apis())) . ').',
            'error'   => 'api_id required'
        ], 400);
    }

    $r = lm_reset_with_fallback($key, $candidates);
    $usedApiId = (int)$r['api_id'];

    if ($r['ok']) {
        logActivity($pdo, (int)$reseller['id'], (string)$reseller['username'], 'KEY_RESET',
            "Key reset via API: {$key} (API #{$usedApiId} {$r['api_name']})");
    }

    apiOutput([
        'status'       => $r['ok'] ? 'success' : 'error',
        'success'      => $r['ok'],
        'message'      => $r['message'],
        'api_id'       => $usedApiId,
        'api_name'     => $r['api_name'],
        'product_name' => $matchedLicense['validity'] ?? 'License',
        'key'          => $key
    ], $r['ok'] ? 200 : 400);
}

// Default Fallback
apiOutput([
    'status'  => 'error',
    'success' => false,
    'message' => "Invalid action '$action' specified. Supported actions: balance, products, generate_key, key_history, reset_key",
    'error'   => 'Invalid action'
], 400);