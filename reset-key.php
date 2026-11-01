<?php
// ====================================================================
// RESET-KEY.PHP — PUBLIC HWID RESET PAGE (customer-der jonno, login lage na)
// Shudhu amader site theke kena key (licenses table) reset hobe.
// Reset API product theke auto-detect hoy (provider api_id 2,3,4,9,11,12 / Angry Mod 99)
// ====================================================================
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/config.php';

if (empty($_SESSION['reset_csrf'])) {
    $_SESSION['reset_csrf'] = bin2hex(random_bytes(16));
}

$ip = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $fail = function (string $msg, int $code = 400) {
        http_response_code($code);
        echo json_encode(['status' => 'error', 'success' => false, 'message' => $msg]);
        exit;
    };

    if (!empty($_POST['hp_check'])) $fail('Automated access denied.', 403);
    if (!hash_equals($_SESSION['reset_csrf'], (string)($_POST['csrf'] ?? ''))) {
        $fail('Session expired. Please refresh the page and try again.', 403);
    }

    $key = trim((string)($_POST['key'] ?? ''));
    if (!lm_valid_key_format($key)) $fail('Please enter a valid license key.');

    // Rate limit: IP per ghontay 10 ta chesta
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM activity_logs WHERE action IN ('PUBLIC_RESET','PUBLIC_RESET_FAIL') AND ip_address = ? AND created_at >= NOW() - INTERVAL 1 HOUR");
        $st->execute([$ip]);
        if ((int)$st->fetchColumn() >= 10) $fail('Too many attempts. Please try again after 1 hour.', 429);

        // Same key 60 sec er moddhe abar noy
        $st = $pdo->prepare("SELECT COUNT(*) FROM activity_logs WHERE action IN ('PUBLIC_RESET','PUBLIC_RESET_FAIL') AND details LIKE ? AND created_at >= NOW() - INTERVAL 60 SECOND");
        $st->execute(['%' . $key . '%']);
        if ((int)$st->fetchColumn() > 0) $fail('This key was just processed. Please wait a minute before retrying.', 429);
    } catch (Throwable $e) {}

    $license = lm_find_license($pdo, $key, null);
    if (!$license) {
        logActivity($pdo, null, 'PUBLIC', 'PUBLIC_RESET_FAIL', "Unknown key: {$key}");
        $fail('This key was not found. Please check the key and try again.', 404);
    }

    $apiId = (int)$license['resolved_reset_api_id'];
    if ($apiId <= 0) {
        logActivity($pdo, null, 'PUBLIC', 'PUBLIC_RESET_FAIL', "No reset api for key: {$key}");
        $fail('Reset is not available for this product. Please contact support.', 422);
    }

    $r = lm_do_reset($key, $apiId);
    logActivity($pdo, null, 'PUBLIC', $r['ok'] ? 'KEY_RESET' : 'PUBLIC_RESET_FAIL',
        ($r['ok'] ? 'Key reset via PUBLIC PAGE' : 'Public reset failed') . ": {$key} (API #{$apiId} {$r['api_name']}) " . ($r['ok'] ? '' : '— ' . $r['message']));

    echo json_encode([
        'status'   => $r['ok'] ? 'success' : 'error',
        'success'  => $r['ok'],
        'message'  => $r['message'],
        'product'  => $license['product_name'] ?: ($license['validity'] ?? ''),
        'api_name' => $r['api_name']
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Key — <?= defined('SITE_NAME') ? htmlspecialchars(SITE_NAME) : 'Reseller Panel' ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>body{background:#0c0818;color:#f1f5f9;font-family:system-ui,sans-serif}</style>
</head>
<body class="min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-md bg-[#120c24] border border-[#2d184f] rounded-3xl p-6 space-y-5 shadow-2xl">
        <div class="text-center space-y-1">
            <h1 class="text-xl font-black tracking-wider uppercase">Reset Key / HWID</h1>
            <p class="text-xs text-purple-300/70">Enter your license key to unbind the device</p>
        </div>
        <form id="f" class="space-y-4">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['reset_csrf']) ?>">
            <input type="text" name="hp_check" style="display:none" tabindex="-1" autocomplete="off">
            <input type="text" name="key" id="key" required maxlength="200" autocomplete="off" placeholder="USERKEY-XXXX-XXXX"
                   class="w-full bg-[#080413] border border-[#2c164f] rounded-2xl p-3.5 text-sm font-mono text-cyan-300 outline-none focus:border-cyan-400">
            <button id="btn" class="w-full py-3.5 rounded-2xl bg-gradient-to-r from-cyan-500 to-blue-600 text-gray-950 font-black text-sm uppercase tracking-wider">Reset Key</button>
        </form>
        <div id="out" class="hidden p-3.5 rounded-2xl border text-xs leading-relaxed"></div>
    </div>
    <script>
    document.getElementById('f').addEventListener('submit', async function (e) {
        e.preventDefault();
        const btn = document.getElementById('btn'), out = document.getElementById('out');
        btn.disabled = true; btn.textContent = 'RESETTING...';
        try {
            const res = await fetch('/reset-key.php', { method: 'POST', body: new FormData(e.target) });
            const d = await res.json();
            out.className = 'p-3.5 rounded-2xl border text-xs leading-relaxed ' + (d.success
                ? 'bg-emerald-950/60 border-emerald-500/50 text-emerald-300'
                : 'bg-red-950/60 border-red-500/50 text-red-300');
            out.textContent = (d.success ? '✅ ' : '❌ ') + (d.message || 'Unknown response') + (d.success && d.product ? ' — ' + d.product : '');
        } catch (err) {
            out.className = 'p-3.5 rounded-2xl border text-xs bg-red-950/60 border-red-500/50 text-red-300';
            out.textContent = '❌ Server connection error!';
        } finally {
            out.classList.remove('hidden');
            btn.disabled = false; btn.textContent = 'Reset Key';
        }
    });
    </script>
</body>
</html>
