<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/config.php';

// Force UTF-8 / UTF8MB4 for full native Bengali (বাংলা) font support
try {
    $pdo->exec("SET NAMES 'utf8mb4'");
    $pdo->exec("SET CHARACTER SET utf8mb4");
} catch (Exception $e) {}

// Permitted Reset APIs (shared engine: reset_engine.php — token config.php te)
$permittedResetApis = lm_reset_apis();

// 1. Session Logout Handler
if (isset($_GET['logout']) || (isset($_GET['action']) && $_GET['action'] === 'logout')) {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
    }
    session_destroy();
    header('Location: index.php');
    exit;
}

// Zero-Bypass Security Guard
if (empty($_SESSION['auth_logged_in']) || empty($_SESSION['auth_user_id']) || empty($_SESSION['tg_verified']) || $_SESSION['tg_verified'] !== true) {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
    }
    session_destroy();
    header('Location: index.php');
    exit;
}

$userId = (int)$_SESSION['auth_user_id'];

// Fetch Current User
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND status = 'active' LIMIT 1");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    session_destroy();
    header('Location: index.php');
    exit;
}

if (empty($user['api_key']) || strpos($user['api_key'], 'keymanager_live_') !== 0) {
    $newBrandKey = 'keymanager_live_' . bin2hex(random_bytes(16));
    $pdo->prepare("UPDATE users SET api_key = ? WHERE id = ?")->execute([$newBrandKey, $user['id']]);
    $user['api_key'] = $newBrandKey;
}

$userRole = strtolower(trim($user['role'] ?? 'reseller'));
$isReseller = ($userRole === 'reseller' || $userRole === 'admin');
$canAddFund = (int)($user['can_add_fund'] ?? 1);

// Load System Settings
$settings = [
    'usdt_rate'              => '125',
    'binance_market_rate'    => '125',
    'bkash_number'           => '01700000000',
    'nagad_number'           => '01800000000',
    'rocket_number'          => '01900000000',
    'binance_id'             => '12345678',
    'snzpay_license_key'     => '',
    'snzpay_api_url'         => 'https://snzpay.digital/api/verify_payment.php',
    'auto_payment_on'        => '0',
    'min_deposit_limit'      => '5.00',
    'max_deposit_limit'      => '500.00',
    'telegram_tutorial_link' => 'https://t.me/Kaymanager_bot',
    'broadcast_notice'       => 'স্বাগতম! আমাদের সকল সার্ভিস ও এপিআই সক্রিয় রয়েছে।'
];

try {
    $dbSettings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    if (!empty($dbSettings)) $settings = array_merge($settings, $dbSettings);
} catch (Exception $e) {}

$adminRate = max(50.0, (float)($settings['usdt_rate'] ?? 125.0));
$binanceMarketRate = max(50.0, (float)($settings['binance_market_rate'] ?? 125.0));
$userCustomRate = (float)($user['custom_bdt_rate'] ?? 0);
$effectiveBdtRate = ($userCustomRate >= 50.0) ? $userCustomRate : $adminRate;

$userMinDepositUSD = ($user['min_deposit'] !== null && $user['min_deposit'] !== '') ? (float)$user['min_deposit'] : (float)($settings['min_deposit_limit'] ?? 5.00);
$userMaxDepositUSD = ($user['max_deposit'] !== null && $user['max_deposit'] !== '') ? (float)$user['max_deposit'] : (float)($settings['max_deposit_limit'] ?? 500.00);

$effectiveTutorialLink = $settings['telegram_tutorial_link'];
if (!empty($user['created_by'])) {
    $parentLink = $pdo->query("SELECT custom_tutorial_link FROM users WHERE id = " . (int)$user['created_by'])->fetchColumn();
    if (!empty($parentLink)) $effectiveTutorialLink = $parentLink;
} elseif ($isReseller && !empty($user['custom_tutorial_link'])) {
    $effectiveTutorialLink = $user['custom_tutorial_link'];
}

// Product Catalog & Accurate Discount Calculation Engine (Includes User Custom Pricing & Discounts)
$catalogStructure = [];
$apiPlanList = []; 
$productsResetMapping = [];

try {
    $dbProducts = $pdo->query("SELECT p.id as product_id, p.name as product_name, 
                                      COALESCE(p.discount_percent, 0) as prod_discount,
                                      COALESCE(p.reset_api_id, 0) as prod_reset_api_id,
                                      pl.id as plan_id, pl.plan_title as validity, 
                                      pl.reseller_price, pl.user_price, pl.api_plan_id,
                                      COALESCE(pl.discount_percent, 0) as plan_discount,
                                      COALESCE(pl.reset_api_id, 0) as plan_reset_api_id,
                                      COALESCE(upp.custom_price, 0.00) as custom_user_price,
                                      COALESCE(upp.custom_discount, 0) as custom_user_discount
                               FROM products p
                               LEFT JOIN product_plans pl ON pl.product_id = p.id
                               LEFT JOIN user_plan_pricing upp ON (upp.plan_id = pl.id AND upp.user_id = {$userId})
                               WHERE p.is_active = 1 
                               ORDER BY p.sort_order ASC, p.id ASC, pl.duration_days ASC, pl.id ASC")->fetchAll();

    foreach ($dbProducts as $dp) {
        $pId = (int)$dp['product_id'];
        $pName = trim($dp['product_name']);

        if (!empty($dp['plan_id'])) {
            $planId = (int)$dp['plan_id'];
            $uPrice = (float)$dp['user_price'];
            $rPrice = (float)$dp['reseller_price'];
            
            // Base price calculation (check if admin assigned custom wholesale price)
            $basePrice = $isReseller ? $rPrice : ($uPrice ?: $rPrice);
            if ((float)$dp['custom_user_price'] > 0) {
                $basePrice = (float)$dp['custom_user_price'];
            }

            // Accurate Discount Calculation
            $activeDiscount = (int)$dp['custom_user_discount'] > 0 
                ? (int)$dp['custom_user_discount'] 
                : (int)($dp['plan_discount'] > 0 ? $dp['plan_discount'] : $dp['prod_discount']);

            $regularPrice = $basePrice;
            $dealPrice = $basePrice;

            if ($activeDiscount > 0) {
                $discountDeduction = round($regularPrice * ($activeDiscount / 100), 2);
                $dealPrice = max(0.01, round($regularPrice - $discountDeduction, 2));
            }

            $resolvedResetApiId = lm_resolve_reset_api_id($dp['plan_reset_api_id'], $dp['prod_reset_api_id'], $pName, 0, (string)$dp['validity']);
            if ($resolvedResetApiId > 0 && empty($productsResetMapping[$pName])) {
                $productsResetMapping[$pName] = $resolvedResetApiId;
            }

            $planItem = [
                'product_id'       => $pId,
                'plan_id'          => $planId,
                'api_plan_id'      => (int)($dp['api_plan_id'] ?? 0),
                'reset_api_id'     => $resolvedResetApiId,
                'product_name'     => $pName,
                'validity'         => $dp['validity'],
                'price'            => $dealPrice,
                'regular_price'    => $regularPrice,
                'discount_percent' => $activeDiscount
            ];

            $catalogStructure[$pName][] = $planItem;
            $apiPlanList[] = $planItem;
        }
    }
} catch (Exception $e) {
    $catalogStructure = [];
    $apiPlanList = [];
}

$userAllowedProducts = !empty($user['allowed_products']) ? explode(',', $user['allowed_products']) : array_keys($catalogStructure);

// 2. AJAX: Fetch Sub-Account History
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_sub_history' && $isReseller) {
    header('Content-Type: application/json');
    $targetId = (int)($_POST['target_user_id'] ?? 0);

    $stmtVerify = $pdo->prepare("SELECT id FROM users WHERE id = ? AND created_by = ? LIMIT 1");
    $stmtVerify->execute([$targetId, $user['id']]);
    if (!$stmtVerify->fetch()) {
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized access!']);
        exit;
    }

    $subDeposits = $pdo->prepare("SELECT * FROM transactions WHERE user_id = ? ORDER BY id DESC LIMIT 50");
    $subDeposits->execute([$targetId]);
    $depList = $subDeposits->fetchAll();

    $subKeys = $pdo->prepare("SELECT * FROM licenses WHERE user_id = ? ORDER BY id DESC LIMIT 50");
    $subKeys->execute([$targetId]);
    $keyList = $subKeys->fetchAll();

    echo json_encode([
        'status'   => 'success',
        'deposits' => $depList,
        'keys'     => $keyList
    ]);
    exit;
}

// 3. AJAX Key Generation (Pure Genuine Keys Only - With Real Discount Application)
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'live_generate') {
    header('Content-Type: application/json');
    $planId = (int)($_POST['plan_id'] ?? 0);
    $quantity = (int)($_POST['quantity'] ?? 1);

    if ($quantity < 1 || $planId <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Please select a valid product plan and quantity.']);
        exit;
    }

    $quantity = min(50, $quantity);

    $stmt = $pdo->prepare("SELECT p.id as real_product_id, p.name as product_name, 
                                  COALESCE(p.discount_percent, 0) as prod_discount,
                                  COALESCE(p.reset_api_id, 0) as prod_reset_api_id,
                                  pl.plan_title, pl.reseller_price, pl.user_price, pl.api_plan_id,
                                  COALESCE(pl.discount_percent, 0) as plan_discount,
                                  COALESCE(pl.reset_api_id, 0) as plan_reset_api_id,
                                  COALESCE(upp.custom_price, 0.00) as custom_user_price,
                                  COALESCE(upp.custom_discount, 0) as custom_user_discount
                           FROM product_plans pl 
                           JOIN products p ON pl.product_id = p.id 
                           LEFT JOIN user_plan_pricing upp ON (upp.plan_id = pl.id AND upp.user_id = ?)
                           WHERE pl.id = ? LIMIT 1");
    $stmt->execute([$user['id'], $planId]);
    $plan = $stmt->fetch();

    if (!$plan) {
        echo json_encode(['status' => 'error', 'message' => 'Selected product plan is unavailable.']);
        exit;
    }

    $baseUnit = $isReseller ? (float)$plan['reseller_price'] : (float)($plan['user_price'] ?: $plan['reseller_price']);
    if ((float)$plan['custom_user_price'] > 0) {
        $baseUnit = (float)$plan['custom_user_price'];
    }

    $planDiscount = (int)$plan['custom_user_discount'] > 0 
        ? (int)$plan['custom_user_discount'] 
        : (int)($plan['plan_discount'] > 0 ? $plan['plan_discount'] : $plan['prod_discount']);
    
    // Accurate Discount calculation from Base Unit Price
    $unitPrice = $baseUnit;
    if ($planDiscount > 0) {
        $unitPrice = max(0.01, round($baseUnit - ($baseUnit * ($planDiscount / 100)), 2));
    }

    $totalCost = round($unitPrice * $quantity, 2);
    $productFullName = $plan['product_name'] . ' (' . $plan['plan_title'] . ')';
    $resolvedResetApiId = lm_resolve_reset_api_id($plan['plan_reset_api_id'], $plan['prod_reset_api_id'], (string)$plan['product_name'], 0, (string)$plan['plan_title']);

    $pdo->exec("SET FOREIGN_KEY_CHECKS=0;");
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare("SELECT balance FROM users WHERE id = ? FOR UPDATE");
        $stmt->execute([$user['id']]);
        $currentBalance = (float)$stmt->fetchColumn();

        if ($currentBalance < $totalCost) {
            $pdo->rollBack();
            echo json_encode([
                'status'  => 'error',
                'message' => 'Insufficient funds! Cost: $' . number_format($totalCost, 2) . ' | Wallet: $' . number_format($currentBalance, 2)
            ]);
            exit;
        }

        $generatedKeys = [];

        // STEP 1: Check Manual Inventory Stock
        $stmtStock = $pdo->prepare("SELECT id, license_key FROM stock_keys WHERE plan_id = ? AND status = 'available' ORDER BY id ASC LIMIT ? FOR UPDATE");
        $stmtStock->bindValue(1, $planId, PDO::PARAM_INT);
        $stmtStock->bindValue(2, $quantity, PDO::PARAM_INT);
        $stmtStock->execute();
        $stockRows = $stmtStock->fetchAll();

        if (count($stockRows) === $quantity) {
            $stockIds = array_column($stockRows, 'id');
            $inClause = implode(',', array_map('intval', $stockIds));
            $pdo->exec("UPDATE stock_keys SET status = 'used', used_by = {$user['id']}, used_at = NOW() WHERE id IN ($inClause)");
            
            foreach ($stockRows as $sRow) {
                $rawKey = trim((string)$sRow['license_key']);
                if (!empty($rawKey)) {
                    $generatedKeys[] = $rawKey;
                }
            }
        } 
        
        // STEP 2: Stock was insufficient -> Fetch from Provider API (NO FAKE KEYS)
        if (count($generatedKeys) !== $quantity) {
            $apiPlanId = (int)($plan['api_plan_id'] ?? 0);

            if ($apiPlanId > 0) {
                $apiResult = generateFromExternalProvider($apiPlanId, $quantity);

                if ($apiResult['status'] === 'success' && !empty($apiResult['keys']) && count($apiResult['keys']) === $quantity) {
                    $generatedKeys = array_values(array_filter(array_map('trim', (array)$apiResult['keys'])));
                } else {
                    $pdo->rollBack();
                    $errDetail = !empty($apiResult['message']) ? $apiResult['message'] : ("Stock Out: " . $productFullName);
                    echo json_encode([
                        'status'  => 'error',
                        'message' => $errDetail
                    ]);
                    exit;
                }
            } else {
                $pdo->rollBack();
                echo json_encode([
                    'status'  => 'error',
                    'message' => "Stock Out: No manual stock or API provider configured for " . $productFullName
                ]);
                exit;
            }
        }

        // ABSOLUTE INTEGRITY CHECK
        if (empty($generatedKeys) || count($generatedKeys) !== $quantity) {
            $pdo->rollBack();
            echo json_encode([
                'status'  => 'error',
                'message' => "Stock Out: Genuine license key could not be provisioned."
            ]);
            exit;
        }

        foreach ($generatedKeys as $chk) {
            if (empty($chk) || strlen($chk) < 5 || stripos($chk, 'fake') !== false) {
                $pdo->rollBack();
                echo json_encode([
                    'status'  => 'error',
                    'message' => "Stock Out: Invalid key data delivered from provider API."
                ]);
                exit;
            }
        }

        // Deduct Balance ONLY after 100% verified real keys exist
        $pdo->prepare("UPDATE users SET balance = balance - ? WHERE id = ?")->execute([$totalCost, $user['id']]);

        if (!empty($user['created_by'])) {
            $wholesalePrice = (float)$plan['reseller_price'] * $quantity;
            $commissionEarned = round($totalCost - $wholesalePrice, 2);
            if ($commissionEarned > 0) {
                $pdo->prepare("UPDATE users SET profit_balance = profit_balance + ? WHERE id = ?")
                    ->execute([$commissionEarned, $user['created_by']]);
            }
        }

        $stmtInsert = $pdo->prepare("INSERT INTO licenses (user_id, product_id, plan_id, reset_api_id, license_key, validity, source) VALUES (?, ?, ?, ?, ?, ?, 'PANEL')");
        foreach ($generatedKeys as $key) {
            $stmtInsert->execute([$user['id'], (int)$plan['real_product_id'], $planId, $resolvedResetApiId, (string)$key, $productFullName]);
        }

        $pdo->commit();

        echo json_encode([
            'status'            => 'success',
            'message'           => count($generatedKeys) . ' genuine key(s) issued successfully!',
            'keys'              => $generatedKeys,
            'product_name'      => $productFullName,
            'tutorial_link'     => $effectiveTutorialLink,
            'remaining_balance' => number_format($currentBalance - $totalCost, 2)
        ]);
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'Transaction error: ' . $e->getMessage()]);
        exit;
    }
}

// 4. AJAX Reset Key (shared engine: 6 LicenseManager APIs + Angry Mod Pinxt)
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'reset_key') {
    header('Content-Type: application/json');
    $licenseKey    = trim($_POST['license_key'] ?? '');
    $selectedApiId = (int)($_POST['reset_api_id'] ?? 0);
    $customToken   = trim($_POST['token'] ?? '');

    if ($licenseKey === '') {
        echo json_encode(['status' => 'error', 'message' => 'Please enter the license key to reset!']);
        exit;
    }
    if (!isset($permittedResetApis[$selectedApiId])) {
        echo json_encode(['status' => 'error', 'message' => 'Please select a valid Reset API from the list!']);
        exit;
    }

    $r = lm_do_reset($licenseKey, $selectedApiId, $customToken);

    if ($r['ok']) {
        logActivity($pdo, (int)$user['id'], (string)$user['username'], 'KEY_RESET',
            "Key reset via PANEL: {$licenseKey} (API #{$selectedApiId} {$r['api_name']})");
    }

    echo json_encode([
        'status'       => $r['ok'] ? 'success' : 'error',
        'product_name' => $r['api_name'],
        'api_id'       => $selectedApiId,
        'message'      => $r['message']
    ]);
    exit;
}

