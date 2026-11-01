/* responsive.js — table gulo phone e card hoye dekhabe (th text => td label) */
(function () {
    function labelTable(t) {
        if (t.hasAttribute('data-no-cards')) return;
        var ths = t.querySelectorAll('thead th');
        if (ths.length < 2) return;
        var names = [];
        for (var i = 0; i < ths.length; i++) {
            var th = ths[i], span = th.colSpan || 1;
            var txt = (th.textContent || '').replace(/\s+/g, ' ').trim();
            for (var s = 0; s < span; s++) names.push(s === 0 ? txt : '');
        }
        t.classList.add('rcards');
        var rows = t.querySelectorAll('tbody tr');
        for (var r = 0; r < rows.length; r++) {
            var idx = 0, cells = rows[r].children;
            for (var c = 0; c < cells.length; c++) {
                var td = cells[c];
                if (td.tagName !== 'TD') continue;
                var cs = td.colSpan || 1;
                if (cs > 1) { td.classList.add('rc-span'); }
                else if (!td.hasAttribute('data-label')) { td.setAttribute('data-label', names[idx] || ''); }
                idx += cs;
            }
        }
    }
    function run() {
        var tables = document.querySelectorAll('table');
        for (var i = 0; i < tables.length; i++) labelTable(tables[i]);
    }
    var timer = null;
    function schedule() { clearTimeout(timer); timer = setTimeout(run, 60); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run); else run();
    // AJAX diye notun row ashle abar label bosao
    if (window.MutationObserver) {
        new MutationObserver(schedule).observe(document.documentElement, { childList: true, subtree: true });
    }
})();
