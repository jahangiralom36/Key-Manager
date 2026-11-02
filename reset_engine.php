<?php
// ====================================================================
// RESET_ENGINE.PHP — ONE RESET SYSTEM FOR PANEL, API & PUBLIC PAGE
// Provider: licensemanager.shop (api_id 2,3,4,9,11,12) + Angry Mod Pinxt (99)
// ====================================================================

/** Provider response er true/false value (bool, 1, "true", "success", "ok") thikmoto bujhe. */
function lm_is_truthy($v): bool {
    if (is_bool($v)) return $v;
    if (is_numeric($v)) return (int)$v === 1;
    return in_array(strtolower(trim((string)$v)), ['true', 'success', 'ok', 'yes'], true);
}

/** Sob supported reset API gulo (token ekhane nei — token config.php te). */
function lm_reset_apis(): array {
    return [
        2  => ['name' => 'DRIPCLIENT',            'slug' => 'drip',      'limit' => '100/day',    'type' => 'provider'],
        4  => ['name' => 'FLUORITE',              'slug' => 'fluorite',  'limit' => '100/day',    'type' => 'provider'],
        9  => ['name' => 'Haxxcker Reset',        'slug' => 'haxxcker',  'limit' => '100/day',    'type' => 'provider'],
        3  => ['name' => 'HGCHEATS',              'slug' => 'hgcheats',  'limit' => '100/day',    'type' => 'provider'],
        11 => ['name' => 'Silent Cheats APKMOD',  'slug' => 'sc_apkmod', 'limit' => '100/day',    'type' => 'provider'],
        12 => ['name' => 'Silent Cheats Root',    'slug' => 'sc_root',   'limit' => '100/day',    'type' => 'provider'],
        99 => ['name' => 'Angry Mod (Pinxt)',     'slug' => 'angrymod',  'limit' => 'Direct API', 'type' => 'angrymod'],
    ];
}

/** Product/plan name dekhe reset api id ber kore (jodi admin kono mapping na dey). */
function lm_detect_reset_api_id(string $productName, string $planTitle = ''): int {
    $n = strtolower($productName . ' ' . $planTitle);
    if (strpos($n, 'pinxt') !== false || strpos($n, 'angry') !== false) return 99;
    if (strpos($n, 'drip') !== false)     return 2;
    if (strpos($n, 'fluorite') !== false) return 4;
    if (strpos($n, 'haxx') !== false)     return 9;
    if (strpos($n, 'hgcheat') !== false || preg_match('/\bhg\b/', $n)) return 3;
    if (strpos($n, 'silent') !== false) {
        return (strpos($n, 'root') !== false) ? 12 : 11;
    }
    return 0;
}

/**
 * Priority: plan > product > license > name-detect.
 * (Agey COALESCE use hoto: 0 value NULL na tai product/license porjonto jeto na.)
 */
function lm_resolve_reset_api_id($planResetId, $productResetId, string $productName = '', $licenseResetId = 0, string $planTitle = ''): int {
    foreach ([$planResetId, $productResetId, $licenseResetId] as $id) {
        if ((int)$id > 0) return (int)$id;
    }
    return lm_detect_reset_api_id($productName, $planTitle);
}

/** Provider er products.php item theke reset api id ber kore (jei key-e thake). */
function lm_extract_provider_reset_id(array $item): int {
    foreach (['reset_api_id', 'reset_id', 'reset_api', 'resetApiId'] as $k) {
        if (isset($item[$k]) && is_numeric($item[$k]) && (int)$item[$k] > 0) return (int)$item[$k];
    }
    foreach (['reset', 'reset_system', 'reset_info'] as $k) {
        if (isset($item[$k]) && is_array($item[$k])) {
            foreach (['api_id', 'id', 'reset_api_id'] as $kk) {
                if (isset($item[$k][$kk]) && is_numeric($item[$k][$kk]) && (int)$item[$k][$kk] > 0) return (int)$item[$k][$kk];
            }
        }
    }
    return 0;
}

/**
 * Key database theke khuje ber kore.
 * $ownerId null => sob license-e khoje (public page / admin).
 * $ownerId set  => oi user ba tar sub-account er license.
 * Return: license row + product_name, resolved_reset_api_id  (ba null)
 */