// 5. PRG Controller (Sub-Resellers, Profit Transfers, SNZ Pay & Manual Deposits)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // Register Sub-Reseller
    if ($_POST['action'] === 'create_sub_reseller' && $isReseller) {
        $_SESSION['active_page'] = 'subresellers';
        $subUser = trim($_POST['sub_username'] ?? '');
        $subPass = $_POST['sub_password'] ?? '';
        $subRole = ($_POST['sub_role'] === 'reseller') ? 'reseller' : 'user';
        $subBal = max(0, (float)($_POST['sub_balance'] ?? 0));
        
        $canDeposit = ($canAddFund === 1 && isset($_POST['can_add_fund'])) ? 1 : 0;
        $apiEnabled = (((int)($user['api_enabled'] ?? 1) === 1) && isset($_POST['api_enabled'])) ? 1 : 0;
        $customRate = (float)($_POST['custom_bdt_rate'] ?? 0);

        if ($customRate < $effectiveBdtRate) {
            $_SESSION['flash_toast'] = [
                'type' => 'error', 
                'msg' => "Exchange rate cannot be lower than your active rate ({$effectiveBdtRate} BDT)!"
            ];
            header('Location: dashboard.php');
            exit;
        }

        if (!empty($subUser) && !empty($subPass)) {
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("SELECT balance FROM users WHERE id = ? FOR UPDATE");
                $stmt->execute([$user['id']]);
                if ($subBal > 0 && (float)$stmt->fetchColumn() < $subBal) {
                    $pdo->rollBack();
                    $_SESSION['flash_toast'] = ['type' => 'error', 'msg' => 'Insufficient wallet balance for initial grant!'];
                } else {
                    $apiKey = 'keymanager_live_' . bin2hex(random_bytes(16));
                    $hashedPass = password_hash($subPass, PASSWORD_DEFAULT);

                    $stmtInsert = $pdo->prepare("INSERT INTO users (username, password, balance, parent_granted_balance, api_key, role, created_by, can_add_fund, api_enabled, custom_bdt_rate) 
                                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmtInsert->execute([$subUser, $hashedPass, $subBal, $subBal, $apiKey, $subRole, $user['id'], $canDeposit, $apiEnabled, $customRate]);

                    if ($subBal > 0) {
                        $pdo->prepare("UPDATE users SET balance = balance - ? WHERE id = ?")->execute([$subBal, $user['id']]);
                    }
                    $pdo->commit();
                    $_SESSION['flash_toast'] = ['type' => 'success', 'msg' => "Sub account '$subUser' registered successfully!"];
                }
            } catch (PDOException $e) {
                $pdo->rollBack();
                $_SESSION['flash_toast'] = ['type' => 'error', 'msg' => 'Username already registered!'];
            }
        }
        header('Location: dashboard.php');
        exit;
    }

    // Manage Sub-Reseller Profile
    if ($_POST['action'] === 'manage_sub_reseller' && $isReseller) {
        $_SESSION['active_page'] = 'subresellers';
        $targetId = (int)$_POST['target_user_id'];
        
        $canDeposit = ($canAddFund === 1 && isset($_POST['can_add_fund'])) ? 1 : 0;
        $apiEnabled = (((int)($user['api_enabled'] ?? 1) === 1) && isset($_POST['api_enabled'])) ? 1 : 0;
        $customRate = (float)$_POST['custom_bdt_rate'];
        $status = ($_POST['status'] === 'banned') ? 'banned' : 'active';
        
        $subAllowedReq = isset($_POST['allowed_products']) ? (array)$_POST['allowed_products'] : [];
        $subAllowedFiltered = array_intersect($subAllowedReq, $userAllowedProducts);
        $allowedProds = implode(',', $subAllowedFiltered);

        $stmtSubCheck = $pdo->prepare("SELECT role FROM users WHERE id = ? AND created_by = ?");
        $stmtSubCheck->execute([$targetId, $user['id']]);
        $currRole = $stmtSubCheck->fetchColumn();

        $role = ($currRole === 'reseller') ? 'reseller' : (($_POST['role'] === 'reseller') ? 'reseller' : 'user');

        if ($customRate < $effectiveBdtRate) {
            $_SESSION['flash_toast'] = [
                'type' => 'error', 
                'msg' => "Exchange rate cannot be lower than your active rate ({$effectiveBdtRate} BDT)!"
            ];
            header('Location: dashboard.php');
            exit;
        }

        if (!empty($_POST['new_password'])) {
            $newHash = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE users SET password = ? WHERE id = ? AND created_by = ?")->execute([$newHash, $targetId, $user['id']]);
        }

        $stmtUpdate = $pdo->prepare("UPDATE users SET role = ?, api_enabled = ?, can_add_fund = ?, custom_bdt_rate = ?, status = ?, allowed_products = ? 
                                     WHERE id = ? AND created_by = ?");
        $stmtUpdate->execute([$role, $apiEnabled, $canDeposit, $customRate, $status, $allowedProds, $targetId, $user['id']]);
        
        $_SESSION['flash_toast'] = ['type' => 'success', 'msg' => 'Sub account settings updated!'];
        header('Location: dashboard.php');
        exit;
    }

    // Direct Add/Transfer Balance to Sub-Reseller
    if ($_POST['action'] === 'transfer_balance' && $isReseller) {
        $_SESSION['active_page'] = 'subresellers';
        $targetId = (int)$_POST['target_user_id'];
        $amount = (float)$_POST['transfer_amount'];

        if ($amount > 0) {
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("SELECT balance FROM users WHERE id = ? FOR UPDATE");
                $stmt->execute([$user['id']]);
                if ((float)$stmt->fetchColumn() >= $amount) {
                    $pdo->prepare("UPDATE users SET balance = balance - ? WHERE id = ?")->execute([$amount, $user['id']]);
                    $pdo->prepare("UPDATE users SET balance = balance + ?, parent_granted_balance = parent_granted_balance + ? WHERE id = ? AND created_by = ?")
                        ->execute([$amount, $amount, $targetId, $user['id']]);
                    $pdo->commit();
                    $_SESSION['flash_toast'] = ['type' => 'success', 'msg' => "Added $$amount USD to sub account!"];
                } else {
                    $pdo->rollBack();
                    $_SESSION['flash_toast'] = ['type' => 'error', 'msg' => 'Insufficient wallet balance in your main wallet!'];
                }
            } catch (Exception $e) { $pdo->rollBack(); }
        }
        header('Location: dashboard.php');
        exit;
    }

    // Deduct / Recall Balance from Sub-Reseller
    if ($_POST['action'] === 'deduct_granted_balance' && $isReseller) {
        $_SESSION['active_page'] = 'subresellers';
        $targetId = (int)$_POST['target_user_id'];
        $amount = (float)$_POST['deduct_amount'];

        if ($amount > 0) {
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("SELECT balance, parent_granted_balance FROM users WHERE id = ? AND created_by = ? FOR UPDATE");
                $stmt->execute([$targetId, $user['id']]);
                $subData = $stmt->fetch();

                if ($subData) {
                    $granted = (float)$subData['parent_granted_balance'];
                    $currentBal = (float)$subData['balance'];
                    $maxAllowDeduct = min($granted, $currentBal);

                    if ($amount > $maxAllowDeduct) {
                        $pdo->rollBack();
                        $_SESSION['flash_toast'] = [
                            'type' => 'error', 
                            'msg' => "You can only recall up to $" . number_format($maxAllowDeduct, 2) . " USD (funds personally granted)."
                        ];
                    } else {
                        $pdo->prepare("UPDATE users SET balance = balance - ?, parent_granted_balance = parent_granted_balance - ? WHERE id = ? AND created_by = ?")
                            ->execute([$amount, $amount, $targetId, $user['id']]);
                        $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?")
                            ->execute([$amount, $user['id']]);
                        $pdo->commit();
                        $_SESSION['flash_toast'] = ['type' => 'success', 'msg' => "Deducted $$amount USD and refunded back to your wallet!"];
                    }
                } else {
                    $pdo->rollBack();
                }
            } catch (Exception $e) { $pdo->rollBack(); }
        }
        header('Location: dashboard.php');
        exit;
    }

    // Delete Sub-Reseller + Auto-Refund
    if ($_POST['action'] === 'delete_sub_reseller' && $isReseller) {
        $_SESSION['active_page'] = 'subresellers';
        $targetId = (int)$_POST['target_user_id'];
        
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT balance FROM users WHERE id = ? FOR UPDATE");
            $stmt->execute([$targetId, $user['id']]);
            $subBal = (float)$stmt->fetchColumn();

            $pdo->prepare("DELETE FROM users WHERE id = ? AND created_by = ?")->execute([$targetId, $user['id']]);

            if ($subBal > 0) {
                $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?")->execute([$subBal, $user['id']]);
                $pdo->commit();
                $_SESSION['flash_toast'] = ['type' => 'success', 'msg' => "Sub account deleted and $$subBal USD refunded back to your wallet!"];
            } else {
                $pdo->commit();
                $_SESSION['flash_toast'] = ['type' => 'success', 'msg' => 'Sub account deleted successfully!'];
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['flash_toast'] = ['type' => 'error', 'msg' => 'Failed to delete account!'];
        }
        header('Location: dashboard.php');
        exit;
    }

    // Convert Commission
    if ($_POST['action'] === 'convert_profit' && $isReseller) {
        $_SESSION['active_page'] = 'subresellers';
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT profit_balance FROM users WHERE id = ? FOR UPDATE");
            $stmt->execute([$user['id']]);
            $profit = (float)$stmt->fetchColumn();

            if ($profit > 0) {
                $pdo->prepare("UPDATE users SET balance = balance + ?, profit_balance = 0.00 WHERE id = ?")->execute([$profit, $user['id']]);
                $pdo->commit();
                $_SESSION['flash_toast'] = ['type' => 'success', 'msg' => "Converted $$profit USD commission to main wallet!"];
            } else {
                $pdo->rollBack();
                $_SESSION['flash_toast'] = ['type' => 'error', 'msg' => 'Commission wallet is empty!'];
            }
        } catch (Exception $e) { $pdo->rollBack(); }
        header('Location: dashboard.php');
        exit;
    }

    // Cashout Commission
    if ($_POST['action'] === 'withdraw_profit' && $isReseller) {
        $_SESSION['active_page'] = 'subresellers';
        $wAmount = (float)$_POST['withdraw_amount'];
        $bkashNum = trim($_POST['bkash_number']);

        if ($wAmount > 0 && !empty($bkashNum)) {
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("SELECT profit_balance FROM users WHERE id = ? FOR UPDATE");
                $stmt->execute([$user['id']]);
                $profit = (float)$stmt->fetchColumn();

                if ($profit >= $wAmount) {
                    $pdo->prepare("UPDATE users SET profit_balance = profit_balance - ? WHERE id = ?")->execute([$wAmount, $user['id']]);
                    $pdo->prepare("INSERT INTO profit_withdrawals (user_id, amount, bkash_number) VALUES (?, ?, ?)")->execute([$user['id'], $wAmount, $bkashNum]);
                    $pdo->commit();
                    $_SESSION['flash_toast'] = ['type' => 'success', 'msg' => "Cashout request for $$wAmount USD submitted!"];
                } else {
                    $pdo->rollBack();
                    $_SESSION['flash_toast'] = ['type' => 'error', 'msg' => 'Insufficient commission balance!'];
                }
            } catch (Exception $e) { $pdo->rollBack(); }
        }
        header('Location: dashboard.php');
        exit;
    }

    // Add Fund Engine (SNZ Pay & Manual)
    if ($_POST['action'] === 'add_fund') {
        if ($canAddFund !== 1) {
            $_SESSION['flash_toast'] = ['type' => 'error', 'msg' => 'Deposit Balance is disabled for your account!'];
            header('Location: dashboard.php');
            exit;
        }

        $_SESSION['active_page'] = 'addfund';
        $method = htmlspecialchars($_POST['method'] ?? 'bKash');
        $rawAmount = (float)($_POST['amount'] ?? 0);
        $sender = htmlspecialchars(trim($_POST['sender'] ?? ''));
        $trxId = trim(htmlspecialchars(strtoupper($_POST['trx_id'] ?? '')));

        if ($rawAmount <= 0 || empty($trxId)) {
            $_SESSION['flash_toast'] = ['type' => 'error', 'msg' => 'Valid Amount and Transaction ID are required!'];
            header('Location: dashboard.php');
            exit;
        }

        $amountInUsd = ($method === 'Binance') ? round($rawAmount, 2) : round($rawAmount / $effectiveBdtRate, 2);

        if ($amountInUsd < $userMinDepositUSD) {
            $minRequiredBDT = round($userMinDepositUSD * $effectiveBdtRate);
            $_SESSION['flash_toast'] = ['type' => 'error', 'msg' => "Minimum deposit limit is $$userMinDepositUSD USD (≈ ৳$minRequiredBDT BDT)!"];
            header('Location: dashboard.php');
            exit;
        }

        if ($amountInUsd > $userMaxDepositUSD) {
            $maxAllowedBDT = round($userMaxDepositUSD * $effectiveBdtRate);
            $_SESSION['flash_toast'] = ['type' => 'error', 'msg' => "Maximum deposit limit is $$userMaxDepositUSD USD (≈ ৳$maxAllowedBDT BDT)!"];
            header('Location: dashboard.php');
            exit;
        }

        $stmtChkTrx = $pdo->prepare("SELECT id, status FROM transactions WHERE UPPER(trx_id) = ? LIMIT 1");
        $stmtChkTrx->execute([$trxId]);
        $existingTrx = $stmtChkTrx->fetch();

        if ($existingTrx) {
            $_SESSION['flash_toast'] = ['type' => 'error', 'msg' => 'This TrxID has already been submitted or approved!'];
            header('Location: dashboard.php');
            exit;
        }

        $autoPaymentOn = ($settings['auto_payment_on'] ?? '0') === '1';
        $snzLicenseKey = trim($settings['snzpay_license_key'] ?? '');
        $snzApiUrl = trim($settings['snzpay_api_url'] ?? 'https://snzpay.digital/api/verify_payment.php');

        if ($autoPaymentOn && !empty($snzLicenseKey)) {
            $snzMethod = strtoupper($method);

            $payload = [
                "license_key" => $snzLicenseKey,
                "trx_id"      => $trxId,
                "method"      => $snzMethod,
                "amount"      => $rawAmount
            ];

            $ch = curl_init($snzApiUrl);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($payload),
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_SSL_VERIFYPEER => false
            ]);
            $snzRes = curl_exec($ch);
            curl_close($ch);

            $result = json_decode($snzRes ?: '', true);

            if ($result && isset($result['status']) && $result['status'] === 'success') {
                $verifiedBDT = (float)($result['data']['amount'] ?? $rawAmount);
                $creditedUSD = round($verifiedBDT / $effectiveBdtRate, 2);

                $stmt = $pdo->prepare("INSERT INTO transactions (user_id, method, amount, sender_num, trx_id, status) VALUES (?, ?, ?, ?, ?, 'approved')");
                $stmt->execute([$user['id'], $method, $creditedUSD, $sender, strtoupper($trxId)]);

                $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?")->execute([$creditedUSD, $user['id']]);

                $_SESSION['flash_toast'] = ['type' => 'success', 'msg' => "Payment Verified! $$creditedUSD USD added to your wallet."];
                header('Location: dashboard.php');
                exit;
            } else {
                $errMsg = $result['message'] ?? 'SNZ Pay Verification Failed! Invalid TrxID or Amount.';
                $_SESSION['flash_toast'] = ['type' => 'error', 'msg' => $errMsg];
                header('Location: dashboard.php');
                exit;
            }
        } else {
            $stmt = $pdo->prepare("INSERT INTO transactions (user_id, method, amount, sender_num, trx_id, status) VALUES (?, ?, ?, ?, ?, 'pending')");
            $stmt->execute([$user['id'], $method, $amountInUsd, $sender, strtoupper($trxId)]);

            $_SESSION['flash_toast'] = ['type' => 'success', 'msg' => "Deposit submitted for $$amountInUsd USD! Waiting for review."];
            header('Location: dashboard.php');
            exit;
        }
    }
}

$toast = $_SESSION['flash_toast'] ?? null;
unset($_SESSION['flash_toast']);

$activePage = $_SESSION['active_page'] ?? 'generate';
unset($_SESSION['active_page']);

