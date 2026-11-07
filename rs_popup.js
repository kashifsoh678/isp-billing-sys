/**
 * Custom HTML popups — drop-in for the old CDN dialog lib.
 * Same call shape everywhere: Swal.fire(opts | title, text, icon) -> Promise({isConfirmed, value, ...})
 * Also: Swal.close(), Swal.showLoading(), Swal.showValidationMessage()
 */
(function () {
    'use strict';

    const ICONS = {
        success: 'fa-circle-check',
        error: 'fa-circle-xmark',
        warning: 'fa-triangle-exclamation',
        info: 'fa-circle-info',
        question: 'fa-circle-question'
    };
    const TEXTY = ['text', 'email', 'password', 'number', 'tel', 'url', 'date', 'time', 'datetime-local', 'search', 'range', 'color'];
    const esc = s => String(s == null ? '' : s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

    let cur = null;   // only one popup lives at a time, same as before

    function settle(result, now) {
        if (!cur) return;
        const c = cur; cur = null;
        clearTimeout(c.timer);
        document.removeEventListener('keydown', c.onKey, true);
        c.root.classList.add('rsp-out');
        c.root.style.pointerEvents = 'none';        // a fading popup must not catch clicks meant for the next one
        const drop = () => { c.root.remove(); if (!document.querySelector('.rsp-overlay')) document.body.classList.remove('rsp-open'); };
        if (now) drop(); else setTimeout(drop, 140);
        try { c.lastFocus && c.lastFocus.focus && c.lastFocus.focus(); } catch (e) { }
        c.resolve(result);
    }
    const dismissed = why => ({ isConfirmed: false, isDenied: false, isDismissed: true, dismiss: why });

    function inputEl(o) {
        const t = o.input;
        if (!t) return null;
        let el;
        if (t === 'textarea') { el = document.createElement('textarea'); el.rows = 4; }
        else if (t === 'select') {
            el = document.createElement('select');
            const opts = o.inputOptions || {};
            if (o.inputPlaceholder) el.appendChild(new Option(o.inputPlaceholder, ''));
            (opts instanceof Map ? [...opts] : Object.entries(opts)).forEach(([v, l]) => el.appendChild(new Option(l, v)));
        } else if (t === 'checkbox') { el = document.createElement('input'); el.type = 'checkbox'; }
        else { el = document.createElement('input'); el.type = TEXTY.indexOf(t) >= 0 ? t : (t === 'file' ? 'file' : 'text'); }
        el.className = 'rsp-input swal2-input';
        if (o.inputPlaceholder && t !== 'select') el.placeholder = o.inputPlaceholder;
        if (o.inputValue != null) el.value = o.inputValue;
        Object.entries(o.inputAttributes || {}).forEach(([k, v]) => el.setAttribute(k, v));
        return el;
    }
    const valueOf = el => !el ? undefined
        : el.type === 'checkbox' ? (el.checked ? 1 : 0)
            : el.type === 'file' ? (el.files && el.files[0]) || null
                : el.value;

    function build(o, resolve) {
        const root = document.createElement('div');
        root.className = 'rsp-overlay' + (o.toast ? ' rsp-toast ' + ('rsp-' + (o.position || 'top-end')) : '');
        const card = document.createElement('div');
        card.className = 'rsp' + (o.icon ? ' rsp-has-icon' : '');
        card.setAttribute('role', 'dialog');
        card.setAttribute('aria-modal', 'true');
        if (o.width) card.style.maxWidth = typeof o.width === 'number' ? o.width + 'px' : o.width;

        let h = '';
        if (o.icon) h += `<div class="rsp-icon rsp-${esc(o.icon)}"><i class="fas ${ICONS[o.icon] || ICONS.info}"></i></div>`;
        h += '<div class="rsp-body">';
        if (o.title) h += `<h2 class="rsp-title swal2-title">${o.title}</h2>`;
        if (o.html) h += `<div class="rsp-text swal2-html-container">${o.html}</div>`;
        else if (o.text) h += `<div class="rsp-text swal2-html-container">${esc(o.text)}</div>`;
        if (o.inputLabel) h += `<label class="rsp-input-label">${esc(o.inputLabel)}</label>`;
        h += '<div class="rsp-input-slot"></div><div class="rsp-valid" hidden></div>';
        h += '<div class="rsp-loader" hidden><i class="fas fa-spinner fa-spin"></i></div>';
        h += '<div class="rsp-actions"></div>';
        if (o.footer) h += `<div class="rsp-footer">${o.footer}</div>`;
        h += '</div>';
        card.innerHTML = h;

        const slot = card.querySelector('.rsp-input-slot'), field = inputEl(o);
        if (field) slot.appendChild(field); else slot.remove();

        // actions — confirm is on by default, everything else opt-in (same defaults as before)
        const acts = card.querySelector('.rsp-actions');
        const mine = () => cur && cur.card === card;
        const mk = (cls, label, fn) => {
            const b = document.createElement('button');
            b.type = 'button'; b.className = 'btn ' + cls; b.innerHTML = label;
            b.addEventListener('click', () => { if (mine()) fn(); });
            acts.appendChild(b);
            return b;
        };
        const showConfirm = o.showConfirmButton !== false;
        let confirmBtn = null;
        if (showConfirm) {
            confirmBtn = mk('btn-primary rsp-confirm', o.confirmButtonText || 'OK', doConfirm);
            if (o.confirmButtonColor) confirmBtn.style.background = o.confirmButtonColor;
        }
        if (o.showDenyButton) mk('btn-warning rsp-deny', o.denyButtonText || 'No',
            () => settle({ isConfirmed: false, isDenied: true, isDismissed: false }));
        if (o.showCancelButton) mk('btn-secondary rsp-cancel', o.cancelButtonText || 'Cancel',
            () => settle(dismissed('cancel')));
        if (o.reverseButtons) [...acts.children].reverse().forEach(b => acts.appendChild(b));
        if (!acts.children.length) acts.remove();

        root.appendChild(card);
        root.addEventListener('mousedown', e => {
            if (mine() && e.target === root && o.allowOutsideClick !== false && !o.toast) settle(dismissed('backdrop'));
        });
        document.body.appendChild(root);
        if (!o.toast) document.body.classList.add('rsp-open');

        const onKey = e => {
            if (!mine()) return;
            if (e.key === 'Escape' && o.allowEscapeKey !== false) { e.preventDefault(); settle(dismissed('esc')); }
            if (e.key === 'Enter' && field && field.tagName !== 'TEXTAREA' && document.activeElement === field) {
                e.preventDefault(); doConfirm();
            }
        };
        document.addEventListener('keydown', onKey, true);

        cur = {
            root, card, opts: o, resolve, onKey, field, timer: null, validationShown: false,
            lastFocus: document.activeElement
        };

        requestAnimationFrame(() => {
            root.classList.add('rsp-in');
            (field || confirmBtn || card).focus && (field || confirmBtn || card).focus();
        });
        if (typeof o.didOpen === 'function') { try { o.didOpen(card); } catch (e) { console.error(e); } }
        if (o.timer) cur.timer = setTimeout(() => settle(dismissed('timer')), o.timer);

        async function doConfirm() {
            if (!mine()) return;
            const val = valueOf(field);
            if (o.inputValidator) {
                const msg = await o.inputValidator(val);
                if (msg) return showValidationMessage(msg);
            }
            if (o.preConfirm) {
                cur.validationShown = false;
                showLoading();
                let res;
                try { res = await o.preConfirm(val); }
                catch (err) { hideLoading(); return showValidationMessage((err && err.message) || String(err)); }
                hideLoading();
                if (!cur || cur.validationShown || res === false) return;
                settle({ isConfirmed: true, isDenied: false, isDismissed: false, value: res === undefined ? (val === undefined ? true : val) : res });
                return;
            }
            settle({ isConfirmed: true, isDenied: false, isDismissed: false, value: val === undefined ? true : val });
        }
    }

    function showLoading() {
        if (!cur) return;
        const l = cur.card.querySelector('.rsp-loader'), a = cur.card.querySelector('.rsp-actions');
        if (l) l.hidden = false;
        if (a) a.hidden = true;
        if (cur.field) cur.field.disabled = true;
    }
    function hideLoading() {
        if (!cur) return;
        const l = cur.card.querySelector('.rsp-loader'), a = cur.card.querySelector('.rsp-actions');
        if (l) l.hidden = true;
        if (a) a.hidden = false;
        if (cur.field) cur.field.disabled = false;
    }
    function showValidationMessage(msg) {
        if (!cur) return;
        const v = cur.card.querySelector('.rsp-valid');
        v.innerHTML = '<i class="fas fa-circle-exclamation"></i> ' + esc(msg);
        v.hidden = false;
        cur.validationShown = true;
        hideLoading();
    }

    window.Swal = window.rsPopup = {
        fire(a, b, c) {
            const o = (typeof a === 'string' || a == null) ? { title: a, text: b, icon: c } : a;
            if (cur) settle(dismissed('close'), true);
            document.querySelectorAll('.rsp-overlay').forEach(n => n.remove());   // nothing stale under the new one
            return new Promise(resolve => build(o || {}, resolve));
        },
        close: () => settle(dismissed('close')),
        showLoading, hideLoading, showValidationMessage,
        resetValidationMessage: () => { if (cur) { const v = cur.card.querySelector('.rsp-valid'); v.hidden = true; cur.validationShown = false; } },
        isVisible: () => !!cur,
        getPopup: () => cur && cur.card,
        getInput: () => cur && cur.field,
        getHtmlContainer: () => cur && cur.card.querySelector('.rsp-text'),
        update() { },
        mixin(base) { return { fire: (a, b, c) => window.Swal.fire(Object.assign({}, base, (typeof a === 'string' ? { title: a, text: b, icon: c } : a))) }; }
    };
})();
