/**
 * Shared page UI — loaded on every page from sidebar.php
 *   1. page action buttons (Refresh / Import / Add ...) ride in the header beside the bell
 *   2. add + edit modals go 90% wide: form left, live preview right
 */
(function () {
    'use strict';

    // hide the toolbar where it sits until we lift it — no jump on load
    document.documentElement.classList.add('ui-lift');

    // ── 1. page actions -> header ──────────────────────────────────────────
    function liftActions() {
        const header = document.querySelector('.main-content .header');
        if (!header) return;
        let tools = header.querySelector('.header-tools');
        if (!tools) {                                   // dashboard already ships one
            const bell = header.querySelector('.header-right');
            tools = document.createElement('div');
            tools.className = 'header-tools';
            header.appendChild(tools);
            if (bell) tools.appendChild(bell);
        }
        // the page's own toolbar = first section-header group outside any modal
        const group = Array.prototype.find.call(
            document.querySelectorAll('.section-header > .btn-group-inline'),
            g => !g.closest('.modal-overlay'));
        if (!group) return;
        group.classList.add('hdr-actions');
        tools.insertBefore(group, tools.querySelector('.header-right')); // bell stays last
    }

    // ── 2. wide add/edit modals + live preview ─────────────────────────────
    const MIN_FIELDS = 6;                               // 2-3 field popups stay compact
    const esc = s => String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
    const box = el => el.closest('.form-group, .toggle-row');
    const seen = el => { const g = box(el); return !!(g && (g.offsetWidth || g.offsetHeight || g.getClientRects().length)); };

    const labelOf = el => {
        const g = box(el), l = g && g.querySelector('label');
        return ((l ? l.textContent : '') || el.placeholder || el.name || '').replace(/\*/g, '').trim();
    };
    const valueOf = el => {
        if (el.type === 'checkbox') return el.checked ? 'Yes' : 'No';
        if (el.type === 'file') return el.files && el.files[0] ? el.files[0].name : '';
        if (el.tagName === 'SELECT') { const o = el.selectedOptions[0]; return o && o.value !== '' ? o.textContent.trim() : ''; }
        return (el.value || '').trim();
    };

    // one ordered pass — section titles carry the grouping, fields fall under the last one seen
    function collect(form) {
        const rows = [], done = {};
        let group = '', missing = 0;
        form.querySelectorAll('.form-section-title, input, select, textarea').forEach(el => {
            if (el.classList.contains('form-section-title')) { group = el.textContent.trim(); return; }
            if (el.type === 'hidden' || el.disabled || !box(el) || !seen(el)) return;
            if (el.type === 'radio') { if (!el.checked || done[el.name]) return; done[el.name] = 1; }
            const label = labelOf(el);
            if (!label) return;
            const v = valueOf(el), miss = !!el.required && !v && el.type !== 'checkbox';
            if (miss) missing++;
            rows.push({ g: group, l: label, v: v, m: miss });
        });
        return { rows: rows, missing: missing };
    }

    // headline of the card — the record as the user is typing it
    const IDLIKE = /code|number|\bno\b|\bid\b/i;
    const NOTNAME = /user\s*name|username|login|password/i;
    function identity(rows) {
        const pick = re => (rows.find(r => r.v && re.test(r.l) && !IDLIKE.test(r.l) && !NOTNAME.test(r.l)) || {}).v || '';
        const code = (rows.find(r => r.v && /code|number|\bno\b|invoice|reference|serial/i.test(r.l)) || {}).v || '';
        const name = pick(/^\s*(full|customer|company|contact|staff|vendor|item|product|package|area|role|lead|ticket)?\s*name\s*$/i)
            || pick(/name|title|subject/i)
            || pick(/customer|client|party|supplier|vendor|employee|member/i)   // invoice/payment = whose it is
            || code
            || (rows.find(r => r.v && !IDLIKE.test(r.l)) || {}).v || '';
        return { name: name, code: code === name ? '' : code };
    }

    function paint(modal) {
        const form = modal.querySelector('form'), card = modal.querySelector('.ms-card');
        if (!form || !card) return;
        const d = collect(form), sig = JSON.stringify(d);
        if (card.dataset.sig === sig) return;           // nothing changed, skip the repaint
        card.dataset.sig = sig;
        const id = identity(d.rows);
        let h = '<div class="ms-id"><span class="ms-avatar">' + esc((id.name.trim()[0] || '?').toUpperCase()) +
            '</span><div><div class="ms-name">' + esc(id.name || 'New record') +
            '</div><div class="ms-sub">' + esc(id.code || 'Not saved yet') + '</div></div></div>';
        h += d.missing
            ? '<div class="ms-flag ms-flag-warn"><i class="fas fa-triangle-exclamation"></i> ' + d.missing +
            ' required field' + (d.missing > 1 ? 's' : '') + ' left</div>'
            : '<div class="ms-flag ms-flag-ok"><i class="fas fa-circle-check"></i> All required fields filled</div>';
        const show = d.rows.filter(r => (r.v && r.v !== id.name && r.v !== id.code) || r.m),
            blank = d.rows.filter(r => !r.v && !r.m).length;
        let g = null;
        show.forEach(r => {
            if (r.g !== g) { g = r.g; if (g) h += '<div class="ms-group">' + esc(g) + '</div>'; }
            h += '<div class="ms-row' + (r.m ? ' ms-miss' : '') + '"><span class="ms-k">' + esc(r.l) +
                '</span><span class="ms-v">' + (r.v ? esc(r.v) : '<i class="ms-empty">—</i>') + '</span></div>';
        });
        if (!show.length) h += '<div class="ms-none"><i class="fas fa-pen-to-square"></i> Start filling the form — it shows up here</div>';
        if (blank) h += '<div class="ms-rest">' + blank + ' optional field' + (blank > 1 ? 's' : '') + ' still empty</div>';
        card.innerHTML = h;
    }

    function splitModals() {
        document.querySelectorAll('.modal-overlay .modal').forEach(modal => {
            if (modal.dataset.msInit) return;
            const body = modal.querySelector('.modal-body'), form = body && body.querySelector('form');
            if (!form) return;
            if (form.querySelectorAll('.form-group input:not([type=hidden]), .form-group select, .form-group textarea, .toggle-row input').length < MIN_FIELDS) return;
            modal.dataset.msInit = '1';
            modal.classList.add('modal-split');
            const pane = document.createElement('div');
            pane.className = 'ms-form';
            while (body.firstChild) pane.appendChild(body.firstChild);
            body.appendChild(pane);
            body.insertAdjacentHTML('beforeend',
                '<aside class="ms-preview"><div class="ms-head"><i class="fas fa-eye"></i> Live Preview</div><div class="ms-card"></div></aside>');
            form.addEventListener('input', () => paint(modal));
            form.addEventListener('change', () => paint(modal));
        });
    }

    // ── 3. detail popups: field grids render as real spec tables ───────────
    function gridToTable(grid) {
        const rows = Array.prototype.map.call(grid.querySelectorAll('.vtab-field'), f => {
            const l = f.querySelector('label'), c = f.cloneNode(true), cl = c.querySelector('label');
            if (cl) cl.remove();
            return { k: l ? l.textContent.trim() : '', v: c.innerHTML.trim() };
        }).filter(r => r.k);
        if (!rows.length) return;
        const wrap = document.createElement('div');
        wrap.className = 'about-table-wrapper spec-wrap';
        wrap.innerHTML = '<table class="spec-table"><thead><tr><th>Field</th><th>Value</th></tr></thead><tbody>' +
            rows.map(r => '<tr><td class="spec-k">' + esc(r.k) + '</td><td class="spec-v">' +
                (r.v || '<span class="muted">—</span>') + '</td></tr>').join('') +
            '</tbody></table>';
        grid.replaceWith(wrap);
    }

    // panes are re-rendered per record, so watch each popup instead of converting once
    function watchGrids() {
        const sweep = n => {
            if (n.nodeType !== 1) return;
            if (n.classList.contains('vtab-grid')) gridToTable(n);
            else n.querySelectorAll && n.querySelectorAll('.vtab-grid').forEach(gridToTable);
        };
        document.querySelectorAll('.modal-overlay').forEach(ov => {
            sweep(ov);
            new MutationObserver(ms => ms.forEach(m => m.addedNodes.forEach(sweep)))
                .observe(ov, { childList: true, subtree: true });
        });
    }

    // edit modals fill their fields from JS, which fires no input event — cheap tick keeps the card true
    setInterval(function () {
        const ov = document.querySelector('.modal-overlay.active');
        if (!ov) return;
        const modal = ov.querySelector('.modal');
        if (!modal) return;
        if (modal.dataset.msInit) paint(modal); else splitModals();
    }, 400);

    // ── 4. thermal receipts — ONE builder, every page hands it the same shape ──
    // rsThermalPrint({ title, meta:[[k,v]], items:[{name,qty,unit,price,total}], sums:[[k,v,'grand']], note, footer })
    window.rsThermalPrint = function (bill) {
        const co = window.RS_COMPANY || {};
        const css = (window.RS_ASSETS && window.RS_ASSETS.thermal) || 'thermal.css';
        const row = (k, v, cls) => '<tr class="' + (cls || '') + '"><td>' + esc(k) + '</td><td class="r">' + esc(v) + '</td></tr>';
        const head = '<div class="t-head"><div class="t-brand">' + esc(co.name || '') + '</div>' +
            (co.address ? '<div class="t-sub">' + esc(co.address) + '</div>' : '') +
            (co.phone ? '<div class="t-sub">' + esc(co.phone) + '</div>' : '') +
            (bill.title ? '<div class="t-sub"><b>' + esc(bill.title) + '</b></div>' : '') + '</div>';
        const meta = '<div class="t-meta">' + (bill.meta || []).filter(m => m && m[1])
            .map(m => '<div><span>' + esc(m[0]) + '</span><b>' + esc(m[1]) + '</b></div>').join('') + '</div>';
        const items = (bill.items || []).length
            ? '<table class="t-items">' + bill.items.map(it =>
                '<tr><td colspan="2">' + esc(it.name) + '</td></tr>' +
                '<tr><td>' + esc(it.qty) + (it.unit ? ' ' + esc(it.unit) : '') + ' x ' + esc(it.price) +
                '</td><td class="r">' + esc(it.total) + '</td></tr>').join('') + '</table>'
            : '';
        const sums = '<table class="t-sum">' + (bill.sums || []).filter(x => x).map(x => row(x[0], x[1], x[2])).join('') + '</table>';
        const note = bill.note ? '<div class="t-void">' + esc(bill.note) + '</div>' : '';
        const foot = '<div class="t-foot">' + (bill.footer || 'Thank you for your business') + '</div>';

        let frame = document.getElementById('rsThermalFrame');
        if (frame) frame.remove();
        frame = document.createElement('iframe');
        frame.id = 'rsThermalFrame';
        frame.className = 'print-frame';
        document.body.appendChild(frame);
        frame.onload = () => setTimeout(() => { frame.contentWindow.focus(); frame.contentWindow.print(); }, 250);
        frame.srcdoc = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' + esc(bill.title || 'Receipt') +
            '</title><link rel="stylesheet" href="' + css + '"></head><body>' +
            head + meta + items + sums + note + foot + '</body></html>';
    };

    // ── 4. CSV import + template — one implementation, every list page ─────
    const csvEsc = s => String(s == null ? '' : s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
    const csvCfg = { entity: 'Record', url: '', onDone: null };
    let csvInput = null;

    function csvPicker() {
        if (csvInput) return csvInput;
        csvInput = document.createElement('input');
        csvInput.type = 'file';
        csvInput.accept = '.csv,text/csv';
        csvInput.className = 'rs-csv-input';
        csvInput.addEventListener('change', function () {
            const f = this.files && this.files[0];
            this.value = '';                       // picking the same file twice must still fire
            if (f) csvConfirm(f);
        });
        document.body.appendChild(csvInput);
        return csvInput;
    }

    function csvConfirm(file) {
        const go = () => csvUpload(file);
        if (!window.Swal) { if (confirm('Import ' + file.name + '?')) go(); return; }
        Swal.fire({
            icon: 'question',
            title: 'Import ' + csvCfg.entity + 's?',
            html: '<b>' + csvEsc(file.name) + '</b><br>Every row is checked first — bad rows are listed and skipped, never half-saved.',
            showCancelButton: true, confirmButtonText: 'Import'
        }).then(r => { if (r.isConfirmed) go(); });
    }

    function csvUpload(file) {
        const fd = new FormData();
        fd.append('file', file);
        if (window.Swal) Swal.fire({ title: 'Importing...', text: file.name, allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        fetch((csvCfg.url || location.pathname) + '?action=importCsv', {
            method: 'POST', body: fd, credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(r => r.json())
            .then(csvResult)
            .catch(() => csvFail('Upload failed — check the connection and try again.'));
    }

    function csvFail(msg) {
        if (window.Swal) Swal.fire({ icon: 'error', title: 'Import failed', text: msg });
        else alert(msg);
    }

    function csvResult(r) {
        if (!r || !r.success) { csvFail((r && r.message) || 'Import failed'); return; }
        const errs = r.errors || [];
        let html = '<b>' + (r.imported || 0) + '</b> imported';
        if (r.skipped) html += ' &middot; <b>' + r.skipped + '</b> skipped';
        if (errs.length) {
            html += '<div class="csv-err-list">' + errs.slice(0, 10).map(e => '<div>' + csvEsc(e) + '</div>').join('') +
                (errs.length > 10 ? '<div>...and ' + (errs.length - 10) + ' more</div>' : '') + '</div>';
        }
        if (window.Swal) {
            Swal.fire({
                icon: r.imported ? (r.skipped ? 'warning' : 'success') : 'error',
                title: r.imported ? 'Import done' : 'Nothing imported', html: html
            });
        } else {
            alert((r.imported || 0) + ' imported, ' + (r.skipped || 0) + ' skipped');
        }
        if (typeof csvCfg.onDone === 'function') csvCfg.onDone(r);
    }

    window.rsImport = {
        init: cfg => Object.assign(csvCfg, cfg || {}),
        pick: () => csvPicker().click(),
        template: () => { window.location.href = (csvCfg.url || location.pathname) + '?action=importTemplate'; }
    };

    document.addEventListener('DOMContentLoaded', function () {
        try { liftActions(); splitModals(); watchGrids(); }
        finally { document.documentElement.classList.remove('ui-lift'); }
    });
})();