// Sub-Resellers List
$subResellers = [];
if ($isReseller) {
    $stmtSubs = $pdo->prepare("
        SELECT u.*, 
        (SELECT COUNT(*) FROM licenses WHERE user_id = u.id) AS sub_keys_count,
        (SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE user_id = u.id AND status = 'approved') AS sub_deposit_total
        FROM users u 
        WHERE u.created_by = ? 
        ORDER BY u.id DESC
    ");
    $stmtSubs->execute([$user['id']]);
    $subResellers = $stmtSubs->fetchAll();
}

$keys = $pdo->query("SELECT * FROM licenses WHERE user_id = {$user['id']} ORDER BY id DESC LIMIT 20")->fetchAll();
$keys = lm_enrich_licenses_reset($pdo, $keys);
$totalKeysCount = (int)$pdo->query("SELECT COUNT(*) FROM licenses WHERE user_id = {$user['id']}")->fetchColumn();
$deposits = $pdo->query("SELECT * FROM transactions WHERE user_id = {$user['id']} ORDER BY id DESC LIMIT 8")->fetchAll();

$stmtAllKeys = $pdo->prepare("SELECT l.license_key, l.validity, l.reset_api_id, l.plan_id, l.product_id, p.name as product_name 
                             FROM licenses l 
                             LEFT JOIN products p ON l.product_id = p.id 
                             WHERE l.user_id = ? ORDER BY l.id DESC");
$stmtAllKeys->execute([$user['id']]);
$userAllResetKeys = lm_enrich_licenses_reset($pdo, $stmtAllKeys->fetchAll(PDO::FETCH_ASSOC));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Dashboard — <?= SITE_NAME ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts with native Bangla font 'Hind Siliguri' support -->
    <link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
    
    <style>
        *, *::before, *::after {
            -webkit-user-select: none !important;
            -moz-user-select: none !important;
            -ms-user-select: none !important;
            user-select: none !important;
            -webkit-touch-callout: none !important;
        }

        input, textarea {
            -webkit-user-select: text !important;
            -moz-user-select: text !important;
            -ms-user-select: text !important;
            user-select: text !important;
        }

        body { 
            background-color: #0b0717; 
            font-family: 'Plus Jakarta Sans', 'Hind Siliguri', sans-serif; 
            color: #f1f5f9; 
            -webkit-tap-highlight-color: transparent; 
            overflow-x: hidden; 
        }

        .bangla-text {
            font-family: 'Hind Siliguri', 'Plus Jakarta Sans', sans-serif;
        }
        
        .font-mono { font-family: 'JetBrains Mono', monospace; }
        .glass-card { background: #130c26; border: 1px solid #231545; }
        
        .generator-box {
            background: #120b24;
            border: 1px solid #2e1853;
            border-radius: 26px;
            box-shadow: 0 -4px 35px rgba(217, 70, 239, 0.25), 0 10px 40px rgba(0, 0, 0, 0.6);
            position: relative;
        }
        .generator-box::before {
            content: '';
            position: absolute;
            top: 0; left: 15%; right: 15%; height: 2px;
            background: linear-gradient(90deg, transparent, #d946ef, #a855f7, transparent);
        }

        .btn-magenta-glow {
            background: linear-gradient(90deg, #d946ef 0%, #c026d3 50%, #a855f7 100%);
            box-shadow: 0 4px 25px rgba(217, 70, 239, 0.45);
        }
        .btn-magenta-glow:active { transform: scale(0.97); }

        .select-dark {
            background-color: #080312;
            border: 1px solid #251346;
            appearance: none;
            background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23d946ef' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 1rem center;
            background-size: 1.1em;
        }

        .deposit-tab { background: #0f0821; border: 1px solid #211242; }
        .deposit-tab.selected { border-color: #d946ef; background: #1c0e35; box-shadow: 0 0 15px rgba(217, 70, 239, 0.25); }

        .profile-card {
            background: #130c28;
            border: 1px solid #231647;
            border-radius: 20px;
            transition: all 0.2s ease;
        }
        .profile-card:hover {
            border-color: rgba(217, 70, 239, 0.4);
            transform: translateY(-2px);
        }

        @keyframes shakeInput {
            0%, 100% { transform: translateX(0); }
            20%, 60% { transform: translateX(-6px); }
            40%, 80% { transform: translateX(6px); }
        }
        .shake-animation {
            animation: shakeInput 0.35s ease-in-out;
        }

        ::-webkit-scrollbar { width: 5px; height: 5px; }
        ::-webkit-scrollbar-track { background: #080312; }
        ::-webkit-scrollbar-thumb { background: #2e1853; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #d946ef; }
    </style>
</head>
<body class="min-h-screen w-full flex flex-col justify-between relative" oncontextmenu="return false;">

    <!-- Toast Notification -->
    <div id="toastNotification" class="fixed top-4 left-1/2 transform -translate-x-1/2 z-50 transition-all duration-300 pointer-events-none opacity-0 translate-y-[-20px] w-[90%] max-w-sm">
        <div id="toastInner" class="bg-[#1b1035] border border-fuchsia-500/50 shadow-2xl rounded-2xl p-3.5 flex items-center space-x-3 text-xs font-semibold text-fuchsia-200 backdrop-blur-xl">
            <i id="toastIcon" class="fa-solid fa-circle-check text-fuchsia-400 text-base"></i>
            <span id="toastMsg" class="bangla-text">Action Executed!</span>
        </div>
    </div>

    <!-- Official System Notice Modal (With Full Native Bangla Support) -->
    <div id="noticeModal" class="fixed inset-0 bg-black/85 backdrop-blur-md z-50 hidden flex items-center justify-center p-4">
        <div class="generator-box rounded-[26px] w-full max-w-sm p-6 space-y-4 border border-amber-500/40 relative shadow-2xl">
            <div class="text-center space-y-1">
                <div class="w-12 h-12 mx-auto rounded-2xl bg-amber-500/20 text-amber-400 flex items-center justify-center text-xl mb-2 border border-amber-500/40 animate-pulse">
                    <i class="fa-solid fa-bell"></i>
                </div>
                <h3 class="text-sm font-black text-white uppercase tracking-wider bangla-text">অফিসিয়াল নোটিশ বোর্ড</h3>
                <p class="text-[11px] text-gray-400 bangla-text">এডমিন প্যানেল থেকে পাঠানো গুরুত্বপূর্ণ বার্তা</p>
            </div>

            <div class="bg-[#080312] p-4 rounded-2xl border border-amber-500/30 text-xs text-amber-200 leading-relaxed max-h-60 overflow-y-auto bangla-text">
                <p id="noticeModalBody"><?= nl2br(htmlspecialchars($settings['broadcast_notice'] ?? '', ENT_QUOTES, 'UTF-8')) ?></p>
            </div>

            <button type="button" onclick="closeNoticeModal()" class="w-full py-3 rounded-xl bg-gradient-to-r from-amber-500 to-orange-600 text-gray-950 font-black text-xs uppercase tracking-wider hover:opacity-95 transition shadow-lg bangla-text">
                ঠিক আছে, বন্ধ করুন
            </button>
        </div>
    </div>

    <!-- Confirmation Modal: Before Buying Keys -->
    <div id="confirmOrderModal" class="fixed inset-0 bg-black/85 backdrop-blur-md z-50 hidden flex items-center justify-center p-4">
        <div class="generator-box rounded-[26px] w-full max-w-sm p-6 space-y-4 border border-fuchsia-500/50 relative">
            <div class="text-center space-y-1">
                <div class="w-12 h-12 mx-auto rounded-2xl bg-fuchsia-500/20 text-fuchsia-400 flex items-center justify-center text-xl mb-2 border border-fuchsia-500/30">
                    <i class="fa-solid fa-cart-shopping"></i>
                </div>
                <h3 class="text-sm font-black text-white uppercase tracking-wider">Confirm Your Order</h3>
                <p class="text-[11px] text-gray-400">Please review your order details before purchase</p>
            </div>

            <div class="bg-[#080312] p-3.5 rounded-2xl border border-[#251346] space-y-2 text-xs">
                <div class="flex justify-between items-center text-gray-400">
                    <span>Product:</span>
                    <strong id="confirmModalProduct" class="text-white">Product Name</strong>
                </div>
                <div class="flex justify-between items-center text-gray-400">
                    <span>Validity:</span>
                    <strong id="confirmModalValidity" class="text-cyan-300">30 Days</strong>
                </div>
                <div class="flex justify-between items-center text-gray-400">
                    <span>Quantity:</span>
                    <strong id="confirmModalQty" class="text-white font-mono">1 Key(s)</strong>
                </div>
                <div class="flex justify-between items-center pt-2 border-t border-[#1a0e33]">
                    <span class="font-bold text-gray-300">Total Deduction:</span>
                    <strong id="confirmModalTotal" class="text-emerald-400 font-mono font-black text-sm">$0.00 USD</strong>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-2.5">
                <button type="button" onclick="closeConfirmModal()" class="w-full py-3 rounded-xl bg-[#170e30] border border-[#2c1b52] text-gray-300 hover:text-white text-xs font-bold">
                    Cancel
                </button>
                <button type="button" id="confirmOrderBtn" onclick="executeKeyPurchase()" class="w-full py-3 rounded-xl btn-magenta-glow text-white text-xs font-black uppercase tracking-wider">
                    Confirm & Buy
                </button>
            </div>
        </div>
    </div>

    <!-- Sub Account History Modal -->
    <div id="subHistoryModal" class="fixed inset-0 bg-black/85 backdrop-blur-md z-50 hidden flex items-center justify-center p-4">
        <div class="generator-box rounded-[26px] w-full max-w-md p-6 space-y-4 border border-fuchsia-500/50 max-h-[85vh] flex flex-col justify-between">
            <div class="flex justify-between items-center pb-2 border-b border-[#251346]">
                <div class="flex items-center space-x-2">
                    <i class="fa-solid fa-clock-rotate-left text-fuchsia-400 text-sm"></i>
                    <h3 class="text-sm font-bold text-white"><span id="subHistUsername" class="text-cyan-300 font-mono">User</span>'s Activity History</h3>
                </div>
                <button type="button" onclick="closeSubHistoryModal()" class="text-gray-400 hover:text-white p-1"><i class="fa-solid fa-xmark text-base"></i></button>
            </div>

            <div class="flex space-x-2 bg-[#080312] p-1 rounded-xl border border-[#251346] text-xs">
                <button type="button" onclick="switchSubHistTab('subhist-deposits')" id="btn-hist-deposits" class="flex-1 py-2 rounded-xl text-white bg-fuchsia-600 transition text-center font-bold shadow-md">
                    <i class="fa-solid fa-wallet mr-1"></i> Add Fund History
                </button>
                <button type="button" onclick="switchSubHistTab('subhist-keys')" id="btn-hist-keys" class="flex-1 py-2 rounded-xl text-gray-400 hover:text-white transition text-center font-bold">
                    <i class="fa-solid fa-key mr-1"></i> Key History
                </button>
            </div>

            <div id="subhist-deposits" class="subhist-content space-y-2 overflow-y-auto max-h-[50vh] pr-1">
                <div id="subHistDepositsList" class="space-y-2"></div>
            </div>

            <div id="subhist-keys" class="subhist-content hidden space-y-2 overflow-y-auto max-h-[50vh] pr-1">
                <div id="subHistKeysList" class="space-y-2"></div>
            </div>

            <button type="button" onclick="closeSubHistoryModal()" class="w-full py-2.5 rounded-xl bg-[#170e30] border border-[#2c1b52] text-gray-300 hover:text-white text-xs font-bold">
                Close
            </button>
        </div>
    </div>

    <!-- Success Modal: License Delivered -->
    <div id="keySuccessModal" class="fixed inset-0 bg-black/85 backdrop-blur-md z-50 hidden flex items-center justify-center p-4">
        <div class="generator-box rounded-[26px] w-full max-w-md p-6 space-y-4 border border-fuchsia-500/50 relative">
            <div class="text-center space-y-1">
                <div class="w-12 h-12 mx-auto rounded-2xl bg-emerald-500/20 text-emerald-400 flex items-center justify-center text-xl mb-2 border border-emerald-500/30">
                    <i class="fa-solid fa-circle-check"></i>
                </div>
                <h3 class="text-base font-black text-white uppercase tracking-wider">License Key Delivered!</h3>
                <p id="modalProductTitle" class="text-xs text-purple-300 font-semibold">Product Variant</p>
            </div>

            <div class="space-y-1.5">
                <label class="text-[11px] font-bold text-gray-400 uppercase tracking-wider block">Generated License Key:</label>
                <div id="modalKeysContainer" class="space-y-2 max-h-44 overflow-y-auto pr-1"></div>
            </div>

            <button type="button" onclick="copyAllGeneratedKeys()" class="w-full py-2.5 rounded-xl bg-fuchsia-500/20 text-fuchsia-300 border border-fuchsia-500/40 text-xs font-bold hover:bg-fuchsia-500/30 flex items-center justify-center gap-2">
                <i class="fa-regular fa-copy"></i> Copy All Keys
            </button>

            <div class="bg-[#080312] p-4 rounded-2xl border border-[#251346] text-center space-y-2.5">
                <div class="flex items-center justify-center gap-1.5 text-xs font-bold text-cyan-300 uppercase tracking-wide">
                    <i class="fa-solid fa-graduation-cap text-cyan-400"></i> Setup & Installation Guide
                </div>
                <p class="text-[11px] text-gray-400 leading-relaxed">Need help activating this key? Follow the official guide below.</p>
                <a id="modalTutorialBtn" href="<?= htmlspecialchars($effectiveTutorialLink) ?>" target="_blank" class="w-full py-3 rounded-xl bg-gradient-to-r from-cyan-500 to-blue-600 text-gray-950 font-black text-xs uppercase flex items-center justify-center gap-2 shadow-lg shadow-cyan-950/50 hover:opacity-95 transition">
                    <i class="fa-brands fa-telegram text-base"></i> Open Setup Tutorial
                </a>
            </div>

            <button type="button" onclick="closeKeySuccessModal()" class="w-full py-3 rounded-xl bg-[#170e30] border border-[#2c1b52] text-gray-300 hover:text-white text-xs font-bold">
                Done & Close
            </button>
        </div>
    </div>

    <!-- 3-Line Sidebar Drawer -->
    <div id="backdrop" class="fixed inset-0 bg-black/80 backdrop-blur-sm z-40 hidden transition-opacity duration-300" onclick="toggleMenu()"></div>

    <aside id="sidebar" class="fixed top-0 right-0 w-[285px] h-full bg-[#100a22] border-l border-[#241544] z-50 transform translate-x-full transition-transform duration-300 flex flex-col justify-between">
        <div class="p-4">
            <div class="flex items-center justify-between pb-4 pt-1 border-b border-[#20133d]">
                <div class="flex items-center space-x-3">
                    <div class="w-10 h-10 rounded-2xl bg-gradient-to-tr from-fuchsia-600 to-purple-600 flex items-center justify-center text-white text-base font-bold shadow-lg">
                        <i class="fa-solid fa-gem"></i>
                    </div>
                    <div>
                        <h2 class="font-extrabold text-white text-sm tracking-wide"><?= SITE_NAME ?></h2>
                        <span class="text-[9px] <?= $isReseller ? 'text-fuchsia-400' : 'text-cyan-400' ?> tracking-wider font-bold uppercase">
                            <?= $isReseller ? 'RESELLER PRO' : 'CLIENT CONSOLE' ?>
                        </span>
                    </div>
                </div>
                <button onclick="toggleMenu()" class="text-gray-400 hover:text-white p-2"><i class="fa-solid fa-xmark text-lg"></i></button>
            </div>

            <div class="space-y-1.5 text-xs font-semibold mt-4">
                <button onclick="goToPage('dashboard')" id="nav-dashboard" class="nav-item w-full flex items-center space-x-3 px-4 py-3 rounded-xl text-gray-300 hover:bg-[#1b1138] transition">
                    <i class="fa-solid fa-chart-pie w-4"></i><span>Dashboard</span>
                </button>
                <button onclick="goToPage('generate')" id="nav-generate" class="nav-item w-full flex items-center space-x-3 px-4 py-3 rounded-xl bg-gradient-to-r from-fuchsia-600 to-purple-600 text-white font-bold transition">
                    <i class="fa-solid fa-bolt w-4"></i><span>Generate Keys</span>
                </button>
                <button onclick="goToPage('resetkey')" id="nav-resetkey" class="nav-item w-full flex items-center space-x-3 px-4 py-3 rounded-xl text-gray-300 hover:bg-[#1b1138] hover:text-cyan-400 transition">
                    <i class="fa-solid fa-arrows-rotate w-4 text-cyan-400"></i><span>Reset Key</span>
                </button>
                
                <?php if ($canAddFund === 1): ?>
                <button onclick="goToPage('addfund')" id="nav-addfund" class="nav-item w-full flex items-center space-x-3 px-4 py-3 rounded-xl text-gray-300 hover:bg-[#1b1138] transition">
                    <i class="fa-solid fa-wallet w-4"></i><span>Deposit Balance</span>
                </button>
                <?php endif; ?>

                <?php if ($isReseller): ?>
                <button onclick="goToPage('subresellers')" id="nav-subresellers" class="nav-item w-full flex items-center space-x-3 px-4 py-3 rounded-xl text-gray-300 hover:bg-[#1b1138] transition">
                    <i class="fa-solid fa-users-gear w-4 text-emerald-400"></i><span>Sub Reseller</span>
                </button>
                <?php endif; ?>

                <button onclick="goToPage('history')" id="nav-history" class="nav-item w-full flex items-center space-x-3 px-4 py-3 rounded-xl text-gray-300 hover:bg-[#1b1138] transition">
                    <i class="fa-solid fa-clock-rotate-left w-4"></i><span>Key History</span>
                </button>

                <?php if ($canAddFund === 1): ?>
                <button onclick="goToPage('transactions')" id="nav-transactions" class="nav-item w-full flex items-center space-x-3 px-4 py-3 rounded-xl text-gray-300 hover:bg-[#1b1138] transition">
                    <i class="fa-solid fa-money-bill-transfer w-4"></i><span>Transactions</span>
                </button>
                <?php endif; ?>
            </div>
        </div>

        <div class="p-4 pb-6 space-y-2">
            <?php if ($isReseller): ?>
            <button onclick="goToPage('api')" id="nav-api" class="nav-item w-full flex items-center space-x-3 px-4 py-3 rounded-xl text-cyan-300 hover:bg-[#1b1138] border border-cyan-500/30 transition font-bold">
                <i class="fa-solid fa-code w-4 text-cyan-400"></i><span>API Integration</span>
            </button>
            <?php endif; ?>

            <a href="dashboard.php?logout=1" class="w-full flex items-center justify-center space-x-2 py-3.5 bg-[#25101e] border border-red-500/30 text-red-400 hover:bg-red-500/20 rounded-xl text-xs font-bold transition">
                <i class="fa-solid fa-power-off"></i><span>Logout Session</span>
            </a>
        </div>
    </aside>

    <!-- Main Viewport -->
    <main class="w-full flex-grow px-3 pt-3 pb-8 max-w-lg mx-auto">
        <header class="w-full flex items-center justify-between py-2.5 mb-3 px-0.5">
            <div class="flex items-center space-x-2.5 cursor-pointer" onclick="goToPage('dashboard')">
                <div class="w-10 h-10 rounded-2xl bg-gradient-to-tr from-fuchsia-600 to-purple-600 flex items-center justify-center text-white text-lg font-bold shadow-lg">
                    <i class="fa-solid fa-gem"></i>
                </div>
                <div>
                    <h1 class="text-sm font-black text-white tracking-wider leading-none"><?= SITE_NAME ?></h1>
                    <span class="text-[9px] <?= $isReseller ? 'text-fuchsia-400' : 'text-cyan-400' ?> font-bold uppercase font-mono">
                        <?= $isReseller ? 'RESELLER CONSOLE' : 'CLIENT CONSOLE' ?>
                    </span>
                </div>
            </div>

            <div class="flex items-center space-x-2">
                <!-- Notice Icon with Pulse Indicator -->
                <button onclick="openNoticeModal()" class="w-10 h-10 flex items-center justify-center bg-[#170e30] border border-amber-500/40 rounded-2xl text-amber-400 hover:bg-amber-500/20 active:scale-90 transition relative" title="System Notice">
                    <i class="fa-solid fa-bell text-base"></i>
                    <span class="absolute top-2 right-2 w-2 h-2 rounded-full bg-amber-400 animate-ping"></span>
                    <span class="absolute top-2 right-2 w-2 h-2 rounded-full bg-amber-400"></span>
                </button>

                <!-- Live Wallet Balance -->
                <div <?= $canAddFund === 1 ? 'onclick="goToPage(\'addfund\')"' : '' ?> class="<?= $canAddFund === 1 ? 'cursor-pointer' : 'cursor-default' ?> flex items-center space-x-1.5 px-3 py-2 bg-[#170e30] border border-emerald-500/30 rounded-2xl shadow-sm" title="Deposit Balance">
                    <i class="fa-solid fa-wallet text-[11px] text-emerald-400"></i>
                    <span id="liveHeaderBalance" class="text-xs font-black text-[#00ff88] font-mono leading-none">
                        $<?= number_format((float)$user['balance'], 2) ?>
                    </span>
                </div>

                <!-- Drawer Toggle -->
                <button onclick="toggleMenu()" class="w-10 h-10 flex items-center justify-center bg-[#170e30] border border-fuchsia-500/40 rounded-2xl text-fuchsia-400 active:scale-90 transition">
                    <i class="fa-solid fa-bars text-base"></i>
                </button>
            </div>
        </header>

        <!-- 1. KEY GENERATOR -->
        <section id="page-generate" class="page-section w-full space-y-4">
            <div class="generator-box p-5 sm:p-6 space-y-4">
                <div class="text-center pb-2">
                    <div class="inline-block pb-2 border-b border-fuchsia-500/40">
                        <h2 class="text-base sm:text-lg font-black tracking-widest text-white uppercase flex items-center justify-center gap-2">
                            <i class="fa-solid fa-bolt text-fuchsia-400 text-lg"></i> KEY GENERATOR
                        </h2>
                    </div>
                    <p class="text-xs text-purple-300/70 mt-2 font-medium">Generate genuine license keys directly from stock/provider</p>
                </div>

                <div class="h-[1px] w-full bg-gradient-to-r from-transparent via-[#2e1853] to-transparent mb-3"></div>

                <form id="liveKeyForm" onsubmit="promptOrderConfirmation(event)" class="space-y-4 text-xs">
                    <div>
                        <label class="text-[11px] font-extrabold text-gray-300 block mb-1.5 tracking-wider flex items-center gap-2 uppercase">
                            <i class="fa-solid fa-box-open text-fuchsia-400 text-sm"></i> PRODUCT TYPE:
                        </label>
                        <select id="genProductType" onchange="onProductTypeChange()" class="select-dark w-full text-sm text-white font-semibold rounded-2xl p-3.5 outline-none focus:border-fuchsia-500 cursor-pointer">
                            <?php if (empty($catalogStructure)): ?>
                                <option value="">No products available. Add products in Admin Panel.</option>
                            <?php else: ?>
                                <?php foreach ($catalogStructure as $pName => $plans): ?>
                                    <?php if (in_array($pName, $userAllowedProducts)): ?>
                                        <option value="<?= htmlspecialchars($pName) ?>"><?= htmlspecialchars($pName) ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div>
                        <div class="flex items-center justify-between mb-1.5">
                            <label class="text-[11px] font-extrabold text-gray-300 tracking-wider flex items-center gap-2 uppercase">
                                <i class="fa-solid fa-hourglass-half text-fuchsia-400 text-sm"></i> VALIDITY & DURATION:
                            </label>
                            <span id="genDiscountTag" class="hidden text-[10px] font-black uppercase px-2 py-0.5 rounded-full bg-emerald-500/20 text-emerald-400 border border-emerald-500/40 animate-pulse">
                                🔥 DISCOUNT OFFER
                            </span>
                        </div>
                        <select id="genValidity" onchange="calculateTotal()" class="select-dark w-full text-sm text-white font-semibold rounded-2xl p-3.5 outline-none focus:border-fuchsia-500 cursor-pointer">
                        </select>
                    </div>

                    <!-- Genuine Discount Notice Box -->
                    <div id="genDiscountNoticeBox" class="hidden p-3 rounded-2xl bg-[#080312] border border-emerald-500/40 flex items-center justify-between text-xs transition duration-300">
                        <div class="flex items-center space-x-2">
                            <div class="w-7 h-7 rounded-xl bg-emerald-500/20 text-emerald-400 flex items-center justify-center text-xs">
                                <i class="fa-solid fa-tags"></i>
                            </div>
                            <div>
                                <span class="text-[11px] font-bold text-white block">Promotional Discount Applied!</span>
                                <span class="text-[10px] text-gray-400">Regular: <s id="genRegularPriceTxt" class="text-gray-500">$0.00</s> → Deal: <strong id="genDealPriceTxt" class="text-emerald-400 font-mono">$0.00</strong></span>
                            </div>
                        </div>
                        <span id="genDiscountPercentBadge" class="text-xs font-black font-mono px-2 py-1 rounded-xl bg-emerald-500/20 text-emerald-300 border border-emerald-500/30">
                            0% OFF
                        </span>
                    </div>

                    <div>
                        <label class="text-[11px] font-extrabold text-gray-300 block mb-1.5 tracking-wider flex items-center gap-2 uppercase">
                            <i class="fa-solid fa-cubes-stacked text-fuchsia-400 text-sm"></i> QUANTITY:
                        </label>
                        <div class="flex items-center space-x-3">
                            <button type="button" onclick="changeQuantity(-1)" class="w-12 h-12 rounded-2xl btn-magenta-glow flex items-center justify-center text-white text-xl font-bold transition">-</button>
                            <input type="number" id="genQuantity" value="1" min="1" max="50" readonly class="flex-grow text-center bg-[#080312] border border-[#251346] text-lg font-black text-white rounded-2xl py-2.5 font-mono">
                            <button type="button" onclick="changeQuantity(1)" class="w-12 h-12 rounded-2xl btn-magenta-glow flex items-center justify-center text-white text-xl font-bold transition">+</button>
                        </div>
                    </div>

                    <button type="submit" id="genSubmitBtn" class="w-full py-4 rounded-2xl btn-magenta-glow text-white font-black text-sm tracking-wider uppercase flex items-center justify-center gap-2 mt-2 transition">
                        <i class="fa-solid fa-bolt"></i> <span id="btnText">GENERATE KEYS</span>
                    </button>
                </form>
            </div>
        </section>

        <!-- 2. RESET KEY SYSTEM (Permitted 6 APIs + Angry Mod Pinxt Direct Engine) -->
        <section id="page-resetkey" class="page-section hidden w-full space-y-4">
            <div class="generator-box p-5 sm:p-6 space-y-4">
                <div class="text-center pb-2">
                    <div class="inline-block pb-2 border-b border-cyan-500/40">
                        <h2 class="text-base sm:text-lg font-black tracking-widest text-white uppercase flex items-center justify-center gap-2">
                            <i class="fa-solid fa-arrows-rotate text-cyan-400 text-lg"></i> RESET KEY / HWID
                        </h2>
                    </div>
                    <p class="text-xs text-purple-300/70 mt-2 font-medium">Select your permitted reset API or Angry Mod and enter key to unbind device</p>
                </div>

                <div class="h-[1px] w-full bg-gradient-to-r from-transparent via-[#2e1853] to-transparent mb-3"></div>

                <form id="liveResetForm" onsubmit="handleLiveKeyReset(event)" class="space-y-4 text-xs">
                    <!-- Step 1: Select API (Permitted 6 APIs + Angry Mod Pinxt) -->
                    <div>
                        <div class="flex justify-between items-center mb-1.5">
                            <label class="text-[11px] font-extrabold text-gray-300 tracking-wider uppercase flex items-center gap-2">
                                <i class="fa-solid fa-plug text-cyan-400"></i> SELECT RESET API:
                            </label>
                            <span id="resetApiBadge" class="text-[10px] font-mono text-cyan-400 font-bold bg-cyan-500/10 px-2 py-0.5 rounded border border-cyan-500/30">
                                100/day Limit
                            </span>
                        </div>
                        <select id="resetApiSelect" onchange="onResetApiSelectionChange()" class="select-dark w-full text-xs sm:text-sm text-cyan-300 font-mono font-bold rounded-2xl p-3.5 outline-none focus:border-cyan-400 cursor-pointer">
                            <?php foreach ($permittedResetApis as $aId => $aInfo): ?>
                                <option value="<?= $aId ?>" data-type="<?= $aInfo['type'] ?>">
                                    <?= ($aId !== 99 ? '#' . $aId . ' • ' : '') . htmlspecialchars($aInfo['name']) . ' • ' . htmlspecialchars($aInfo['slug']) . ' • ' . htmlspecialchars($aInfo['limit']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Angry Mod Custom Token Box (Appears when Angry Mod is selected) -->
                    <div id="angryModTokenBox" class="hidden space-y-1.5 bg-[#080312] p-3 rounded-2xl border border-cyan-500/30 transition">
                        <label class="text-[11px] font-extrabold text-amber-300 tracking-wider uppercase flex items-center gap-1.5">
                            <i class="fa-solid fa-shield-keyhole text-amber-400"></i> ANGRY MOD ACCESS TOKEN:
                        </label>
                        <input type="text" id="angryModTokenInput" value="" placeholder="Leave blank = server default token" class="w-full bg-[#120b24] border border-[#251346] text-xs text-amber-200 font-mono rounded-xl p-2.5 outline-none focus:border-amber-400">
                    </div>

                    <!-- Step 2: Input or Paste Key with Live Purchased Suggestion -->
                    <div class="relative">
                        <div class="flex items-center justify-between mb-1.5">
                            <label class="text-[11px] font-extrabold text-gray-300 tracking-wider uppercase flex items-center gap-2">
                                <i class="fa-solid fa-key text-cyan-400"></i> LICENSE KEY TO RESET:
                            </label>
                            <span class="text-[10px] text-cyan-400/80 font-mono">e.g. USERKEY-XXXX-XXXX</span>
                        </div>
                        
                        <div class="relative">
                            <input type="text" id="resetKeySearchInput" autocomplete="off" placeholder="Enter or paste key (e.g. USERKEY-XXXX-XXXX)" oninput="onResetKeySearchInput(this.value)" onfocus="onResetKeySearchInput(this.value)" class="w-full bg-[#080312] border border-[#251346] text-xs sm:text-sm text-cyan-300 font-mono rounded-2xl p-3.5 pr-11 outline-none focus:border-cyan-400 uppercase transition tracking-wider">
                            <div class="absolute right-3.5 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none">
                                <i class="fa-solid fa-keyboard text-sm text-cyan-400/70"></i>
                            </div>
                        </div>

                        <!-- Dropdown list of keys for quick pick -->
                        <div id="resetSearchResultsBox" class="hidden absolute left-0 right-0 top-full mt-1.5 bg-[#0e0722] border border-cyan-500/40 rounded-2xl max-h-56 overflow-y-auto z-30 shadow-2xl p-1.5 space-y-1 backdrop-blur-xl">
                        </div>
                    </div>

                    <button type="submit" id="resetSubmitBtn" class="w-full py-4 rounded-2xl bg-gradient-to-r from-cyan-500 to-blue-600 text-gray-950 font-black text-sm tracking-wider uppercase flex items-center justify-center gap-2 mt-2 transition shadow-lg shadow-cyan-950/40">
                        <i class="fa-solid fa-arrows-rotate"></i> <span id="resetBtnText">RESET KEY</span>
                    </button>
                </form>

                <div id="resetResultBox" class="hidden mt-4 p-4 rounded-2xl border text-xs leading-relaxed"></div>
            </div>
        </section>

        <!-- 3. SUB RESELLER -->
        <?php if ($isReseller): ?>
        <section id="page-subresellers" class="page-section hidden w-full space-y-4">
            <div class="generator-box p-4 flex justify-between items-center">
                <div>
                    <span class="text-[10px] font-bold text-gray-400 uppercase block">Commission & Profit Wallet</span>
                    <h3 class="text-xl font-black text-[#00ff88] font-mono">$<?= number_format((float)($user['profit_balance'] ?? 0), 2) ?> USD</h3>
                </div>
                <div class="flex space-x-1.5">
                    <form method="POST" class="inline">
                        <input type="hidden" name="action" value="convert_profit">
                        <button type="submit" class="px-3 py-2 rounded-xl bg-emerald-500/20 text-emerald-300 border border-emerald-500/40 text-[11px] font-bold hover:bg-emerald-500/30">
                            Convert
                        </button>
                    </form>
                    <button type="button" onclick="document.getElementById('withdrawModal').classList.remove('hidden')" class="px-3 py-2 rounded-xl bg-fuchsia-500/20 text-fuchsia-300 border border-fuchsia-500/40 text-[11px] font-bold hover:bg-fuchsia-500/30">
                        Cashout
                    </button>
                </div>
            </div>

            <div class="flex space-x-2 bg-[#080312] p-1 rounded-2xl border border-[#251346] text-xs font-bold">
                <button type="button" onclick="switchSubTab('sub-list')" id="tab-btn-list" class="sub-tab-btn flex-1 py-2.5 rounded-xl text-white bg-fuchsia-600 transition text-center">
                    Sub Accounts (<?= count($subResellers) ?>)
                </button>
                <button type="button" onclick="switchSubTab('sub-add')" id="tab-btn-add" class="sub-tab-btn flex-1 py-2.5 rounded-xl text-gray-400 hover:text-white transition text-center">
                    + Add New Account
                </button>
            </div>

            <div id="sub-list" class="sub-tab-content space-y-3">
                <?php if (empty($subResellers)): ?>
                    <div class="glass-card rounded-2xl p-8 text-center text-gray-500 text-xs">
                        No sub accounts registered under your account yet.
                    </div>
                <?php else: ?>
                    <div class="grid grid-cols-1 gap-3">
                        <?php foreach ($subResellers as $sub): ?>
                            <div class="profile-card p-4 flex flex-col justify-between space-y-3">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center space-x-3">
                                        <div class="w-11 h-11 rounded-2xl bg-gradient-to-tr from-fuchsia-600/30 to-purple-600/30 border border-fuchsia-500/30 flex items-center justify-center text-fuchsia-300 text-base font-black">
                                            <?= strtoupper(substr($sub['username'], 0, 1)) ?>
                                        </div>
                                        <div>
                                            <h4 class="font-bold text-white text-sm leading-none"><?= htmlspecialchars($sub['username']) ?></h4>
                                            <span class="text-[10px] text-gray-400 font-mono mt-1 block">ID: #<?= $sub['id'] ?> • Joined <?= date('M d, Y', strtotime($sub['created_at'])) ?></span>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <span class="text-xs font-black text-emerald-400 font-mono block">$<?= number_format((float)$sub['balance'], 2) ?></span>
                                        <span class="text-[9px] font-bold px-2 py-0.5 rounded-full <?= $sub['role'] === 'reseller' ? 'bg-fuchsia-500/20 text-fuchsia-300 border border-fuchsia-500/40' : 'bg-cyan-500/20 text-cyan-300 border border-cyan-500/40' ?>">
                                            <?= strtoupper($sub['role'] === 'reseller' ? 'RESELLER' : 'CLIENT') ?>
                                        </span>
                                    </div>
                                </div>

                                <div class="grid grid-cols-3 gap-2 bg-[#080312] p-2.5 rounded-xl border border-[#1a0e33] text-center font-mono">
                                    <div>
                                        <span class="text-[9px] text-gray-400 block font-semibold">Keys Bought</span>
                                        <strong class="text-cyan-300 text-xs font-bold"><?= (int)($sub['sub_keys_count'] ?? 0) ?></strong>
                                    </div>
                                    <div>
                                        <span class="text-[9px] text-gray-400 block font-semibold">Deposited</span>
                                        <strong class="text-emerald-400 text-xs font-bold">$<?= number_format((float)($sub['sub_deposit_total'] ?? 0), 2) ?></strong>
                                    </div>
                                    <div>
                                        <span class="text-[9px] text-gray-400 block font-semibold">Granted Bal</span>
                                        <strong class="text-amber-300 text-xs font-bold">$<?= number_format((float)($sub['parent_granted_balance'] ?? 0), 2) ?></strong>
                                    </div>
                                </div>

                                <div class="flex items-center justify-between pt-2.5 border-t border-[#1e1338] text-[11px] gap-2 flex-wrap sm:flex-nowrap">
                                    <span class="text-gray-400 font-mono">Rate: <strong class="text-white"><?= (float)$sub['custom_bdt_rate'] ?: $effectiveBdtRate ?> BDT</strong></span>
                                    
                                    <div class="flex items-center space-x-2">
                                        <button type="button" onclick="openSubHistoryModal(<?= $sub['id'] ?>, '<?= htmlspecialchars(addslashes($sub['username'])) ?>')" class="px-3 py-1.5 rounded-xl bg-[#1c0f38] border border-fuchsia-500/30 text-fuchsia-300 hover:bg-fuchsia-500/20 font-bold text-xs flex items-center gap-1.5 transition shadow-sm" title="View Deposit & Key History">
                                            <i class="fa-solid fa-clock-rotate-left"></i> History
                                        </button>
                                        <button type="button" onclick="openManageSubModal(<?= htmlspecialchars(json_encode($sub)) ?>)" class="px-3.5 py-1.5 rounded-xl bg-gradient-to-r from-fuchsia-600 to-purple-600 text-white font-bold text-xs flex items-center gap-1.5 shadow-md shadow-fuchsia-950/40 hover:opacity-95 transition">
                                            <i class="fa-regular fa-id-card"></i> Manage & Balance
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div id="sub-add" class="sub-tab-content hidden space-y-4 text-xs">
                <div class="generator-box p-5 space-y-3">
                    <h3 class="text-sm font-bold text-white flex items-center gap-2">
                        <i class="fa-solid fa-user-plus text-fuchsia-400"></i> Register New Sub Account
                    </h3>
                    <form method="POST" class="space-y-3">
                        <input type="hidden" name="action" value="create_sub_reseller">
                        <div>
                            <label class="text-[11px] text-gray-400 block mb-1">Username</label>
                            <input type="text" name="sub_username" required placeholder="e.g. reseller_kabir" class="w-full bg-[#080312] border border-[#251346] p-3 rounded-xl text-white outline-none">
                        </div>
                        <div>
                            <label class="text-[11px] text-gray-400 block mb-1">Password</label>
                            <input type="password" name="sub_password" required placeholder="Assign account password" class="w-full bg-[#080312] border border-[#251346] p-3 rounded-xl text-white outline-none">
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="text-[11px] text-gray-400 block mb-1 font-semibold">Access Level</label>
                                <select name="sub_role" class="w-full bg-[#080312] border border-[#251346] p-3 rounded-xl text-cyan-300 font-bold outline-none">
                                    <option value="user" selected>Client (User)</option>
                                    <option value="reseller">Reseller Access</option>
                                </select>
                            </div>
                            <div>
                                <label class="text-[11px] text-gray-400 block mb-1">Initial Balance ($ USD)</label>
                                <input type="number" step="0.5" name="sub_balance" value="0.00" class="w-full bg-[#080312] border border-[#251346] p-3 rounded-xl text-emerald-400 font-mono font-bold outline-none">
                            </div>
                        </div>

                        <div>
                            <label class="text-[11px] text-gray-400 block mb-1">Custom Deposit Rate (Min: <?= $effectiveBdtRate ?> BDT)</label>
                            <input type="number" step="0.5" min="<?= $effectiveBdtRate ?>" name="custom_bdt_rate" value="<?= $effectiveBdtRate ?>" placeholder="Min <?= $effectiveBdtRate ?>" class="w-full bg-[#080312] border border-[#251346] p-3 rounded-xl text-emerald-300 font-mono outline-none">
                        </div>

                        <div class="flex items-center justify-between p-2.5 bg-[#080312] rounded-xl border border-[#251346] text-gray-300">
                            <?php if (((int)($user['api_enabled'] ?? 1)) === 1): ?>
                            <label class="flex items-center space-x-2 cursor-pointer">
                                <input type="checkbox" name="api_enabled" value="1" checked class="rounded text-fuchsia-500">
                                <span>API Access</span>
                            </label>
                            <?php else: ?>
                            <span class="text-[10px] text-gray-500 italic">API disabled for your tier</span>
                            <?php endif; ?>

                            <?php if ($canAddFund === 1): ?>
                            <label class="flex items-center space-x-2 cursor-pointer">
                                <input type="checkbox" name="can_add_fund" value="1" checked class="rounded text-fuchsia-500">
                                <span>Deposit Balance Access</span>
                            </label>
                            <?php else: ?>
                            <span class="text-[10px] text-gray-500 italic">Deposit disabled for your tier</span>
                            <?php endif; ?>
                        </div>

                        <button type="submit" class="w-full py-3.5 btn-magenta-glow text-white font-black rounded-xl uppercase tracking-wider">
                            Register Sub Account
                        </button>
                    </form>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <!-- 4. DEPOSIT BALANCE -->
        <?php if ($canAddFund === 1): ?>
        <section id="page-addfund" class="page-section hidden w-full space-y-4">
            <div class="generator-box p-5 space-y-4">
                <div>
                    <h3 class="text-sm font-extrabold text-white flex items-center gap-2">
                        <i class="fa-solid fa-wallet text-fuchsia-400"></i> Deposit Balance Gateway
                    </h3>
                    <p class="text-[11px] text-gray-400 mt-0.5 font-mono">
                        Exchange Rate: <strong class="text-emerald-400">1 USD = <?= $effectiveBdtRate ?> BDT</strong>
                    </p>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 text-xs">
                    <div onclick="selectDepositMethod('bKash', '<?= htmlspecialchars($settings['bkash_number']) ?>', 'Send Money (Personal)', 'BDT', this)" class="deposit-tab selected p-3 rounded-2xl cursor-pointer text-center">
                        <span class="font-extrabold text-pink-400 block"><i class="fa-solid fa-paper-plane mr-1"></i> bKash</span>
                        <span class="text-[9px] text-gray-400">Pay in BDT</span>
                    </div>
                    <div onclick="selectDepositMethod('Nagad', '<?= htmlspecialchars($settings['nagad_number']) ?>', 'Send Money (Personal)', 'BDT', this)" class="deposit-tab p-3 rounded-2xl cursor-pointer text-center">
                        <span class="font-extrabold text-orange-400 block"><i class="fa-solid fa-paper-plane mr-1"></i> Nagad</span>
                        <span class="text-[9px] text-gray-400">Pay in BDT</span>
                    </div>
                    <div onclick="selectDepositMethod('Rocket', '<?= htmlspecialchars($settings['rocket_number']) ?>', 'Personal Transfer', 'BDT', this)" class="deposit-tab p-3 rounded-2xl cursor-pointer text-center">
                        <span class="font-extrabold text-purple-400 block"><i class="fa-solid fa-wallet mr-1"></i> Rocket</span>
                        <span class="text-[9px] text-gray-400">Pay in BDT</span>
                    </div>
                    <div onclick="selectDepositMethod('Binance', '<?= htmlspecialchars($settings['binance_id']) ?>', 'Binance Pay ID (USDT)', 'USDT', this)" class="deposit-tab p-3 rounded-2xl cursor-pointer text-center">
                        <span class="font-extrabold text-yellow-400 block"><i class="fa-brands fa-bitcoin mr-1"></i> Binance</span>
                        <span class="text-[9px] text-gray-400">Direct USDT</span>
                    </div>
                </div>

                <div class="bg-[#080312] border border-[#251346] p-3.5 rounded-2xl flex items-center justify-between">
                    <div>
                        <span id="depMethodType" class="text-[10px] text-gray-400 font-bold block uppercase tracking-wider">Send Money (Personal)</span>
                        <span id="depNumberText" class="text-sm font-black text-white font-mono tracking-wider"><?= htmlspecialchars($settings['bkash_number']) ?></span>
                    </div>
                    <button type="button" onclick="copyDepositNumber()" class="px-3 py-1.5 rounded-xl bg-fuchsia-500/20 text-fuchsia-300 border border-fuchsia-500/40 text-xs font-bold hover:bg-fuchsia-500/30 flex items-center gap-1.5">
                        <i class="fa-regular fa-copy"></i> Copy
                    </button>
                </div>

                <form method="POST" id="depositSubmitForm" onsubmit="return validateDepositLimits()" class="space-y-3 text-xs">
                    <input type="hidden" name="action" value="add_fund">
                    <input type="hidden" id="hiddenMethod" name="method" value="bKash">

                    <div>
                        <div class="flex justify-between items-center mb-1">
                            <label id="amountLabel" class="text-[11px] text-gray-400 font-bold">Amount to Pay in BDT (৳)</label>
                            <span id="liveConversionTag" class="text-[10px] font-mono text-emerald-400 font-bold">≈ $0.00 USD</span>
                        </div>
                        <input type="number" step="any" id="depositAmountInput" name="amount" oninput="calculateDepositConversion()" required placeholder="e.g. 125" class="w-full bg-[#080312] border border-[#251346] text-sm text-white rounded-xl p-3 outline-none focus:border-fuchsia-500 font-mono transition">
                        
                        <div id="depositLimitNotice" class="text-[10px] font-mono mt-1 text-gray-400 font-semibold flex items-center justify-between">
                            <span id="minMaxHintText">Min: $<?= number_format($userMinDepositUSD, 2) ?> | Max: $<?= number_format($userMaxDepositUSD, 2) ?> USD</span>
                            <span id="limitErrorAlert" class="text-red-400 hidden font-bold"></span>
                        </div>
                    </div>

                    <div>
                        <label class="text-[11px] text-gray-400 block mb-1">Sender Mobile No / Binance UID</label>
                        <input type="text" name="sender" required placeholder="Sender Number or UID" class="w-full bg-[#080312] border border-[#251346] text-sm text-white rounded-xl p-3 outline-none focus:border-fuchsia-500 font-mono">
                    </div>
                    <div>
                        <label class="text-[11px] text-gray-400 block mb-1">Transaction ID (TrxID)</label>
                        <input type="text" name="trx_id" required placeholder="e.g. 9A8B7C6D" class="w-full bg-[#080312] border border-[#251346] text-sm text-fuchsia-300 font-mono uppercase rounded-xl p-3 outline-none focus:border-fuchsia-500">
                    </div>
                    <button type="submit" id="submitDepositBtn" class="w-full py-3.5 btn-magenta-glow text-white font-black rounded-xl text-xs uppercase tracking-wider transition">
                        Verify & Submit Deposit
                    </button>
                </form>
            </div>
        </section>
        <?php endif; ?>

        <!-- 5. DEVELOPER BOT INTEGRATION & PLAN ID DIRECTORY (WITH 7 RESET APIS) -->
        <?php if ($isReseller): ?>
        <section id="page-api" class="page-section hidden w-full space-y-4">
            
            <!-- API Token Box -->
            <div class="generator-box p-5 space-y-3">
                <div class="flex justify-between items-center pb-2 border-b border-[#251346]">
                    <div>
                        <h3 class="text-sm font-black text-white flex items-center gap-2">
                            <i class="fa-solid fa-key text-fuchsia-400"></i> API Authorization Token
                        </h3>
                        <p class="text-[11px] text-gray-400 mt-0.5">Use this branded bearer token in headers or query parameters</p>
                    </div>
                </div>

                <div>
                    <label class="text-[11px] text-gray-400 block mb-1 font-bold">Your Secret Token:</label>
                    <div class="flex items-center bg-[#080312] p-2 rounded-xl border border-[#251346] space-x-2">
                        <input type="password" id="apiVal" value="<?= htmlspecialchars($user['api_key']) ?>" readonly class="w-full bg-transparent font-mono text-fuchsia-300 outline-none text-xs tracking-wider">
                        <button type="button" onclick="toggleApiKeyVisibility()" class="text-gray-400 hover:text-white px-2 text-sm" title="Show / Hide Token">
                            <i id="apiEyeIcon" class="fa-solid fa-eye"></i>
                        </button>
                        <button type="button" onclick="copyToClipboard(document.getElementById('apiVal').value, 'API Key')" class="text-cyan-400 hover:text-cyan-300 px-2 text-sm" title="Copy Token">
                            <i class="fa-regular fa-copy"></i>
                        </button>
                    </div>
                </div>
            </div>

            <!-- ALL PLAN ID DIRECTORY -->
            <div class="generator-box p-5 space-y-3.5 border border-cyan-500/30">
                <div class="pb-2 border-b border-[#251346] flex flex-col sm:flex-row justify-between sm:items-center gap-2">
                    <div>
                        <h3 class="text-sm font-black text-white flex items-center gap-2">
                            <i class="fa-solid fa-layer-group text-cyan-400"></i> All Available Plan IDs Directory
                        </h3>
                        <p class="text-[11px] text-gray-400 mt-0.5">Connect your Telegram bot or website directly by using the specific <b>Plan ID</b></p>
                    </div>
                    <span class="text-[10px] font-mono font-bold text-cyan-300 bg-cyan-500/10 px-2.5 py-1 rounded-xl border border-cyan-500/20 w-fit">
                        Total Plans: <?= count($apiPlanList) ?>
                    </span>
                </div>

                <div class="relative">
                    <input type="text" id="apiProductFilterInput" oninput="filterApiPlansTable(this.value)" placeholder="Search by Plan ID, Product Name, or Validity..." class="w-full bg-[#080312] border border-[#251346] text-xs text-white rounded-xl p-3 pl-9 outline-none focus:border-cyan-400 font-mono">
                    <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-gray-500 text-xs"></i>
                </div>

                <div class="space-y-2.5 max-h-80 overflow-y-auto pr-1" id="apiPlanCatalogContainer">
                    <?php if (empty($apiPlanList)): ?>
                        <div class="p-4 text-center text-gray-500 text-xs">No active plans found in database.</div>
                    <?php else: ?>
                        <?php foreach ($apiPlanList as $pl): ?>
                            <div class="api-plan-item bg-[#080312] border border-[#251346] hover:border-cyan-500/40 p-3.5 rounded-2xl flex flex-col sm:flex-row sm:items-center justify-between gap-3 transition">
                                <div class="space-y-1.5">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <span class="text-xs font-mono font-black text-emerald-400 bg-emerald-500/15 px-2.5 py-0.5 rounded-lg border border-emerald-500/30">
                                            Plan ID: #<?= $pl['plan_id'] ?>
                                        </span>
                                        <h4 class="font-extrabold text-white text-sm"><?= htmlspecialchars($pl['product_name']) ?> — <span class="text-cyan-300"><?= htmlspecialchars($pl['validity']) ?></span></h4>
                                    </div>
                                    
                                    <div class="text-[10px] text-gray-400 flex items-center gap-2 flex-wrap font-mono">
                                        <span class="text-gray-500 font-bold uppercase">Rate:</span>
                                        <span class="px-2 py-0.5 rounded bg-[#170e30] border border-[#2c1b52] text-white font-bold">
                                            $<?= number_format($pl['price'], 2) ?> USD
                                        </span>
                                        <?php if ($pl['discount_percent'] > 0): ?>
                                            <span class="text-emerald-400 font-bold">[<?= $pl['discount_percent'] ?>% DISCOUNT APPLIED]</span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="flex items-center space-x-2 pt-2 sm:pt-0 border-t sm:border-t-0 border-[#1a0e33] justify-end">
                                    <button type="button" onclick="copyToClipboard('<?= $pl['plan_id'] ?>', 'Plan ID #<?= $pl['plan_id'] ?>')" class="px-3 py-1.5 rounded-xl bg-gradient-to-r from-emerald-500/20 to-teal-500/20 hover:from-emerald-500/30 hover:to-teal-500/30 border border-emerald-500/40 text-emerald-300 text-xs font-bold flex items-center gap-1.5 transition">
                                        <i class="fa-regular fa-copy"></i> Copy Plan ID
                                    </button>
                                    <button type="button" onclick="copyToClipboard('<?= SITE_URL ?>api.php?action=generate_key&plan_id=<?= $pl['plan_id'] ?>&quantity=1&api_key=<?= $user['api_key'] ?>', 'Order URL for Plan ID #<?= $pl['plan_id'] ?>')" class="px-3 py-1.5 rounded-xl bg-cyan-500/15 hover:bg-cyan-500/25 border border-cyan-500/30 text-cyan-300 text-xs font-bold flex items-center gap-1.5 transition">
                                        <i class="fa-solid fa-link"></i> Order API URL
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- OFFICIAL 7 RESET APIS DIRECTORY (6 LICENSEMANAGER APIS + ANGRY MOD PINXT) -->
            <div class="generator-box p-5 space-y-3.5 border border-purple-500/40">
                <div class="pb-2 border-b border-[#251346] flex flex-col sm:flex-row justify-between sm:items-center gap-2">
                    <div>
                        <h3 class="text-sm font-black text-white flex items-center gap-2">
                            <i class="fa-solid fa-plug text-purple-400"></i> Supported 7 Reset APIs (HWID Key Reset)
                        </h3>
                        <p class="text-[11px] text-gray-400 mt-0.5">Use the exact <b>api_id</b> parameter when resetting keys via Telegram Bot or external store</p>
                    </div>
                    <span class="text-[10px] font-mono font-bold text-purple-300 bg-purple-500/10 px-2.5 py-1 rounded-xl border border-purple-500/30 w-fit">
                        7 Endpoints Active
                    </span>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5 text-xs font-mono">
                    <?php foreach ($permittedResetApis as $rId => $rData): ?>
                        <div class="bg-[#080312] border border-[#251346] hover:border-purple-500/40 p-3 rounded-2xl flex items-center justify-between transition">
                            <div>
                                <div class="flex items-center space-x-2">
                                    <span class="px-2 py-0.5 rounded-md <?= $rId === 99 ? 'bg-cyan-500/20 text-cyan-300 border border-cyan-500/30' : 'bg-purple-500/20 text-purple-300 border border-purple-500/30' ?> font-black text-xs">
                                        api_id=<?= $rId ?>
                                    </span>
                                    <strong class="text-white text-xs font-sans"><?= htmlspecialchars($rData['name']) ?></strong>
                                </div>
                                <span class="text-[10px] text-gray-400 mt-1 block">
                                    Slug: <span class="text-cyan-300"><?= $rData['slug'] ?></span> • Limit: <?= $rData['limit'] ?>
                                </span>
                            </div>
                            <?php if ($rId === 99): ?>
                                <button type="button" onclick="copyToClipboard('<?= SITE_URL ?>api.php?action=reset_key&api_id=99&key=YOURKEY&api_key=<?= $user['api_key'] ?>', 'Angry Mod Reset URL')" class="px-2.5 py-1.5 rounded-xl bg-cyan-500/20 text-cyan-300 hover:bg-cyan-500/30 text-[11px] font-bold border border-cyan-500/40 flex items-center gap-1">
                                    <i class="fa-solid fa-code text-[10px]"></i> URL
                                </button>
                            <?php else: ?>
                                <button type="button" onclick="copyToClipboard('<?= SITE_URL ?>api.php?action=reset_key&api_id=<?= $rId ?>&key=USERKEY-XXXX-XXXX&api_key=<?= $user['api_key'] ?>', 'Reset URL for <?= $rData['name'] ?>')" class="px-2.5 py-1.5 rounded-xl bg-purple-500/20 text-purple-300 hover:bg-purple-500/30 text-[11px] font-bold border border-purple-500/40 flex items-center gap-1">
                                    <i class="fa-solid fa-code text-[10px]"></i> URL
                                </button>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Live API Endpoints -->
            <div class="generator-box p-5 space-y-3">
                <div class="pb-2 border-b border-[#251346]">
                    <h3 class="text-sm font-black text-white flex items-center gap-2">
                        <i class="fa-solid fa-link text-cyan-400"></i> Live API Endpoints
                    </h3>
                    <p class="text-[11px] text-gray-400 mt-0.5">Direct HTTPS URLs for Telegram bot, Discord bot and web application communication</p>
                </div>

                <div class="space-y-3 text-xs">
                    <div class="bg-[#080312] p-2.5 rounded-xl border border-[#251346] space-y-1">
                        <div class="flex justify-between items-center">
                            <span class="font-bold text-gray-300">1. Check Balance Endpoint (GET):</span>
                            <button onclick="copyToClipboard('<?= SITE_URL ?>api.php?action=balance&api_key=<?= $user['api_key'] ?>', 'Endpoint')" class="text-cyan-400 hover:text-cyan-300 text-[11px] font-bold"><i class="fa-regular fa-copy mr-1"></i>Copy</button>
                        </div>
                        <code class="block font-mono text-cyan-300 text-[11px] break-all"><?= SITE_URL ?>api.php?action=balance&api_key=<?= $user['api_key'] ?></code>
                    </div>

                    <div class="bg-[#080312] p-2.5 rounded-xl border border-[#251346] space-y-1">
                        <div class="flex justify-between items-center">
                            <span class="font-bold text-gray-300">2. Generate License Key by Plan ID (POST / GET):</span>
                            <button onclick="copyToClipboard('<?= SITE_URL ?>api.php?action=generate_key&plan_id=1&quantity=1&api_key=<?= $user['api_key'] ?>', 'Endpoint')" class="text-emerald-400 hover:text-emerald-300 text-[11px] font-bold"><i class="fa-regular fa-copy mr-1"></i>Copy</button>
                        </div>
                        <code class="block font-mono text-emerald-300 text-[11px] break-all"><?= SITE_URL ?>api.php?action=generate_key&plan_id=1&quantity=1&api_key=<?= $user['api_key'] ?></code>
                    </div>

                    <div class="bg-[#080312] p-2.5 rounded-xl border border-[#251346] space-y-1">
                        <div class="flex justify-between items-center">
                            <span class="font-bold text-gray-300">3. Recent Key Order History (GET):</span>
                            <button onclick="copyToClipboard('<?= SITE_URL ?>api.php?action=key_history&limit=10&api_key=<?= $user['api_key'] ?>', 'Endpoint')" class="text-cyan-400 hover:text-cyan-300 text-[11px] font-bold"><i class="fa-regular fa-copy mr-1"></i>Copy</button>
                        </div>
                        <code class="block font-mono text-cyan-300 text-[11px] break-all"><?= SITE_URL ?>api.php?action=key_history&limit=10&api_key=<?= $user['api_key'] ?></code>
                    </div>

                    <div class="bg-[#080312] p-2.5 rounded-xl border border-[#251346] space-y-1">
                        <div class="flex justify-between items-center">
                            <span class="font-bold text-gray-300">4. Reset License HWID (api_id: 2,3,4,9,11,12 or Angry Mod #99 — <i>api_id optional, auto-detected from key</i>):</span>
                            <button onclick="copyToClipboard('<?= SITE_URL ?>api.php?action=reset_key&api_id=2&key=USERKEY-XXXX-XXXX&api_key=<?= $user['api_key'] ?>', 'Endpoint')" class="text-fuchsia-400 hover:text-fuchsia-300 text-[11px] font-bold"><i class="fa-regular fa-copy mr-1"></i>Copy</button>
                        </div>
                        <code class="block font-mono text-fuchsia-300 text-[11px] break-all"><?= SITE_URL ?>api.php?action=reset_key&api_id=2&key=USERKEY-XXXX-XXXX&api_key=<?= $user['api_key'] ?></code>
                        <p class="text-[10px] text-gray-400 leading-relaxed pt-1">
                            Header diye pathale: <code class="text-fuchsia-300 break-all">curl -H "X-API-Token: <?= $user['api_key'] ?>" "<?= SITE_URL ?>api.php?action=reset_key&amp;api_id=2&amp;key=USERKEY-XXXX-XXXX"</code><br>
                            Only <b>key</b> pathale (api_id chhara) system nijei product dekhe reset API bepe nibe. Response: <code class="text-fuchsia-300">{"status":"success","success":true,"message":"...","api_id":2,"api_name":"DRIPCLIENT","key":"..."}</code><br>
                            Public reset page (customer-der jonno): <a href="<?= SITE_URL ?>reset-key.php" target="_blank" class="text-cyan-300 underline"><?= SITE_URL ?>reset-key.php</a>
                        </p>
                    </div>
                </div>
            </div>

            <!-- Bot & Website Integration Code -->
            <div class="generator-box p-5 space-y-3">
                <div class="pb-2 border-b border-[#251346]">
                    <h3 class="text-sm font-black text-white flex items-center gap-2">
                        <i class="fa-solid fa-code text-cyan-400"></i> Bot & Website Integration Code
                    </h3>
                    <p class="text-[11px] text-gray-400 mt-0.5">Ready-to-use production code snippets for Telegram Bots & external stores</p>
                </div>

                <div class="space-y-2">
                    <div class="flex space-x-1 bg-[#080312] p-1 rounded-xl border border-[#251346] text-xs">
                        <button type="button" onclick="switchCodeTab('php')" id="tab-code-php" class="code-tab-btn active px-3 py-1.5 rounded-lg transition">PHP (cURL)</button>
                        <button type="button" onclick="switchCodeTab('python')" id="tab-code-python" class="code-tab-btn px-3 py-1.5 text-gray-400 rounded-lg transition">Python (Bot)</button>
                        <button type="button" onclick="switchCodeTab('java')" id="tab-code-java" class="code-tab-btn px-3 py-1.5 text-gray-400 rounded-lg transition">Java</button>
                        <button type="button" onclick="switchCodeTab('curl')" id="tab-code-curl" class="code-tab-btn px-3 py-1.5 text-gray-400 rounded-lg transition">cURL (CLI)</button>
                    </div>

                    <div id="code-snippet-php" class="code-block space-y-1">
                        <pre class="bg-[#080312] border border-[#251346] p-3 rounded-xl text-[11px] font-mono text-cyan-300 overflow-x-auto"><code>&lt;?php
$apiKey = "<?= htmlspecialchars($user['api_key']) ?>";
$url = "<?= SITE_URL ?>api.php?action=generate_key";

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $url,
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POSTFIELDS     => http_build_query(['plan_id' => 1, 'quantity' => 1]),
    CURLOPT_HTTPHEADER     => ['X-API-Token: ' . $apiKey]
]);
$res = curl_exec($ch);
curl_close($ch);
print_r(json_decode($res, true));</code></pre>
                    </div>

                    <div id="code-snippet-python" class="code-block hidden space-y-1">
                        <pre class="bg-[#080312] border border-[#251346] p-3 rounded-xl text-[11px] font-mono text-emerald-300 overflow-x-auto"><code>import requests

API_TOKEN = "<?= htmlspecialchars($user['api_key']) ?>"
ENDPOINT = "<?= SITE_URL ?>api.php"

def buy_license(plan_id, qty=1):
    headers = {"X-API-Token": API_TOKEN}
    payload = {"action": "generate_key", "plan_id": plan_id, "quantity": qty}
    r = requests.post(ENDPOINT, data=payload, headers=headers)
    return r.json()</code></pre>
                    </div>

                    <div id="code-snippet-java" class="code-block hidden space-y-1">
                        <pre class="bg-[#080312] border border-[#251346] p-3 rounded-xl text-[11px] font-mono text-amber-300 overflow-x-auto"><code>import java.net.URI;
import java.net.http.*;

public class LicenseApiBot {
    public static void main(String[] args) throws Exception {
        String token = "<?= htmlspecialchars($user['api_key']) ?>";
        String url = "<?= SITE_URL ?>api.php?action=generate_key&plan_id=1&quantity=1";

        HttpClient client = HttpClient.newHttpClient();
        HttpRequest req = HttpRequest.newBuilder()
                .uri(URI.create(url))
                .header("X-API-Token", token)
                .POST(HttpRequest.BodyPublishers.noBody())
                .build();

        HttpResponse&lt;String&gt; res = client.send(req, HttpResponse.BodyHandlers.ofString());
        System.out.println(res.body());
    }
}</code></pre>
                    </div>

                    <div id="code-snippet-curl" class="code-block hidden space-y-1">
                        <pre class="bg-[#080312] border border-[#251346] p-3 rounded-xl text-[11px] font-mono text-fuchsia-300 overflow-x-auto"><code>curl -X POST -H "X-API-Token: <?= htmlspecialchars($user['api_key']) ?>" \
  -d "plan_id=1&quantity=1" \
  <?= SITE_URL ?>api.php?action=generate_key</code></pre>
                    </div>
                </div>
            </div>

        </section>
        <?php endif; ?>

        <!-- 6. DASHBOARD OVERVIEW SECTION -->
        <section id="page-dashboard" class="page-section hidden w-full space-y-3.5">
            <div class="generator-box p-4 rounded-2xl flex items-center justify-between">
                <div>
                    <span class="text-[10px] text-gray-400 font-bold uppercase tracking-wider block">Terminal Status</span>
                    <h3 class="text-sm font-bold text-white flex items-center gap-1.5 mt-0.5">
                        <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                        <span>Session Online</span>
                    </h3>
                </div>
                <div class="text-right">
                    <span class="text-[10px] text-gray-400 font-bold uppercase block">FX Exchange Rate</span>
                    <span class="text-xs font-mono font-bold text-cyan-300">1 USD = <?= $effectiveBdtRate ?> BDT</span>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-2.5">
                <?php if ($canAddFund === 1): ?>
                    <button onclick="goToPage('addfund')" class="glass-card py-3 rounded-2xl text-xs font-bold text-fuchsia-300 border border-fuchsia-500/30 flex items-center justify-center gap-2">
                        <i class="fa-solid fa-wallet text-fuchsia-400"></i> Deposit Balance
                    </button>
                <?php else: ?>
                    <button onclick="goToPage('history')" class="glass-card py-3 rounded-2xl text-xs font-bold text-cyan-300 border border-cyan-500/30 flex items-center justify-center gap-2">
                        <i class="fa-solid fa-clock-rotate-left text-cyan-400"></i> Key History
                    </button>
                <?php endif; ?>

                <button onclick="goToPage('generate')" class="glass-card py-3 rounded-2xl text-xs font-bold text-fuchsia-300 border border-fuchsia-500/30 flex items-center justify-center gap-2">
                    <i class="fa-solid fa-bolt text-fuchsia-400"></i> Generate Key
                </button>
            </div>

            <div class="grid grid-cols-2 gap-2.5">
                <div class="glass-card rounded-2xl p-4 flex flex-col justify-between h-28">
                    <span class="text-[10px] text-gray-400 font-bold uppercase tracking-wider">WALLET BALANCE</span>
                    <div>
                        <div class="text-2xl font-black text-[#00ff88] font-mono leading-tight">$<?= number_format((float)$user['balance'], 2) ?></div>
                        <span class="text-[10px] text-gray-500 font-mono">≈ ৳ <?= number_format((float)$user['balance'] * $effectiveBdtRate, 2) ?> BDT</span>
                    </div>
                </div>

                <div class="glass-card rounded-2xl p-4 flex flex-col justify-between h-28">
                    <span class="text-[10px] text-gray-400 font-bold uppercase tracking-wider">LICENSES ISSUED</span>
                    <div>
                        <div class="text-2xl font-black text-white font-mono leading-tight"><?= $totalKeysCount ?></div>
                        <span class="text-[10px] text-cyan-400 font-mono">Active Records</span>
                    </div>
                </div>
            </div>

            <div class="glass-card rounded-2xl p-4 space-y-2.5">
                <span class="text-[10px] font-bold text-gray-400 uppercase tracking-wider block">Terminal Shortcuts</span>
                <div class="grid grid-cols-3 gap-2 text-center text-xs">
                    <button onclick="goToPage('resetkey')" class="p-2.5 rounded-xl bg-[#080312] border border-[#251346] hover:border-cyan-400/50 transition">
                        <i class="fa-solid fa-arrows-rotate text-cyan-400 block mb-1 text-base"></i>
                        <span class="text-[10px] text-gray-300 font-semibold">Reset HWID</span>
                    </button>
                    <button onclick="goToPage('history')" class="p-2.5 rounded-xl bg-[#080312] border border-[#251346] hover:border-fuchsia-400/50 transition">
                        <i class="fa-solid fa-clock-rotate-left text-fuchsia-400 block mb-1 text-base"></i>
                        <span class="text-[10px] text-gray-300 font-semibold">Key Logs</span>
                    </button>
                    
                    <?php if ($canAddFund === 1): ?>
                    <button onclick="goToPage('transactions')" class="p-2.5 rounded-xl bg-[#080312] border border-[#251346] hover:border-emerald-400/50 transition">
                        <i class="fa-solid fa-money-bill-transfer text-emerald-400 block mb-1 text-base"></i>
                        <span class="text-[10px] text-gray-300 font-semibold">Deposits</span>
                    </button>
                    <?php else: ?>
                    <a href="<?= htmlspecialchars($effectiveTutorialLink) ?>" target="_blank" class="p-2.5 rounded-xl bg-[#080312] border border-[#251346] hover:border-emerald-400/50 transition flex flex-col items-center justify-center">
                        <i class="fa-solid fa-graduation-cap text-emerald-400 block mb-1 text-base"></i>
                        <span class="text-[10px] text-gray-300 font-semibold">Tutorial</span>
                    </a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="glass-card rounded-2xl p-4 space-y-2">
                <div class="flex justify-between items-center pb-1 border-b border-[#21143f]">
                    <span class="text-xs font-bold text-white flex items-center gap-1.5">
                        <i class="fa-solid fa-history text-fuchsia-400"></i> Recent Licenses
                    </span>
                    <button onclick="goToPage('history')" class="text-[10px] text-fuchsia-400 font-bold hover:underline">View All</button>
                </div>
                <div class="space-y-1.5">
                    <?php if (empty($keys)): ?>
                        <p class="text-xs text-gray-500 text-center py-3">No licenses generated yet.</p>
                    <?php else: ?>
                        <?php foreach(array_slice($keys, 0, 3) as $k): ?>
                            <div class="p-2 bg-[#080312] rounded-xl flex justify-between items-center text-xs">
                                <div>
                                    <div class="flex items-center space-x-1.5">
                                        <span class="font-mono text-fuchsia-300 font-bold block text-[11px]"><?= $k['license_key'] ?></span>
                                        <?php if (strtoupper($k['source'] ?? 'PANEL') === 'API'): ?>
                                            <span class="text-[8px] font-mono font-bold px-1.5 py-0.5 rounded bg-cyan-500/20 text-cyan-300 border border-cyan-500/30">API</span>
                                        <?php else: ?>
                                            <span class="text-[8px] font-mono font-bold px-1.5 py-0.5 rounded bg-purple-500/20 text-purple-300 border border-purple-500/30">PANEL</span>
                                        <?php endif; ?>
                                    </div>
                                    <span class="text-[10px] text-gray-500 mt-0.5 block"><?= htmlspecialchars($k['validity'] ?? 'Key') ?></span>
                                </div>
                                <button onclick="copyToClipboard('<?= $k['license_key'] ?>')" class="text-gray-400 hover:text-white px-2"><i class="fa-regular fa-copy"></i></button>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

        </section>

        <!-- 7. KEY HISTORY -->
        <section id="page-history" class="page-section hidden w-full space-y-3">
            <div class="glass-card rounded-2xl p-4">
                <h3 class="text-sm font-bold text-white mb-3 flex items-center gap-2">
                    <i class="fa-solid fa-clock-rotate-left text-fuchsia-400"></i> License History
                </h3>
                <div class="space-y-2.5">
                    <?php if (empty($keys)): ?>
                        <p class="text-xs text-gray-500 text-center py-6">No keys generated yet.</p>
                    <?php else: ?>
                        <?php foreach($keys as $k): ?>
                            <div class="bg-[#080312] p-3.5 rounded-2xl border border-[#251346] space-y-2 text-xs">
                                <div class="flex justify-between items-start">
                                    <span class="font-mono text-fuchsia-300 font-bold block tracking-wider text-xs"><?= $k['license_key'] ?></span>
                                    <div class="flex items-center space-x-1.5">
                                        <?php if (strtoupper($k['source'] ?? 'PANEL') === 'API'): ?>
                                            <span class="text-[9px] font-mono font-bold px-2 py-0.5 rounded bg-cyan-500/20 text-cyan-300 border border-cyan-500/30">API</span>
                                        <?php else: ?>
                                            <span class="text-[9px] font-mono font-bold px-2 py-0.5 rounded bg-purple-500/20 text-purple-300 border border-purple-500/30">PANEL</span>
                                        <?php endif; ?>
                                        <span class="text-[9px] font-mono text-emerald-400 bg-emerald-500/15 px-2 py-0.5 rounded font-bold">ACTIVE</span>
                                    </div>
                                </div>
                                <div class="flex justify-between items-center pt-2 border-t border-[#1a0e33]">
                                    <span class="text-[10px] text-gray-400"><?= htmlspecialchars($k['validity'] ?? 'Active') ?></span>
                                    <div class="flex items-center space-x-1.5">
                                        <button onclick="copyToClipboard('<?= $k['license_key'] ?>')" class="px-2.5 py-1 rounded-lg bg-[#180e30] border border-[#2e1850] text-gray-300 hover:text-white text-[10px] font-bold">Copy</button>
                                        <button onclick="setResetTargetKey('<?= $k['license_key'] ?>', <?= (int)($k['reset_api_id'] ?? 0) ?>)" class="px-2.5 py-1 rounded-lg bg-cyan-500/15 border border-cyan-500/30 text-cyan-300 text-[10px] font-bold">Reset HWID</button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <!-- 8. TRANSACTIONS -->
        <?php if ($canAddFund === 1): ?>
        <section id="page-transactions" class="page-section hidden w-full space-y-3">
            <div class="glass-card rounded-2xl p-4">
                <h3 class="text-sm font-bold text-white mb-3 flex items-center gap-2">
                    <i class="fa-solid fa-money-bill-transfer text-fuchsia-400"></i> Deposit Ledger
                </h3>
                <div class="space-y-2">
                    <?php if (empty($deposits)): ?>
                        <p class="text-xs text-gray-500 text-center py-6">No deposits recorded.</p>
                    <?php else: ?>
                        <?php foreach($deposits as $d): ?>
                            <div class="bg-[#080312] p-3 rounded-xl border border-[#251346] flex justify-between items-center text-xs">
                                <div>
                                    <span class="font-bold text-white block"><?= $d['method'] ?> • $<?= number_format((float)$d['amount'], 2) ?> USD</span>
                                    <span class="text-[10px] text-gray-400 font-mono">Trx: <?= $d['trx_id'] ?></span>
                                </div>
                                <span class="text-[10px] font-bold px-2 py-0.5 rounded-lg <?= $d['status'] === 'approved' ? 'bg-emerald-500/20 text-emerald-400' : ($d['status'] === 'pending' ? 'bg-amber-500/20 text-amber-400' : 'bg-red-500/20 text-red-400') ?>">
                                    <?= strtoupper($d['status']) ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </section>
        <?php endif; ?>

    </main>

    <!-- Sub Account Profile & Control Modal -->
    <div id="manageSubModal" class="fixed inset-0 bg-black/85 backdrop-blur-md z-50 hidden flex items-center justify-center p-4">
        <div class="generator-box rounded-[26px] w-full max-w-md p-6 space-y-4 border border-fuchsia-500/50 max-h-[90vh] overflow-y-auto">
            <div class="flex justify-between items-center pb-2 border-b border-[#251346]">
                <div class="flex items-center space-x-2.5">
                    <div class="w-9 h-9 rounded-xl bg-fuchsia-500/20 text-fuchsia-400 flex items-center justify-center font-bold">
                        <i class="fa-solid fa-user-gear"></i>
                    </div>
                    <div>
                        <h3 id="modalSubUsername" class="text-sm font-bold text-white leading-none">Username</h3>
                        <span id="modalSubInfo" class="text-[10px] text-gray-400 font-mono mt-0.5 block">Sub Account Control</span>
                    </div>
                </div>
                <button type="button" onclick="closeManageSubModal()" class="text-gray-400 hover:text-white p-1"><i class="fa-solid fa-xmark text-base"></i></button>
            </div>

            <!-- Transfer Funds -->
            <form method="POST" class="bg-[#080c18] p-3.5 rounded-2xl border border-[#251346] space-y-2 text-xs">
                <input type="hidden" name="action" value="transfer_balance">
                <input type="hidden" id="modalTransferTargetId" name="target_user_id">
                <label class="text-[11px] font-bold text-emerald-400 block uppercase">Send / Add Wallet Funds (+):</label>
                <div class="flex gap-2">
                    <input type="number" step="0.5" name="transfer_amount" placeholder="Amount ($)" required class="flex-grow bg-[#120b24] border border-[#2e1853] p-2 rounded-xl text-white font-mono text-xs outline-none">
                    <button type="submit" class="px-3.5 py-2 rounded-xl btn-magenta-glow text-white font-bold text-xs whitespace-nowrap">+ Add Fund</button>
                </div>
            </form>

            <!-- Recall / Deduct Balance -->
            <form method="POST" class="bg-[#080c18] p-3.5 rounded-2xl border border-red-500/30 space-y-2 text-xs">
                <input type="hidden" name="action" value="deduct_granted_balance">
                <input type="hidden" id="modalDeductTargetId" name="target_user_id">
                <div class="flex justify-between items-center">
                    <label class="text-[11px] font-bold text-red-400 block uppercase">Recall / Reduce Balance (-):</label>
                    <span id="modalGrantedBalText" class="text-[10px] font-mono text-gray-400 font-bold">Max cut: $0.00</span>
                </div>
                <div class="flex gap-2">
                    <input type="number" step="0.5" name="deduct_amount" placeholder="Amount to recall ($)" required class="flex-grow bg-[#120b24] border border-red-500/30 p-2 rounded-xl text-white font-mono text-xs outline-none">
                    <button type="submit" class="px-3.5 py-2 rounded-xl bg-red-600 hover:bg-red-500 text-white font-bold text-xs whitespace-nowrap">- Deduct</button>
                </div>
                <span class="text-[9px] text-gray-400 italic block leading-tight">Note: You can recall funds granted from your main balance.</span>
            </form>

            <!-- Permissions & Settings Form -->
            <form method="POST" class="space-y-3 text-xs">
                <input type="hidden" name="action" value="manage_sub_reseller">
                <input type="hidden" id="modalManageTargetId" name="target_user_id">

                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="text-[10px] text-gray-400 block mb-1 font-semibold">Access Level</label>
                        <select id="modalSubRole" name="role" class="w-full bg-[#080312] border border-[#251346] p-2.5 rounded-xl text-white outline-none">
                            <option value="reseller">Reseller Access</option>
                            <option value="user">Client (User)</option>
                        </select>
                        <span id="roleLockedNotice" class="text-[9px] text-amber-300 font-mono hidden mt-0.5 block">Reseller level is permanent</span>
                    </div>
                    <div>
                        <label class="text-[10px] text-gray-400 block mb-1 font-semibold">Status</label>
                        <select id="modalSubStatus" name="status" class="w-full bg-[#080312] border border-[#251346] p-2.5 rounded-xl text-white outline-none">
                            <option value="active">Active</option>
                            <option value="banned">Banned</option>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="text-[10px] text-gray-400 block mb-1 font-semibold">Custom Exchange Rate (Min: <?= $effectiveBdtRate ?> BDT)</label>
                        <input type="number" step="0.5" min="<?= $effectiveBdtRate ?>" id="modalSubRate" name="custom_bdt_rate" required placeholder="Min <?= $effectiveBdtRate ?>" class="w-full bg-[#080312] border border-[#251346] p-2.5 rounded-xl text-emerald-300 font-mono outline-none">
                    </div>
                    <div>
                        <label class="text-[10px] text-gray-400 block mb-1 font-semibold">Reset Password</label>
                        <input type="text" name="new_password" placeholder="New password" class="w-full bg-[#080312] border border-[#251346] p-2.5 rounded-xl text-white outline-none">
                    </div>
                </div>

                <div class="flex items-center justify-between p-2.5 bg-[#080312] rounded-xl border border-[#251346] text-gray-300">
                    <label class="flex items-center space-x-2 cursor-pointer">
                        <input type="checkbox" id="modalSubApi" name="api_enabled" value="1" class="rounded text-fuchsia-500">
                        <span>API Access</span>
                    </label>
                    <label class="flex items-center space-x-2 cursor-pointer">
                        <input type="checkbox" id="modalSubAddFund" name="can_add_fund" value="1" class="rounded text-fuchsia-500">
                        <span>Deposit Balance Access</span>
                    </label>
                </div>

                <div>
                    <label class="text-[10px] text-gray-400 block mb-1 font-semibold">Allowed Products:</label>
                    <div id="subAllowedProdsContainer" class="grid grid-cols-2 gap-1.5 text-[11px] text-gray-300 max-h-28 overflow-y-auto bg-[#080312] p-2.5 rounded-xl border border-[#251346]">
                        <?php foreach ($userAllowedProducts as $pTitle): ?>
                            <label class="flex items-center space-x-1.5 truncate">
                                <input type="checkbox" name="allowed_products[]" value="<?= htmlspecialchars($pTitle) ?>" class="sub-product-checkbox rounded text-fuchsia-500">
                                <span class="truncate"><?= htmlspecialchars($pTitle) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <button type="submit" class="w-full py-3 rounded-xl bg-gradient-to-r from-fuchsia-600 to-purple-600 text-white font-bold text-xs uppercase">
                    Save Profile Settings
                </button>
            </form>

            <form method="POST" onsubmit="return confirm('Delete this sub account permanently? Any remaining wallet balance will be refunded to your wallet.');" class="pt-2 border-t border-[#251346]">
                <input type="hidden" name="action" value="delete_sub_reseller">
                <input type="hidden" id="modalDeleteTargetId" name="target_user_id">
                <button type="submit" class="w-full py-2.5 rounded-xl bg-red-500/15 border border-red-500/30 text-red-400 hover:bg-red-500/25 text-xs font-bold transition">
                    Delete Sub Account (With Refund)
                </button>
            </form>
        </div>
    </div>

    <!-- Modal: Cashout -->
    <div id="withdrawModal" class="fixed inset-0 bg-black/85 backdrop-blur-md z-50 hidden flex items-center justify-center p-4">
        <div class="generator-box rounded-[26px] w-full max-w-sm p-6 space-y-4 border border-fuchsia-500/50">
            <h3 class="text-sm font-bold text-white flex items-center gap-2">
                <i class="fa-solid fa-money-bill-wave text-pink-400"></i> Cashout Commission
            </h3>
            <form method="POST" class="space-y-3 text-xs">
                <input type="hidden" name="action" value="withdraw_profit">
                <div>
                    <label class="text-[11px] text-gray-400 block mb-1">Cashout Amount ($ USD)</label>
                    <input type="number" step="0.5" name="withdraw_amount" max="<?= (float)($user['profit_balance'] ?? 0) ?>" required class="w-full bg-[#080312] border border-[#251346] p-3 rounded-xl text-white font-mono outline-none">
                </div>
                <div>
                    <label class="text-[11px] text-gray-400 block mb-1">Account Number</label>
                    <input type="text" name="bkash_number" required placeholder="017XXXXXXXX" class="w-full bg-[#080312] border border-[#251346] p-3 rounded-xl text-white font-mono outline-none">
                </div>
                <button type="submit" class="w-full py-3 rounded-xl btn-magenta-glow text-white font-bold text-xs uppercase">
                    Submit Cashout Request
                </button>
                <button type="button" onclick="document.getElementById('withdrawModal').classList.add('hidden')" class="w-full py-2 text-center text-gray-400 hover:text-white text-xs">
                    Cancel
                </button>
            </form>
        </div>
    </div>

    <footer class="w-full text-center text-[11px] text-gray-500 py-4 font-medium border-t border-[#180e30] relative z-10">
        &lt;/&gt; &copy; <?= date('Y') ?> <?= SITE_NAME ?> — Elite Command Console
    </footer>

    <!-- Client-Side Engine -->
    <script>
        document.addEventListener('contextmenu', e => e.preventDefault());
        document.addEventListener('selectstart', e => {
            if (e.target.tagName !== 'INPUT' && e.target.tagName !== 'TEXTAREA') {
                e.preventDefault();
            }
        });
        document.addEventListener('copy', e => {
            if (e.target.tagName !== 'INPUT' && e.target.tagName !== 'TEXTAREA') {
                e.preventDefault();
            }
        });

        const catalogData = <?= json_encode($catalogStructure) ?>;
        const currentBdtRate = <?= $effectiveBdtRate ?>;
        const currentMarketRate = <?= $binanceMarketRate ?>;
        const allUserResetKeys = <?= json_encode($userAllResetKeys) ?>;
        const permittedResetApis = <?= json_encode($permittedResetApis) ?>;
        
        const userMinDepositUSD = <?= (float)$userMinDepositUSD ?>;
        const userMaxDepositUSD = <?= (float)$userMaxDepositUSD ?>;

        let selectedCurrency = 'BDT';
        let latestKeys = [];

        let audioCtx = null;
        function playCyberSound() {
            try {
                if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)();
                if (audioCtx.state === 'suspended') audioCtx.resume();
                const osc = audioCtx.createOscillator();
                const gain = audioCtx.createGain();
                osc.type = 'sine';
                osc.frequency.setValueAtTime(900, audioCtx.currentTime);
                osc.frequency.exponentialRampToValueAtTime(450, audioCtx.currentTime + 0.04);
                gain.gain.setValueAtTime(0.08, audioCtx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + 0.04);
                osc.connect(gain);
                gain.connect(audioCtx.destination);
                osc.start();
                osc.stop(audioCtx.currentTime + 0.04);
            } catch (e) {}
        }

        document.addEventListener('click', (e) => {
            if (e.target.closest('button') || e.target.closest('a') || e.target.closest('.deposit-tab')) {
                playCyberSound();
            }
        });

        function openNoticeModal() {
            document.getElementById('noticeModal').classList.remove('hidden');
        }

        function closeNoticeModal() {
            document.getElementById('noticeModal').classList.add('hidden');
        }

        function toggleApiKeyVisibility() {
            const input = document.getElementById('apiVal');
            const icon = document.getElementById('apiEyeIcon');
            if (input.type === 'password') {
                input.type = 'text';
                icon.className = 'fa-solid fa-eye-slash';
            } else {
                input.type = 'password';
                icon.className = 'fa-solid fa-eye';
            }
        }

        function onProductTypeChange() {
            const productTypeSelect = document.getElementById('genProductType');
            const validitySelect = document.getElementById('genValidity');
            if (!productTypeSelect || !validitySelect) return;

            const productType = productTypeSelect.value;
            validitySelect.innerHTML = '';

            const variants = catalogData[productType] || [];
            if (variants.length > 0) {
                variants.forEach(v => {
                    const opt = document.createElement('option');
                    opt.value = v.plan_id;
                    opt.setAttribute('data-price', v.price);
                    opt.setAttribute('data-regular-price', v.regular_price || v.price);
                    opt.setAttribute('data-discount', v.discount_percent || 0);

                    let discountTag = '';
                    if (v.discount_percent && v.discount_percent > 0) {
                        discountTag = ` (${v.discount_percent}% OFF)`;
                    }

                    opt.innerText = `${v.validity} - $${parseFloat(v.price).toFixed(2)}${discountTag}`;
                    validitySelect.appendChild(opt);
                });
            } else {
                const opt = document.createElement('option');
                opt.value = '';
                opt.innerText = 'No validities available.';
                validitySelect.appendChild(opt);
            }
            calculateTotal();
        }

        function changeQuantity(val) {
            const input = document.getElementById('genQuantity');
            let current = parseInt(input.value) || 1;
            current = Math.max(1, Math.min(50, current + val));
            input.value = current;
            calculateTotal();
        }

        function calculateTotal() {
            const select = document.getElementById('genValidity');
            const discountNoticeBox = document.getElementById('genDiscountNoticeBox');
            const discountTopTag = document.getElementById('genDiscountTag');
            const regularPriceTxt = document.getElementById('genRegularPriceTxt');
            const dealPriceTxt = document.getElementById('genDealPriceTxt');
            const discountBadge = document.getElementById('genDiscountPercentBadge');

            if (!select || select.options.length === 0 || !select.value) {
                document.getElementById('btnText').innerText = `GENERATE KEYS ($0.00)`;
                if (discountNoticeBox) discountNoticeBox.classList.add('hidden');
                if (discountTopTag) discountTopTag.classList.add('hidden');
                return;
            }

            const selectedOpt = select.options[select.selectedIndex];
            const regularUnitPrice = parseFloat(selectedOpt.getAttribute('data-regular-price')) || 0.00;
            const discountPercent = parseInt(selectedOpt.getAttribute('data-discount')) || 0;
            const qty = parseInt(document.getElementById('genQuantity').value) || 1;

            let dealUnitPrice = regularUnitPrice;
            if (discountPercent > 0) {
                dealUnitPrice = parseFloat(selectedOpt.getAttribute('data-price')) || (regularUnitPrice - (regularUnitPrice * (discountPercent / 100)));
                if (discountNoticeBox) {
                    discountNoticeBox.classList.remove('hidden');
                    regularPriceTxt.innerText = '$' + (regularUnitPrice * qty).toFixed(2);
                    dealPriceTxt.innerText = '$' + (dealUnitPrice * qty).toFixed(2);
                    discountBadge.innerText = discountPercent + '% OFF';
                }
                if (discountTopTag) discountTopTag.classList.remove('hidden');
            } else {
                if (discountNoticeBox) discountNoticeBox.classList.add('hidden');
                if (discountTopTag) discountTopTag.classList.add('hidden');
            }

            document.getElementById('btnText').innerText = `GENERATE KEYS ($${(dealUnitPrice * qty).toFixed(2)})`;
        }

        function filterApiPlansTable(query) {
            const filter = (query || '').toLowerCase().trim();
            const items = document.querySelectorAll('.api-plan-item');
            items.forEach(item => {
                const text = item.textContent.toLowerCase();
                item.style.display = text.includes(filter) ? '' : 'none';
            });
        }

        function promptOrderConfirmation(e) {
            e.preventDefault();
            const planSelect = document.getElementById('genValidity');
            const prodSelect = document.getElementById('genProductType');
            if (!planSelect || !planSelect.value) {
                showToast('Please select a valid product and validity first!', 'error');
                return;
            }

            const selectedOpt = planSelect.options[planSelect.selectedIndex];
            const price = parseFloat(selectedOpt.getAttribute('data-price')) || 0.00;
            const qty = parseInt(document.getElementById('genQuantity').value) || 1;
            const total = (price * qty).toFixed(2);

            document.getElementById('confirmModalProduct').innerText = prodSelect.value;
            document.getElementById('confirmModalValidity').innerText = selectedOpt.text.split(' - ')[0];
            document.getElementById('confirmModalQty').innerText = qty + ' Key(s)';
            document.getElementById('confirmModalTotal').innerText = '$' + total + ' USD';

            document.getElementById('confirmOrderModal').classList.remove('hidden');
        }

        function closeConfirmModal() {
            document.getElementById('confirmOrderModal').classList.add('hidden');
        }

        async function executeKeyPurchase() {
            closeConfirmModal();

            const planSelect = document.getElementById('genValidity');
            const btn = document.getElementById('genSubmitBtn');
            const qtyInput = document.getElementById('genQuantity');

            btn.disabled = true;
            document.getElementById('btnText').innerText = 'PROVISIONING REAL KEYS...';

            const formData = new FormData();
            formData.append('ajax_action', 'live_generate');
            formData.append('plan_id', planSelect.value);
            formData.append('quantity', qtyInput.value);

            try {
                const res = await fetch(window.location.href, { method: 'POST', body: formData });
                const data = await res.json();

                if (data.status === 'success') {
                    document.getElementById('liveHeaderBalance').innerText = '$' + data.remaining_balance;
                    latestKeys = data.keys;

                    data.keys.forEach(k => {
                        allUserResetKeys.unshift({
                            license_key: k,
                            validity: data.product_name,
                            reset_api_id: data.reset_api_id || 0
                        });
                    });

                    document.getElementById('modalProductTitle').innerText = data.product_name;
                    if (data.tutorial_link) {
                        document.getElementById('modalTutorialBtn').href = data.tutorial_link;
                    }
                    
                    const modalKeysContainer = document.getElementById('modalKeysContainer');
                    modalKeysContainer.innerHTML = '';

                    data.keys.forEach(k => {
                        const row = document.createElement('div');
                        row.className = "bg-[#080312] p-2.5 rounded-xl border border-fuchsia-500/30 flex justify-between items-center text-xs";
                        row.innerHTML = `
                            <span class="font-mono text-fuchsia-300 font-bold tracking-wider">${k}</span>
                            <button type="button" onclick="copyToClipboard('${k}')" class="px-2.5 py-1 rounded bg-fuchsia-500/20 text-fuchsia-300 text-[11px] font-bold hover:bg-fuchsia-500/30">Copy</button>
                        `;
                        modalKeysContainer.appendChild(row);
                    });

                    qtyInput.value = 1;
                    calculateTotal();

                    document.getElementById('keySuccessModal').classList.remove('hidden');
                    showToast(data.message, 'success');
                } else {
                    showToast(data.message, 'error');
                }
            } catch (err) {
                showToast('Server connection error!', 'error');
            } finally {
                btn.disabled = false;
                calculateTotal();
            }
        }

        // Live Reset Key UX: Handles 6 LM APIs + Angry Mod (Pinxt) Token display
        function onResetApiSelectionChange() {
            const apiSelect = document.getElementById('resetApiSelect');
            const tokenBox = document.getElementById('angryModTokenBox');
            const badge = document.getElementById('resetApiBadge');
            const val = parseInt(apiSelect.value);

            if (val === 99) {
                tokenBox.classList.remove('hidden');
                badge.innerText = 'Direct API';
            } else {
                tokenBox.classList.add('hidden');
                badge.innerText = '100/day Limit';
            }

            const keyInput = document.getElementById('resetKeySearchInput');
            onResetKeySearchInput(keyInput ? keyInput.value : '');
        }

        function onResetKeySearchInput(query) {
            const resultsBox = document.getElementById('resetSearchResultsBox');
            const apiSelect = document.getElementById('resetApiSelect');
            const targetApiId = parseInt(apiSelect.value) || 0;
            const q = (query || '').trim().toUpperCase();

            resultsBox.innerHTML = '';

            const matches = allUserResetKeys.filter(item => {
                const k = (item.license_key || '').toUpperCase();
                const itemResetId = parseInt(item.reset_api_id) || 0;
                const apiMatch = (targetApiId === 0 || itemResetId === 0 || itemResetId === targetApiId || targetApiId === 99);
                return apiMatch && (q === '' || k.includes(q));
            });

            if (matches.length === 0) {
                if (q !== '') {
                    resultsBox.innerHTML = '<div class="p-3 text-center text-gray-500 text-xs">No matching key found in your purchased keys</div>';
                    resultsBox.classList.remove('hidden');
                } else {
                    resultsBox.classList.add('hidden');
                }
                return;
            }

            matches.slice(0, 15).forEach(item => {
                const row = document.createElement('div');
                row.className = "p-2.5 rounded-xl hover:bg-[#1c0f3c] cursor-pointer transition flex flex-col border border-transparent hover:border-cyan-500/30";
                row.innerHTML = `
                    <span class="font-mono font-bold text-cyan-300 text-xs">${item.license_key}</span>
                    <span class="text-[10px] text-gray-400 mt-0.5">${item.validity || 'Purchased License'}</span>
                `;
                row.onclick = () => selectKeyForReset(item.license_key, item.reset_api_id);
                resultsBox.appendChild(row);
            });

            resultsBox.classList.remove('hidden');
        }

        function selectKeyForReset(key, resetApiId = 0) {
            const input = document.getElementById('resetKeySearchInput');
            input.value = key;
            document.getElementById('resetSearchResultsBox').classList.add('hidden');

            const apiSelect = document.getElementById('resetApiSelect');
            const rId = parseInt(resetApiId);
            if (apiSelect && rId > 0 && permittedResetApis[rId]) {
                apiSelect.value = rId;
                onResetApiSelectionChange();
            }
        }

        document.addEventListener('click', (e) => {
            const resultsBox = document.getElementById('resetSearchResultsBox');
            const searchInput = document.getElementById('resetKeySearchInput');
            if (resultsBox && searchInput && !resultsBox.contains(e.target) && e.target !== searchInput) {
                resultsBox.classList.add('hidden');
            }
        });

        function setResetTargetKey(key, resetApiId = 0) {
            goToPage('resetkey');
            selectKeyForReset(key, resetApiId);
        }

        // Live HWID Reset Submission using 6 LM APIs or Angry Mod Pinxt Directly
        async function handleLiveKeyReset(e) {
            e.preventDefault();
            const targetKey = document.getElementById('resetKeySearchInput').value.trim();
            const targetApiId = document.getElementById('resetApiSelect').value;
            const tokenInput = document.getElementById('angryModTokenInput');

            if (!targetKey) {
                showToast('Please enter a license key to reset!', 'error');
                return;
            }

            if (!targetApiId) {
                showToast('Please select one of the permitted reset APIs!', 'error');
                return;
            }

            const btn = document.getElementById('resetSubmitBtn');
            btn.disabled = true;
            document.getElementById('resetBtnText').innerText = 'RESETTING HWID...';

            const formData = new FormData();
            formData.append('ajax_action', 'reset_key');
            formData.append('license_key', targetKey);
            formData.append('reset_api_id', targetApiId);
            if (parseInt(targetApiId) === 99 && tokenInput) {
                formData.append('token', tokenInput.value.trim());
            }

            try {
                const res = await fetch(window.location.href, { method: 'POST', body: formData });
                const data = await res.json();
                const resultBox = document.getElementById('resetResultBox');

                resultBox.classList.remove('hidden');
                if (data.status === 'success') {
                    resultBox.className = "mt-4 p-4 rounded-2xl border border-emerald-500/40 bg-emerald-950/20 text-emerald-300 text-xs leading-relaxed";
                    resultBox.innerHTML = `<b>Success!</b> ${data.message}<br><span class="text-[10px] text-gray-400 font-mono">API Target: ${data.product_name || ('#' + targetApiId)}</span>`;
                    showToast(data.message, 'success');
                } else {
                    resultBox.className = "mt-4 p-4 rounded-2xl border border-pink-500/40 bg-pink-950/20 text-pink-300 text-xs leading-relaxed";
                    resultBox.innerHTML = `<b>Failed!</b> ${data.message}`;
                    showToast(data.message, 'error');
                }
            } catch (err) {
                showToast('Reset request timed out!', 'error');
            } finally {
                btn.disabled = false;
                document.getElementById('resetBtnText').innerText = 'RESET KEY';
            }
        }

        function closeKeySuccessModal() {
            document.getElementById('keySuccessModal').classList.add('hidden');
        }

        function copyAllGeneratedKeys() {
            if (latestKeys.length > 0) {
                navigator.clipboard.writeText(latestKeys.join('\n'));
                showToast('All keys copied to clipboard!', 'success');
            }
        }

        function openManageSubModal(sub) {
            document.getElementById('modalSubUsername').innerText = sub.username;
            document.getElementById('modalSubInfo').innerText = `Balance: $${parseFloat(sub.balance).toFixed(2)} | Role: ${sub.role.toUpperCase()}`;
            document.getElementById('modalTransferTargetId').value = sub.id;
            document.getElementById('modalDeductTargetId').value = sub.id;
            document.getElementById('modalManageTargetId').value = sub.id;
            document.getElementById('modalDeleteTargetId').value = sub.id;

            const grantedBal = Math.min(parseFloat(sub.parent_granted_balance || 0), parseFloat(sub.balance || 0));
            document.getElementById('modalGrantedBalText').innerText = `Max cut: $${grantedBal.toFixed(2)}`;

            const roleSelect = document.getElementById('modalSubRole');
            const roleNotice = document.getElementById('roleLockedNotice');
            if (sub.role === 'reseller') {
                roleSelect.value = 'reseller';
                roleSelect.disabled = true;
                roleNotice.classList.remove('hidden');
            } else {
                roleSelect.value = 'user';
                roleSelect.disabled = false;
                roleNotice.classList.add('hidden');
            }

            document.getElementById('modalSubStatus').value = sub.status;
            document.getElementById('modalSubRate').value = sub.custom_bdt_rate || currentBdtRate;
            document.getElementById('modalSubRate').min = currentBdtRate;
            
            const apiCb = document.getElementById('modalSubApi');
            apiCb.checked = (parseInt(sub.api_enabled) !== 0);

            const depCb = document.getElementById('modalSubAddFund');
            depCb.checked = (parseInt(sub.can_add_fund) !== 0);

            const allowed = sub.allowed_products ? sub.allowed_products.split(',') : [];
            document.querySelectorAll('.sub-product-checkbox').forEach(cb => {
                cb.checked = (allowed.length === 0 || allowed.includes(cb.value));
            });

            document.getElementById('manageSubModal').classList.remove('hidden');
        }

        function closeManageSubModal() {
            document.getElementById('manageSubModal').classList.add('hidden');
        }

        async function openSubHistoryModal(userId, username) {
            document.getElementById('subHistUsername').innerText = username;
            document.getElementById('subHistDepositsList').innerHTML = '<div class="text-center py-8 text-gray-500 text-xs"><i class="fa-solid fa-spinner fa-spin text-lg text-fuchsia-400 mb-2"></i><br>Loading history...</div>';
            document.getElementById('subHistKeysList').innerHTML = '<div class="text-center py-8 text-gray-500 text-xs"><i class="fa-solid fa-spinner fa-spin text-lg text-fuchsia-400 mb-2"></i><br>Loading history...</div>';
            
            switchSubHistTab('subhist-deposits');
            document.getElementById('subHistoryModal').classList.remove('hidden');

            const formData = new FormData();
            formData.append('ajax_action', 'get_sub_history');
            formData.append('target_user_id', userId);

            try {
                const res = await fetch(window.location.href, { method: 'POST', body: formData });
                const data = await res.json();

                if (data.status === 'success') {
                    const depContainer = document.getElementById('subHistDepositsList');
                    if (!data.deposits || data.deposits.length === 0) {
                        depContainer.innerHTML = '<p class="text-center py-6 text-gray-500 text-xs">No deposit records found for this account.</p>';
                    } else {
                        depContainer.innerHTML = '';
                        data.deposits.forEach(d => {
                            const statusClass = d.status === 'approved' ? 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/30' : (d.status === 'pending' ? 'bg-amber-500/20 text-amber-400 border border-amber-500/30' : 'bg-red-500/20 text-red-400 border border-red-500/30');
                            const div = document.createElement('div');
                            div.className = "bg-[#080312] p-3 rounded-xl border border-[#251346] flex justify-between items-center text-xs";
                            div.innerHTML = `
                                <div>
                                    <span class="font-bold text-white block">${d.method} • $${parseFloat(d.amount).toFixed(2)} USD</span>
                                    <span class="text-[10px] text-gray-400 font-mono">Trx: ${d.trx_id} • ${d.created_at}</span>
                                </div>
                                <span class="text-[9px] font-bold px-2 py-0.5 rounded uppercase ${statusClass}">
                                    ${d.status}
                                </span>
                            `;
                            depContainer.appendChild(div);
                        });
                    }

                    const keyContainer = document.getElementById('subHistKeysList');
                    if (!data.keys || data.keys.length === 0) {
                        keyContainer.innerHTML = '<p class="text-center py-6 text-gray-500 text-xs">No keys generated by this account yet.</p>';
                    } else {
                        keyContainer.innerHTML = '';
                        data.keys.forEach(k => {
                            const isApi = (k.source || '').toUpperCase() === 'API';
                            const srcBadge = isApi 
                                ? '<span class="text-[8px] font-mono font-bold px-1.5 py-0.5 rounded bg-cyan-500/20 text-cyan-300 border border-cyan-500/30">API</span>'
                                : '<span class="text-[8px] font-mono font-bold px-1.5 py-0.5 rounded bg-purple-500/20 text-purple-300 border border-purple-500/30">PANEL</span>';
                            
                            const div = document.createElement('div');
                            div.className = "bg-[#080312] p-3 rounded-xl border border-[#251346] space-y-1.5 text-xs";
                            div.innerHTML = `
                                <div class="flex justify-between items-center">
                                    <span class="font-mono text-fuchsia-300 font-bold text-xs select-all">${k.license_key}</span>
                                    <div class="flex items-center space-x-1.5">
                                        ${srcBadge}
                                        <button type="button" onclick="copyToClipboard('${k.license_key}')" class="text-gray-400 hover:text-white px-1.5 text-xs"><i class="fa-regular fa-copy"></i></button>
                                    </div>
                                </div>
                                <div class="flex justify-between items-center text-[10px] text-gray-400 pt-1 border-t border-[#1a0e33]">
                                    <span>${k.validity}</span>
                                    <span class="font-mono">${k.created_at}</span>
                                </div>
                            `;
                            keyContainer.appendChild(div);
                        });
                    }
                } else {
                    document.getElementById('subHistDepositsList').innerHTML = `<p class="text-center py-6 text-red-400 text-xs">${data.message}</p>`;
                    document.getElementById('subHistKeysList').innerHTML = `<p class="text-center py-6 text-red-400 text-xs">${data.message}</p>`;
                }
            } catch (e) {
                document.getElementById('subHistDepositsList').innerHTML = '<p class="text-center py-6 text-red-400 text-xs">Failed to load history!</p>';
                document.getElementById('subHistKeysList').innerHTML = '<p class="text-center py-6 text-red-400 text-xs">Failed to load history!</p>';
            }
        }

        function closeSubHistoryModal() {
            document.getElementById('subHistoryModal').classList.add('hidden');
        }

        function switchSubHistTab(tabId) {
            document.getElementById('subhist-deposits').classList.add('hidden');
            document.getElementById('subhist-keys').classList.add('hidden');

            document.getElementById('btn-hist-deposits').className = "flex-1 py-2 rounded-xl text-gray-400 hover:text-white transition text-center font-bold";
            document.getElementById('btn-hist-keys').className = "flex-1 py-2 rounded-xl text-gray-400 hover:text-white transition text-center font-bold";

            document.getElementById(tabId).classList.remove('hidden');
            const activeBtn = (tabId === 'subhist-deposits') ? document.getElementById('btn-hist-deposits') : document.getElementById('btn-hist-keys');
            activeBtn.className = "flex-1 py-2 rounded-xl text-white bg-fuchsia-600 transition text-center font-bold shadow-md";
        }

        function switchSubTab(tabId) {
            document.querySelectorAll('.sub-tab-content').forEach(el => el.classList.add('hidden'));
            document.querySelectorAll('.sub-tab-btn').forEach(btn => {
                btn.classList.remove('bg-fuchsia-600', 'text-white');
                btn.classList.add('text-gray-400');
            });
            document.getElementById(tabId).classList.remove('hidden');
            const targetBtn = (tabId === 'sub-list') ? document.getElementById('tab-btn-list') : document.getElementById('tab-btn-add');
            if (targetBtn) {
                targetBtn.classList.add('bg-fuchsia-600', 'text-white');
                targetBtn.classList.remove('text-gray-400');
            }
        }

        function selectDepositMethod(method, number, typeLabel, currency, el) {
            document.querySelectorAll('.deposit-tab').forEach(t => t.classList.remove('selected'));
            el.classList.add('selected');

            selectedCurrency = currency;
            document.getElementById('hiddenMethod').value = method;
            document.getElementById('depNumberText').innerText = number;
            document.getElementById('depMethodType').innerText = typeLabel;

            const label = document.getElementById('amountLabel');
            if (currency === 'BDT') {
                label.innerText = 'Amount to Pay in BDT (৳)';
                document.getElementById('depositAmountInput').placeholder = 'e.g. 125';
            } else {
                label.innerText = 'Amount to Pay in USDT ($)';
                document.getElementById('depositAmountInput').placeholder = 'e.g. 1.00';
            }
            calculateDepositConversion();
        }

        function calculateDepositConversion() {
            const inputEl = document.getElementById('depositAmountInput');
            const val = parseFloat(inputEl.value) || 0;
            const tag = document.getElementById('liveConversionTag');
            const errorAlert = document.getElementById('limitErrorAlert');
            const minMaxHint = document.getElementById('minMaxHintText');
            
            let usdEquivalent = 0;
            let minAllowedInCurrency = 0;
            let maxAllowedInCurrency = 0;
            let currencySign = '৳';

            if (selectedCurrency === 'BDT') {
                usdEquivalent = val > 0 ? (val / currentBdtRate) : 0;
                minAllowedInCurrency = Math.round(userMinDepositUSD * currentBdtRate);
                maxAllowedInCurrency = Math.round(userMaxDepositUSD * currentBdtRate);
                currencySign = '৳';
                tag.innerText = `≈ $${usdEquivalent.toFixed(2)} USD`;
                minMaxHint.innerText = `Min: ৳${minAllowedInCurrency} | Max: ৳${maxAllowedInCurrency} BDT`;
            } else {
                usdEquivalent = val > 0 ? ((val * currentMarketRate) / currentBdtRate) : 0;
                minAllowedInCurrency = userMinDepositUSD;
                maxAllowedInCurrency = userMaxDepositUSD;
                currencySign = '$';
                tag.innerText = `≈ $${usdEquivalent.toFixed(2)} USD`;
                minMaxHint.innerText = `Min: $${minAllowedInCurrency} | Max: $${maxAllowedInCurrency} USD`;
            }

            if (val > 0 && (val < minAllowedInCurrency || val > maxAllowedInCurrency)) {
                inputEl.classList.remove('border-[#251346]', 'text-white');
                inputEl.classList.add('border-red-500', 'bg-red-950/30', 'text-red-300', 'shake-animation');

                if (navigator.vibrate) {
                    navigator.vibrate([80, 40, 80]);
                }

                errorAlert.classList.remove('hidden');
                if (val < minAllowedInCurrency) {
                    errorAlert.innerText = `❌ Minimum deposit is ${currencySign}${minAllowedInCurrency}!`;
                } else {
                    errorAlert.innerText = `❌ Maximum deposit is ${currencySign}${maxAllowedInCurrency}!`;
                }

                setTimeout(() => {
                    inputEl.classList.remove('shake-animation');
                }, 350);
            } else {
                inputEl.classList.remove('border-red-500', 'bg-red-950/30', 'text-red-300', 'shake-animation');
                inputEl.classList.add('border-[#251346]', 'text-white');
                errorAlert.classList.add('hidden');
            }
        }

        function validateDepositLimits() {
            const inputEl = document.getElementById('depositAmountInput');
            const val = parseFloat(inputEl.value) || 0;
            
            let minAllowedInCurrency = selectedCurrency === 'BDT' ? Math.round(userMinDepositUSD * currentBdtRate) : userMinDepositUSD;
            let maxAllowedInCurrency = selectedCurrency === 'BDT' ? Math.round(userMaxDepositUSD * currentBdtRate) : userMaxDepositUSD;
            let currencySign = selectedCurrency === 'BDT' ? '৳' : '$';

            if (val < minAllowedInCurrency) {
                if (navigator.vibrate) navigator.vibrate([150, 50, 150]);
                inputEl.classList.add('border-red-500', 'bg-red-950/30', 'text-red-300', 'shake-animation');
                showToast(`Minimum deposit is ${currencySign}${minAllowedInCurrency}!`, 'error');
                return false;
            }

            if (val > maxAllowedInCurrency) {
                if (navigator.vibrate) navigator.vibrate([150, 50, 150]);
                inputEl.classList.add('border-red-500', 'bg-red-950/30', 'text-red-300', 'shake-animation');
                showToast(`Maximum deposit is ${currencySign}${maxAllowedInCurrency}!`, 'error');
                return false;
            }

            return true;
        }

        function copyDepositNumber() {
            copyToClipboard(document.getElementById('depNumberText').innerText, 'Payment Address');
        }

        function switchCodeTab(lang) {
            document.querySelectorAll('.code-tab-btn').forEach(btn => {
                btn.classList.remove('active', 'text-white');
                btn.classList.add('text-gray-400');
            });
            document.querySelectorAll('.code-block').forEach(block => block.classList.add('hidden'));

            document.getElementById('tab-code-' + lang).classList.add('active', 'text-white');
            document.getElementById('tab-code-' + lang).classList.remove('text-gray-400');
            document.getElementById('code-snippet-' + lang).classList.remove('hidden');
        }

        function toggleMenu() {
            document.getElementById('sidebar').classList.toggle('translate-x-full');
            document.getElementById('backdrop').classList.toggle('hidden');
        }

        function goToPage(pageId) {
            const sidebar = document.getElementById('sidebar');
            const backdrop = document.getElementById('backdrop');
            if (sidebar && backdrop) {
                sidebar.classList.add('translate-x-full');
                backdrop.classList.add('hidden');
            }

            document.querySelectorAll('.page-section').forEach(p => p.classList.add('hidden'));
            const activeEl = document.getElementById('page-' + pageId);
            if (activeEl) activeEl.classList.remove('hidden');

            document.querySelectorAll('.nav-item').forEach(btn => {
                btn.className = "nav-item w-full flex items-center space-x-3 px-4 py-3 rounded-xl text-gray-300 hover:bg-[#1b1138] transition";
            });
            const curNav = document.getElementById('nav-' + pageId);
            if (curNav) {
                curNav.className = "nav-item w-full flex items-center space-x-3 px-4 py-3 rounded-xl bg-gradient-to-r from-fuchsia-600 to-purple-600 text-white font-bold transition";
            }

            sessionStorage.setItem('dashboard_active_page', pageId);
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        function showToast(msg, type = 'success') {
            const toast = document.getElementById('toastNotification');
            const toastInner = document.getElementById('toastInner');
            const toastIcon = document.getElementById('toastIcon');
            document.getElementById('toastMsg').innerText = msg;
            
            if (type === 'error') {
                toastIcon.className = "fa-solid fa-triangle-exclamation text-pink-400 text-base";
                toastInner.className = "bg-[#180e1a] border border-pink-500/60 shadow-2xl rounded-2xl p-3.5 flex items-center space-x-3 text-xs font-semibold text-pink-200 backdrop-blur-xl";
            } else {
                toastIcon.className = "fa-solid fa-circle-check text-fuchsia-400 text-base";
                toastInner.className = "bg-[#1b1035] border border-fuchsia-500/50 shadow-2xl rounded-2xl p-3.5 flex items-center space-x-3 text-xs font-semibold text-fuchsia-200 backdrop-blur-xl";
            }

            toast.classList.remove('opacity-0', 'translate-y-[-20px]');
            toast.classList.add('opacity-100', 'translate-y-0');

            setTimeout(() => {
                toast.classList.remove('opacity-100', 'translate-y-0');
                toast.classList.add('opacity-0', 'translate-y-[-20px]');
            }, 3000);
        }

        function copyToClipboard(text, label = 'Copied') {
            navigator.clipboard.writeText(text);
            showToast(`${label} copied to clipboard!`, 'success');
        }

        window.addEventListener('DOMContentLoaded', () => {
            onProductTypeChange();
            onResetApiSelectionChange();
            const savedPage = '<?= $activePage ?>' !== 'generate' ? '<?= $activePage ?>' : (sessionStorage.getItem('dashboard_active_page') || 'generate');
            goToPage(savedPage);

            <?php if ($toast): ?>
                showToast("<?= addslashes($toast['msg']) ?>", "<?= $toast['type'] ?>");
            <?php endif; ?>
        });
    </script>
</body>
</html>