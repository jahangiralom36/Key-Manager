<?php
// ====================================================================
// ADMIN_EXTRA_TABS.PHP — Deposits (search + types), Reports (sales/history/stock/resellers), Chat
// admin.php er <main> er bhitore include hoy.
// ====================================================================
$__bonusSlabs = [];
try { $__bonusSlabs = $pdo->query("SELECT min_amount, bonus_amount, bonus_type FROM deposit_bonuses ORDER BY min_amount ASC")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
?>
<!-- ================================================================= -->
<!-- TAB: DEPOSITS (search by TrxID / reseller, type wise)              -->
<!-- ================================================================= -->
<section id="tab-deposits" class="tab-section hidden space-y-5">
    <div class="noc-card p-5 space-y-4">
        <div class="flex justify-between items-center pb-3 border-b border-cyber-border gap-2">
            <div class="flex items-center space-x-3">
                <div class="w-9 h-9 rounded-xl bg-teal-500/15 text-teal-400 flex items-center justify-center text-sm border border-teal-500/30"><i class="fa-solid fa-vault"></i></div>
                <h3 class="text-sm font-bold text-white font-mono uppercase tracking-wider">Deposits</h3>
            </div>
            <a href="admin.php?export=transactions" class="px-3 py-2 rounded-xl bg-emerald-500/15 border border-emerald-500/30 text-emerald-300 text-[11px] font-bold font-mono"><i class="fa-solid fa-download mr-1"></i>CSV</a>
        </div>

        <input type="text" id="dpSearch" oninput="dpDebounce()" placeholder="Search TrxID / Reseller / Sender No" class="w-full bg-[#040711] border border-cyber-border p-3 rounded-xl text-xs text-white font-mono outline-none focus:border-cyan-400">

        <div class="grid grid-cols-4 gap-2" id="dpStatusBar">
            <button type="button" data-v="" onclick="dpSetStatus('')" class="dp-st py-2 rounded-xl text-[11px] font-bold font-mono border transition">All</button>
            <button type="button" data-v="pending" onclick="dpSetStatus('pending')" class="dp-st py-2 rounded-xl text-[11px] font-bold font-mono border transition">Pending <span id="dpPendCnt" class="opacity-70"></span></button>
            <button type="button" data-v="approved" onclick="dpSetStatus('approved')" class="dp-st py-2 rounded-xl text-[11px] font-bold font-mono border transition">Approved</button>
            <button type="button" data-v="rejected" onclick="dpSetStatus('rejected')" class="dp-st py-2 rounded-xl text-[11px] font-bold font-mono border transition">Rejected</button>
        </div>
        <div class="grid grid-cols-5 gap-1.5" id="dpTypeBar">
            <button type="button" data-v="" onclick="dpSetType('')" class="dp-ty py-1.5 rounded-lg text-[10px] font-bold font-mono border transition">All</button>
            <button type="button" data-v="AUTO" onclick="dpSetType('AUTO')" class="dp-ty py-1.5 rounded-lg text-[10px] font-bold font-mono border transition">Auto</button>
            <button type="button" data-v="MANUAL" onclick="dpSetType('MANUAL')" class="dp-ty py-1.5 rounded-lg text-[10px] font-bold font-mono border transition">Manual</button>
            <button type="button" data-v="ADMIN_ADD" onclick="dpSetType('ADMIN_ADD')" class="dp-ty py-1.5 rounded-lg text-[10px] font-bold font-mono border transition">Admin +</button>
            <button type="button" data-v="ADMIN_DEDUCT" onclick="dpSetType('ADMIN_DEDUCT')" class="dp-ty py-1.5 rounded-lg text-[10px] font-bold font-mono border transition">Admin −</button>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs font-mono">
                <thead><tr class="text-gray-400 border-b border-cyber-border">
                    <th class="pb-2.5">Reseller</th><th class="pb-2.5">Type</th><th class="pb-2.5">Amount</th><th class="pb-2.5">Sender</th>
                    <th class="pb-2.5">Trx ID</th><th class="pb-2.5">Date</th><th class="pb-2.5">Status</th><th class="pb-2.5 text-right">Action</th>
                </tr></thead>
                <tbody id="dpBody" class="divide-y divide-cyber-border text-[11px]"><tr><td colspan="8" class="py-6 text-center text-gray-500">Loading…</td></tr></tbody>
            </table>
        </div>
    </div>

    <?php if (!empty($__bonusSlabs)): ?>
    <div class="noc-card p-4 space-y-2">
        <h4 class="text-xs font-bold text-white uppercase font-mono">Active Bonus Slabs</h4>
        <div class="flex flex-wrap gap-2">
            <?php foreach ($__bonusSlabs as $b): ?>
                <span class="px-2.5 py-1 rounded-lg bg-amber-500/10 border border-amber-500/30 text-amber-300 text-[11px] font-mono">
                    $<?= rtrim(rtrim(number_format((float)$b['min_amount'], 2), '0'), '.') ?> → +<?= $b['bonus_type'] === 'percent' ? rtrim(rtrim(number_format((float)$b['bonus_amount'], 2), '0'), '.') . '%' : '$' . rtrim(rtrim(number_format((float)$b['bonus_amount'], 2), '0'), '.') ?>
                </span>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</section>

<!-- ================================================================= -->
<!-- TAB: REPORTS (Sales / History / Stock & API / Reseller Stats)      -->
<!-- ================================================================= -->
<section id="tab-reports" class="tab-section hidden space-y-5">
    <div class="grid grid-cols-4 gap-1.5 bg-[#080d1a] p-1.5 rounded-2xl border border-cyber-border text-[11px] font-bold font-mono">
        <button type="button" onclick="rpTab('sales')" id="rp-btn-sales" class="rp-btn py-2.5 rounded-xl transition">Sales</button>
        <button type="button" onclick="rpTab('history')" id="rp-btn-history" class="rp-btn py-2.5 rounded-xl transition">History</button>
        <button type="button" onclick="rpTab('stock')" id="rp-btn-stock" class="rp-btn py-2.5 rounded-xl transition">Stock/API</button>
        <button type="button" onclick="rpTab('resellers')" id="rp-btn-resellers" class="rp-btn py-2.5 rounded-xl transition">Resellers</button>
    </div>

    <!-- SALES -->
    <div id="rp-sales" class="rp-pane noc-card p-5 space-y-4">
        <h3 class="text-sm font-bold text-white font-mono uppercase">Sales Summary</h3>
        <div class="grid grid-cols-2 gap-2">
            <input type="date" id="rpFrom" class="bg-[#040711] border border-cyber-border p-2.5 rounded-xl text-xs text-white font-mono">
            <input type="date" id="rpTo" class="bg-[#040711] border border-cyber-border p-2.5 rounded-xl text-xs text-white font-mono">
        </div>
        <div class="flex gap-2">
            <button type="button" onclick="rpLoadSales()" class="flex-1 py-2.5 rounded-xl bg-cyan-500/15 border border-cyan-500/30 text-cyan-300 text-xs font-bold font-mono">Show</button>
            <button type="button" onclick="document.getElementById('rpFrom').value='';document.getElementById('rpTo').value='';rpLoadSales()" class="px-4 py-2.5 rounded-xl bg-[#0c1326] border border-cyber-border text-gray-300 text-xs font-bold font-mono">All time</button>
        </div>
        <div id="rpSalesTotals" class="grid grid-cols-2 gap-2"></div>
        <div id="rpSalesList" class="space-y-3"></div>
    </div>

    <!-- HISTORY -->
    <div id="rp-history" class="rp-pane hidden noc-card p-5 space-y-4">
        <div class="flex items-center justify-between gap-2">
            <h3 class="text-sm font-bold text-white font-mono uppercase">Key History</h3>
            <a id="rpExportKeys" href="admin.php?export=keys" class="px-3 py-2 rounded-xl bg-emerald-500/15 border border-emerald-500/30 text-emerald-300 text-[11px] font-bold font-mono"><i class="fa-solid fa-download mr-1"></i>Export all keys</a>
        </div>
        <input type="text" id="hsSearch" oninput="hsDebounce()" placeholder="Search key / reseller / product / validity" class="w-full bg-[#040711] border border-cyber-border p-3 rounded-xl text-xs text-white font-mono outline-none focus:border-cyan-400">
        <div class="grid grid-cols-2 gap-2">
            <input type="date" id="hsFrom" onchange="hsLoad(1)" class="bg-[#040711] border border-cyber-border p-2.5 rounded-xl text-xs text-white font-mono">
            <input type="date" id="hsTo" onchange="hsLoad(1)" class="bg-[#040711] border border-cyber-border p-2.5 rounded-xl text-xs text-white font-mono">
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs font-mono">
                <thead><tr class="text-gray-400 border-b border-cyber-border"><th class="pb-2.5">Key</th><th class="pb-2.5">Reseller</th><th class="pb-2.5">Product</th><th class="pb-2.5">Validity</th><th class="pb-2.5">Price</th><th class="pb-2.5">Date</th></tr></thead>
                <tbody id="hsBody" class="divide-y divide-cyber-border text-[11px]"></tbody>
            </table>
        </div>
        <div class="flex items-center justify-between text-[11px] font-mono text-gray-400">
            <button type="button" onclick="hsLoad(hsPage-1)" class="px-3 py-1.5 rounded-lg bg-[#0c1326] border border-cyber-border">‹ Prev</button>
            <span id="hsInfo"></span>
            <button type="button" onclick="hsLoad(hsPage+1)" class="px-3 py-1.5 rounded-lg bg-[#0c1326] border border-cyber-border">Next ›</button>
        </div>
    </div>

    <!-- STOCK / API -->
    <div id="rp-stock" class="rp-pane hidden noc-card p-5 space-y-4">
        <h3 class="text-sm font-bold text-white font-mono uppercase">Stock & API</h3>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs font-mono">
                <thead><tr class="text-gray-400 border-b border-cyber-border"><th class="pb-2.5">Product</th><th class="pb-2.5">Validity</th><th class="pb-2.5">In Stock</th><th class="pb-2.5">Sold</th><th class="pb-2.5">API</th></tr></thead>
                <tbody id="stBody" class="divide-y divide-cyber-border text-[11px]"></tbody>
            </table>
        </div>
    </div>

    <!-- RESELLERS -->
    <div id="rp-resellers" class="rp-pane hidden noc-card p-5 space-y-4">
        <h3 class="text-sm font-bold text-white font-mono uppercase">Reseller Investment</h3>
        <input type="text" id="rsSearch" oninput="rsDebounce()" placeholder="Search reseller" class="w-full bg-[#040711] border border-cyber-border p-3 rounded-xl text-xs text-white font-mono outline-none focus:border-cyan-400">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs font-mono">
                <thead><tr class="text-gray-400 border-b border-cyber-border"><th class="pb-2.5">Reseller</th><th class="pb-2.5">Deposited</th><th class="pb-2.5">Bonus</th><th class="pb-2.5">Admin +/−</th><th class="pb-2.5">Keys</th><th class="pb-2.5">Spent</th><th class="pb-2.5">Balance</th></tr></thead>
                <tbody id="rsBody" class="divide-y divide-cyber-border text-[11px]"></tbody>
            </table>
        </div>
    </div>
</section>

<!-- ================================================================= -->
<!-- TAB: CHAT (admin <-> reseller one to one)                          -->
<!-- ================================================================= -->
<section id="tab-chat" class="tab-section hidden space-y-5">
    <div id="chatListBox" class="noc-card p-5 space-y-3">
        <h3 class="text-sm font-bold text-white font-mono uppercase">Messages</h3>
        <input type="text" id="chatSearch" oninput="chatRenderList()" placeholder="Search reseller" class="w-full bg-[#040711] border border-cyber-border p-3 rounded-xl text-xs text-white font-mono outline-none focus:border-cyan-400">
        <div id="chatUsers" class="space-y-1.5"></div>
    </div>
    <div id="chatThreadBox" class="noc-card p-4 space-y-3 hidden">
        <div class="flex items-center justify-between">
            <button type="button" onclick="chatBack()" class="px-3 py-1.5 rounded-lg bg-[#0c1326] border border-cyber-border text-gray-300 text-xs font-mono">‹ Back</button>
            <strong id="chatWith" class="text-sm text-white font-mono"></strong>
        </div>
        <div id="chatMsgs" class="h-[55vh] overflow-y-auto space-y-2 bg-[#040711] border border-cyber-border rounded-2xl p-3"></div>
        <form onsubmit="chatSend(event)" class="flex gap-2">
            <input type="text" id="chatInput" maxlength="2000" autocomplete="off" placeholder="Type a message…" class="flex-1 bg-[#040711] border border-cyber-border p-3 rounded-xl text-sm text-white outline-none focus:border-cyan-400">
            <button class="px-4 rounded-xl bg-cyan-500 text-gray-950 font-black text-xs font-mono">Send</button>
        </form>
    </div>
</section>

<style>
    .dp-st, .dp-ty, .rp-btn { background:#080d1a; border-color:#192648; color:#94a3b8; }
    .dp-st.active, .dp-ty.active, .rp-btn.active { background:rgba(0,245,255,.15); border-color:rgba(0,245,255,.5); color:#67e8f9; }
    .rp-btn { border:1px solid #192648; }
</style>
<script>
(function () {
    const $ = (id) => document.getElementById(id);
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const money = (n) => '$' + (parseFloat(n) || 0).toFixed(2);
    async function api(action, data = {}) {
        const fd = new FormData(); fd.append('ajax_action', action);
        for (const k in data) fd.append(k, data[k]);
        try { const r = await fetch('admin.php', { method: 'POST', body: fd }); return await r.json(); }
        catch (e) { return { success: false, error: 'Network error' }; }
    }
    function debounce(fn, ms = 350) { let t; return () => { clearTimeout(t); t = setTimeout(fn, ms); }; }

    // ---------------- DEPOSITS ----------------
    let dpStatus = '', dpType = '';
    const TYPE_LBL = { AUTO: ['Auto', 'emerald'], MANUAL: ['Manual', 'amber'], ADMIN_ADD: ['Admin +', 'cyan'], ADMIN_DEDUCT: ['Admin −', 'rose'] };
    window.dpSetStatus = (v) => { dpStatus = v; dpMark(); dpLoad(); };
    window.dpSetType = (v) => { dpType = v; dpMark(); dpLoad(); };
    window.dpDebounce = debounce(() => dpLoad());
    function dpMark() {
        document.querySelectorAll('.dp-st').forEach(b => b.classList.toggle('active', b.dataset.v === dpStatus));
        document.querySelectorAll('.dp-ty').forEach(b => b.classList.toggle('active', b.dataset.v === dpType));
    }
    async function dpLoad() {
        const d = await api('deposits_search', { q: $('dpSearch').value, status: dpStatus, type: dpType });
        const body = $('dpBody');
        if (!d.success || !d.rows.length) { body.innerHTML = '<tr><td colspan="8" class="py-6 text-center text-gray-500 italic">No records.</td></tr>'; return; }
        const pend = d.rows.filter(r => r.status === 'pending').length;
        $('dpPendCnt').textContent = (dpStatus === '' && pend) ? '(' + pend + ')' : '';
        body.innerHTML = d.rows.map(r => {
            const t = TYPE_LBL[r.type] || TYPE_LBL.MANUAL, amt = parseFloat(r.amount), neg = amt < 0;
            const bonus = parseFloat(r.bonus_amount) > 0 ? '<span class="block text-[10px] text-amber-300">+' + money(r.bonus_amount) + ' bonus</span>' : '';
            const stc = r.status === 'approved' ? 'emerald' : (r.status === 'pending' ? 'amber' : 'red');
            let act = '<span class="text-gray-500 text-[10px]">Settled</span>';
            if (r.status === 'pending') act = '<form method="POST" class="inline"><input type="hidden" name="admin_action" value="manage_trx"><input type="hidden" name="active_tab" value="tab-deposits"><input type="hidden" name="trx_db_id" value="' + r.id + '">'
                + '<button name="status" value="approved" class="px-2.5 py-1 bg-emerald-600 rounded text-[10px] font-bold text-white mr-1">Approve</button>'
                + '<button name="status" value="rejected" class="px-2.5 py-1 bg-red-600 rounded text-[10px] font-bold text-white">Reject</button></form>';
            return '<tr class="hover:bg-[#0c1326]">'
              + '<td class="py-2.5 font-bold text-white font-sans">' + esc(r.username) + '</td>'
              + '<td class="py-2.5"><span class="px-2 py-0.5 rounded text-[9px] font-bold uppercase bg-' + t[1] + '-500/15 text-' + t[1] + '-300 border border-' + t[1] + '-500/30">' + t[0] + '</span><span class="block text-[10px] text-gray-500">' + esc(r.method) + '</span></td>'
              + '<td class="py-2.5 font-black ' + (neg ? 'text-rose-400' : 'text-emerald-400') + '">' + (neg ? '−' : '+') + money(Math.abs(amt)) + bonus + '</td>'
              + '<td class="py-2.5 text-gray-300">' + esc(r.sender_num || '—') + '</td>'
              + '<td class="py-2.5 text-cyan-300 font-bold break-all">' + esc(r.trx_id) + (r.note ? '<span class="block text-[10px] text-gray-500 font-normal">' + esc(r.note) + '</span>' : '') + '</td>'
              + '<td class="py-2.5 text-gray-400">' + esc(String(r.created_at).slice(0, 16)) + '</td>'
              + '<td class="py-2.5"><span class="px-2 py-0.5 rounded text-[9px] font-bold uppercase bg-' + stc + '-500/15 text-' + stc + '-300 border border-' + stc + '-500/30">' + esc(r.status) + '</span></td>'
              + '<td class="py-2.5 text-right">' + act + '</td></tr>';
        }).join('');
    }

    // ---------------- REPORTS ----------------
    let rpCur = 'sales';
    window.rpTab = (name) => {
        rpCur = name;
        document.querySelectorAll('.rp-pane').forEach(p => p.classList.add('hidden'));
        $('rp-' + name).classList.remove('hidden');
        document.querySelectorAll('.rp-btn').forEach(b => b.classList.toggle('active', b.id === 'rp-btn-' + name));
        if (name === 'sales') rpLoadSales(); else if (name === 'history') hsLoad(1); else if (name === 'stock') stLoad(); else rsLoad();
    };
    window.rpLoadSales = async () => {
        const d = await api('report_sales', { from: $('rpFrom').value, to: $('rpTo').value });
        if (!d.success) { $('rpSalesList').innerHTML = '<p class="text-red-400 text-xs">' + esc(d.error) + '</p>'; return; }
        $('rpSalesTotals').innerHTML = '<div class="bg-[#040711] border border-cyber-border rounded-2xl p-3"><span class="text-[10px] text-gray-400 font-mono uppercase block">Keys Sold</span><strong class="text-xl text-amber-300 font-mono">' + d.total_sold + '</strong></div>'
            + '<div class="bg-[#040711] border border-cyber-border rounded-2xl p-3"><span class="text-[10px] text-gray-400 font-mono uppercase block">Revenue</span><strong class="text-xl text-emerald-300 font-mono">' + money(d.total_revenue) + '</strong></div>';
        const groups = {};
        d.rows.forEach(r => { (groups[r.product_name] = groups[r.product_name] || []).push(r); });
        const names = Object.keys(groups);
        $('rpSalesList').innerHTML = names.length ? names.map(n => {
            const g = groups[n], tot = g.reduce((a, r) => a + r.sold, 0);
            return '<div class="bg-[#040711] border border-cyber-border rounded-2xl p-3 space-y-1.5"><div class="flex justify-between items-center"><strong class="text-white text-xs font-mono">' + esc(n) + '</strong><span class="text-amber-300 text-xs font-mono font-bold">' + tot + ' sold</span></div>'
              + g.map(r => '<div class="flex justify-between text-[11px] font-mono border-t border-cyber-border pt-1.5"><span class="text-gray-300">' + esc(r.plan_title) + '</span><span><b class="text-white">' + r.sold + '</b> <span class="text-emerald-400">' + money(r.revenue) + '</span></span></div>').join('') + '</div>';
        }).join('') : '<p class="text-gray-500 text-xs italic">No sales in this period.</p>';
    };

    let hsPage = 1, hsPages = 1; window.hsPage = 1;
    window.hsDebounce = debounce(() => hsLoad(1));
    window.hsLoad = async (p) => {
        p = Math.max(1, Math.min(p, hsPages)); hsPage = p; window.hsPage = p;
        const q = $('hsSearch').value, f = $('hsFrom').value, t = $('hsTo').value;
        $('rpExportKeys').href = 'admin.php?export=keys&q=' + encodeURIComponent(q) + '&from=' + f + '&to=' + t;
        const d = await api('history_search', { q, from: f, to: t, page: p });
        if (!d.success) return;
        hsPages = Math.max(1, Math.ceil(d.total / d.per_page));
        $('hsInfo').textContent = 'Page ' + d.page + '/' + hsPages + ' • ' + d.total + ' keys';
        $('hsBody').innerHTML = d.rows.length ? d.rows.map(r => '<tr class="hover:bg-[#0c1326]"><td class="py-2 text-cyan-300 font-bold break-all select-all">' + esc(r.license_key) + '</td><td class="py-2 text-white font-sans">' + esc(r.username) + '</td><td class="py-2 text-gray-300">' + esc(r.product_name) + '</td><td class="py-2 text-gray-400">' + esc(r.plan_title) + '</td><td class="py-2 text-emerald-400">' + (parseFloat(r.price) > 0 ? money(r.price) : '—') + '</td><td class="py-2 text-gray-500">' + esc(String(r.created_at).slice(0, 16)) + '</td></tr>').join('')
            : '<tr><td colspan="6" class="py-6 text-center text-gray-500 italic">No keys found.</td></tr>';
    };

    async function stLoad() {
        const d = await api('stock_overview'); if (!d.success) return;
        $('stBody').innerHTML = d.rows.map(r => '<tr class="hover:bg-[#0c1326]"><td class="py-2 text-white font-sans">' + esc(r.product_name) + '</td><td class="py-2 text-gray-300">' + esc(r.plan_title) + '</td>'
            + '<td class="py-2 font-bold ' + (parseInt(r.stock_available) > 0 ? 'text-emerald-400' : 'text-gray-500') + '">' + r.stock_available + '</td><td class="py-2 text-amber-300">' + r.sold + '</td>'
            + '<td class="py-2">' + (r.api_linked ? '<span class="px-2 py-0.5 rounded text-[9px] font-bold bg-cyan-500/15 text-cyan-300 border border-cyan-500/30">API #' + r.api_plan_id + '</span>' : '<span class="px-2 py-0.5 rounded text-[9px] font-bold bg-gray-500/15 text-gray-400 border border-gray-500/30">No API</span>') + '</td></tr>').join('');
    }
    window.rsDebounce = debounce(() => rsLoad());
    async function rsLoad() {
        const d = await api('reseller_stats', { q: $('rsSearch').value }); if (!d.success) return;
        $('rsBody').innerHTML = d.rows.map(r => '<tr class="hover:bg-[#0c1326]"><td class="py-2 font-bold text-white font-sans">' + esc(r.username) + '</td>'
            + '<td class="py-2 text-emerald-400 font-bold">' + money(r.deposited) + '</td><td class="py-2 text-amber-300">' + money(r.bonus) + '</td>'
            + '<td class="py-2"><span class="text-cyan-300">+' + money(r.admin_added) + '</span> <span class="text-rose-400">−' + money(r.admin_deducted) + '</span></td>'
            + '<td class="py-2 text-gray-300">' + r.keys_bought + '</td><td class="py-2 text-fuchsia-300">' + money(r.spent) + '</td><td class="py-2 text-white">' + money(r.balance) + '</td></tr>').join('');
    }

    // ---------------- CHAT ----------------
    let chatUsers = [], chatUid = 0, chatLast = 0, chatTimer = null;
    async function chatLoadList() { const d = await api('chat_list'); if (d.success) { chatUsers = d.rows; chatRenderList(); } }
    window.chatRenderList = () => {
        const q = ($('chatSearch').value || '').toLowerCase();
        $('chatUsers').innerHTML = chatUsers.filter(u => u.username.toLowerCase().includes(q)).map(u =>
            '<button type="button" onclick="chatOpen(' + u.id + ',\'' + esc(u.username).replace(/'/g, '') + '\')" class="w-full text-left bg-[#040711] border border-cyber-border hover:border-cyan-500/40 rounded-xl p-3 flex items-center justify-between gap-2">'
            + '<span class="min-w-0"><b class="text-white text-xs font-sans block">' + esc(u.username) + '</b><span class="text-[10px] text-gray-500 block truncate">' + esc(u.last_body || 'No messages yet') + '</span></span>'
            + (parseInt(u.unread) > 0 ? '<span class="px-2 py-0.5 rounded-full bg-rose-500 text-white text-[10px] font-bold">' + u.unread + '</span>' : '') + '</button>').join('');
    };
    window.chatOpen = async (id, name) => {
        chatUid = id; chatLast = 0; $('chatWith').textContent = name; $('chatMsgs').innerHTML = '';
        $('chatListBox').classList.add('hidden'); $('chatThreadBox').classList.remove('hidden');
        await chatPoll(); clearInterval(chatTimer); chatTimer = setInterval(chatPoll, 4000);
    };
    window.chatBack = () => { clearInterval(chatTimer); chatUid = 0; $('chatThreadBox').classList.add('hidden'); $('chatListBox').classList.remove('hidden'); chatLoadList(); };
    async function chatPoll() {
        if (!chatUid) return;
        const d = await api('chat_fetch', { user_id: chatUid, after_id: chatLast }); if (!d.success || !d.rows.length) return;
        const box = $('chatMsgs');
        d.rows.forEach(m => {
            chatLast = Math.max(chatLast, parseInt(m.id));
            const me = m.sender_role === 'admin';
            box.insertAdjacentHTML('beforeend', '<div class="flex ' + (me ? 'justify-end' : 'justify-start') + '"><div class="max-w-[80%] px-3 py-2 rounded-2xl text-xs ' + (me ? 'bg-cyan-500/20 text-cyan-100 border border-cyan-500/30' : 'bg-[#0c1326] text-gray-200 border border-cyber-border') + '"><div class="whitespace-pre-wrap break-words">' + esc(m.body) + '</div><div class="text-[9px] text-gray-500 mt-1">' + esc(String(m.created_at).slice(5, 16)) + '</div></div></div>');
        });
        box.scrollTop = box.scrollHeight;
    }
    window.chatSend = async (e) => {
        e.preventDefault(); const inp = $('chatInput'), body = inp.value.trim(); if (!body || !chatUid) return;
        inp.value = ''; const d = await api('chat_send', { user_id: chatUid, body }); if (d.success) chatPoll();
    };

    // tab open hook (switchAdminTab theke call hoy)
    window.onAdminTabShow = (id) => {
        if (id === 'tab-deposits') { dpMark(); dpLoad(); }
        if (id === 'tab-reports') rpTab(rpCur);
        if (id === 'tab-chat') chatLoadList();
    };
    document.addEventListener('DOMContentLoaded', () => {
        dpMark(); document.querySelectorAll('.rp-btn')[0] && document.getElementById('rp-btn-sales').classList.add('active');
        // refresh e jei tab chhilo oi tab er data load
        const act = (function () { try { return sessionStorage.getItem('admin_active_tab'); } catch (e) { return null; } })();
        if (act) setTimeout(() => window.onAdminTabShow(act), 50);
        // chat unread badge (drawer button)
        const pollUnread = async () => { const d = await api('chat_unread_total'); const b = document.getElementById('chatUnreadBadge'); if (b && d.success) { b.textContent = d.unread; b.classList.toggle('hidden', !(d.unread > 0)); } };
        pollUnread(); setInterval(pollUnread, 30000);
    });
})();
</script>