function lm_descendant_ids(PDO $pdo, int $ownerId, int $maxDepth = 4): array {
    $all = [$ownerId];
    $frontier = [$ownerId];
    for ($d = 0; $d < $maxDepth && $frontier; $d++) {
        $in = implode(',', array_map('intval', $frontier));
        $rows = $pdo->query("SELECT id FROM users WHERE created_by IN ($in)")->fetchAll(PDO::FETCH_COLUMN);
        $frontier = [];
        foreach ($rows as $id) {
            $id = (int)$id;
            if (!in_array($id, $all, true)) { $all[] = $id; $frontier[] = $id; }
        }
    }
    return $all;
}

function lm_find_license(PDO $pdo, string $key, ?int $ownerId = null): ?array {
    $sql = "SELECT l.*, p.name AS product_name, pl.plan_title AS plan_title_db,
                   pl.reset_api_id AS plan_reset_api_id, p.reset_api_id AS prod_reset_api_id
            FROM licenses l
            LEFT JOIN products p ON l.product_id = p.id
            LEFT JOIN product_plans pl ON l.plan_id = pl.id
            WHERE l.license_key = ?";
    $params = [$key];
    if ($ownerId !== null) {
        // owner + tar sob sub-account (multi-level)
        $ids = implode(',', array_map('intval', lm_descendant_ids($pdo, $ownerId)));
        $sql .= " AND l.user_id IN ($ids)";
    }
    $sql .= " ORDER BY l.id DESC LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;

    $row['product_name'] = $row['product_name'] ?? '';
    $row['resolved_reset_api_id'] = lm_resolve_reset_api_id(
        $row['plan_reset_api_id'] ?? 0,
        $row['prod_reset_api_id'] ?? 0,
        (string)($row['product_name'] ?: ($row['validity'] ?? '')),
        $row['reset_api_id'] ?? 0,
        (string)($row['plan_title_db'] ?? '')
    );
    return $row;
}

/**
 * Candidate api id gulo ek ek kore try kore; prothom success-e thame.
 * (Customer-er pathano api_id bhul hole amader detect kora ta try hobe, ulto-o.)
 */
function lm_reset_with_fallback(string $key, array $candidateIds, string $customToken = ''): array {
    $tried = [];
    $last = ['ok' => false, 'message' => 'No reset API available.', 'api_id' => 0, 'api_name' => ''];
    foreach ($candidateIds as $id) {
        $id = (int)$id;
        if ($id <= 0 || in_array($id, $tried, true) || !isset(lm_reset_apis()[$id])) continue;
        $tried[] = $id;
        $last = lm_do_reset($key, $id, $customToken);
        if ($last['ok']) return $last;
    }
    return $last;
}

/** Key er basic format check (injection / junk atkate). */
function lm_valid_key_format(string $key): bool {
    return $key !== '' && strlen($key) <= 200 && !preg_match('/[\s\x00-\x1F]/', $key);
}

/**
 * Asol reset call. Provider ba Angry Mod — api id onujayi.
 * Return: ['ok'=>bool, 'message'=>string, 'api_id'=>int, 'api_name'=>string]
 */
function lm_do_reset(string $key, int $apiId, string $customToken = ''): array {
    $apis = lm_reset_apis();
    if (!isset($apis[$apiId])) {
        return ['ok' => false, 'message' => 'Invalid reset api_id. Supported: ' . implode(', ', array_keys($apis)), 'api_id' => $apiId, 'api_name' => ''];
    }
    $info = $apis[$apiId];
    $out  = ['ok' => false, 'message' => '', 'api_id' => $apiId, 'api_name' => $info['name']];

    if (!lm_valid_key_format($key)) {
        $out['message'] = 'Invalid license key format.';
        return $out;
    }

    // ---------- Angry Mod (Pinxt) ----------
    if ($info['type'] === 'angrymod') {
        $token = $customToken !== '' ? $customToken : (defined('ANGRYMOD_PINXT_TOKEN') ? (string)ANGRYMOD_PINXT_TOKEN : '');
        if ($token === '') {
            $out['message'] = 'Angry Mod token is not configured (ANGRYMOD_PINXT_TOKEN in config.php).';
            return $out;
        }
        $endpoint = defined('ANGRYMOD_RESET_URL') ? (string)ANGRYMOD_RESET_URL : 'https://angrymod.com/Pinxt/reset.php';
        $ch = curl_init($endpoint . '?key=' . urlencode($key) . '&token=' . urlencode($token));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
        ]);
        $response = curl_exec($ch);
        $http     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) { $out['message'] = 'Connection error: ' . $curlErr; return $out; }
        if ($http !== 200 || $response === false || $response === '') {
            $out['message'] = 'Angry Mod server HTTP ' . $http . ' error';
            return $out;
        }

        $res = json_decode($response, true);
        if (is_array($res)) {
            if ((isset($res['success']) && lm_is_truthy($res['success'])) || (isset($res['status']) && lm_is_truthy($res['status']))) {
                $out['ok'] = true;
                $out['message'] = (string)($res['message'] ?? 'Angry Mod HWID successfully reset!');
            } else {
                $out['message'] = (string)($res['error'] ?? ($res['message'] ?? 'Angry Mod key reset rejected.'));
            }
        } else {
            $raw = strtolower(trim((string)$response));
            // plain-text reply: "success"/"ok"/"true" hole ok, "fail/error/invalid" thakle na
            $bad = preg_match('/fail|error|invalid|denied|not\s*found|expired|limit/', $raw);
            if (!$bad && preg_match('/success|\bok\b|\btrue\b|reset/', $raw)) {
                $out['ok'] = true;
                $out['message'] = 'Angry Mod key reset successful!';
            } else {
                $out['message'] = mb_substr(trim(strip_tags((string)$response)), 0, 200) ?: 'Angry Mod key reset rejected.';
            }
        }
        return $out;
    }

    // ---------- LicenseManager provider ----------
    $token = lm_provider_token();
    if ($token === '') {
        $out['message'] = 'Provider API Token is missing in config.php';
        return $out;
    }
    $ch = curl_init(lm_provider_base() . '/reset_key.php?api_id=' . $apiId . '&key=' . urlencode($key));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET        => true,
        CURLOPT_HTTPHEADER     => ['X-API-Token: ' . $token, 'Accept: application/json'],
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 30
    ]);
    $response = curl_exec($ch);
    $http     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) { $out['message'] = 'Curl error: ' . $curlErr; return $out; }

    $res = json_decode($response ?: '', true);
    if (is_array($res)) {
        $success = (isset($res['success']) && lm_is_truthy($res['success'])) || (isset($res['status']) && lm_is_truthy($res['status']));
        if ($success && $http >= 200 && $http < 300) {
            $out['ok'] = true;
            $out['message'] = (string)($res['message'] ?? 'License HWID has been successfully reset!');
        } else {
            // provider er asol error message (limit, invalid key...) dekhao, shudhu HTTP code na
            $out['message'] = (string)($res['error'] ?? ($res['message'] ?? ('Reset request failed at provider (HTTP ' . $http . ').')));
        }
    } else {
        $out['message'] = 'Provider server response error (HTTP ' . $http . ')';
    }
    return $out;
}

/**
 * License row gulo-te `reset_api_id` bosiye dey (plan > product > license > name).
 * Rows e product_id, plan_id, reset_api_id, validity thakte hobe.
 */
function lm_enrich_licenses_reset(PDO $pdo, array $rows): array {
    if (empty($rows)) return $rows;
    static $map = null;
    if ($map === null) {
        $map = [];
        try {
            $q = $pdo->query("SELECT pl.id AS plan_id, pl.plan_title, pl.reset_api_id AS plan_reset, p.name AS pname, p.reset_api_id AS prod_reset
                              FROM product_plans pl JOIN products p ON pl.product_id = p.id");
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $map[(int)$r['plan_id']] = lm_resolve_reset_api_id($r['plan_reset'], $r['prod_reset'], (string)$r['pname'], 0, (string)$r['plan_title']);
            }
        } catch (Throwable $e) {}
    }
    foreach ($rows as &$row) {
        $id = (int)($row['reset_api_id'] ?? 0);
        if ($id <= 0 && isset($map[(int)($row['plan_id'] ?? 0)])) $id = $map[(int)$row['plan_id']];
        if ($id <= 0) $id = lm_detect_reset_api_id((string)($row['product_name'] ?? ''), (string)($row['validity'] ?? ''));
        $row['reset_api_id'] = $id;
    }
    unset($row);
    return $rows;
}
