/**
 * Developed by Kashif  Scripts
 * WhatsApp: https://wa.me/923071344399 (For Custom Projects)
 * YouTube: https://www.youtube.com/@kashifsoh678 (Subscribe for more!)
 *
 * SearchableDropdown — vanilla JS, single drop-in.
 * Wraps a hidden <select> with a searchable trigger + panel.
 * Tokens match docs/searchable-dropdown.md (border-radius: 3px, border: 2px solid #e0e0e0).
 *
 * Usage:
 *   new SearchableDropdown({ selectId: 'role', placeholder: 'Select role...', icon: 'fas fa-user-tag' });
 * Or on multiple selects:
 *   SearchableDropdown.initAll('select.searchable');
 */
(function () {
    // inject CSS once — cleaner polish, preserves mandated tokens (3px radius, 2px border, 14px font, .3s transition)
    if (!document.getElementById('sd-styles')) {
        const css = `
.sd-wrap { position:relative; width:100%; font-size:14px; }

/* Trigger — matches .filter-input visual rhythm */
.sd-trigger {
    display:flex; align-items:center; gap:10px;
    width:100%; min-height:44px;
    padding:10px 38px 10px 14px;
    border:2px solid #e0e0e0; border-radius:3px;
    background:#fff; color:#001f3f;
    font-size:14px; line-height:1.4;
    cursor:pointer; user-select:none;
    transition:all .3s ease;
    position:relative; box-sizing:border-box;
}
.sd-trigger > i { color:#0074D9; font-size:14px; flex-shrink:0; }
.sd-trigger:hover { border-color:#bcd4ec; background:#fafcff; }
.sd-trigger.sd-open {
    border-color:#0074D9; background:#fff;
    box-shadow:0 0 0 3px rgba(0,116,217,.15);
}
.sd-trigger .sd-label {
    flex:1; min-width:0;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
    font-weight:500;
}
.sd-trigger .sd-placeholder { color:#94a3b8; font-weight:400; }
.sd-trigger .sd-arrow {
    position:absolute; right:14px; top:50%;
    transform:translateY(-50%);
    color:#94a3b8; font-size:11px;
    transition:transform .3s ease, color .3s ease;
    pointer-events:none;
}
.sd-trigger:hover .sd-arrow { color:#0074D9; }
.sd-trigger.sd-open .sd-arrow { transform:translateY(-50%) rotate(180deg); color:#0074D9; }

/* Panel — smoother shadow + entrance */
.sd-panel {
    position:absolute; top:calc(100% + 6px); left:0; right:0;
    background:#fff;
    border:2px solid #0074D9; border-radius:3px;
    box-shadow:0 12px 32px rgba(0,31,63,.18), 0 4px 12px rgba(0,31,63,.08);
    z-index:1000;
    max-height:300px;
    display:flex; flex-direction:column;
    overflow:hidden;
    animation:sd-pop .15s ease-out;
}
@keyframes sd-pop {
    from { opacity:0; transform:translateY(-4px); }
    to   { opacity:1; transform:translateY(0); }
}

/* Search input */
.sd-search { padding:10px; background:#fafbfc; border-bottom:1px solid #e8ecef; position:relative; }
.sd-search::before {
    content:"\f002"; font-family:"Font Awesome 6 Free"; font-weight:900;
    position:absolute; left:22px; top:50%; transform:translateY(-50%);
    color:#94a3b8; font-size:12px; pointer-events:none; z-index:1;
}
.sd-search input {
    width:100%; padding:9px 12px 9px 32px;
    border:1.5px solid #e0e0e0; border-radius:3px;
    font-size:14px; line-height:1.4; color:#001f3f;
    outline:none; box-sizing:border-box;
    background:#fff; transition:all .3s ease;
}
.sd-search input::placeholder { color:#94a3b8; }
.sd-search input:focus { border-color:#0074D9; box-shadow:0 0 0 2px rgba(0,116,217,.15); }

/* List */
.sd-list { overflow-y:auto; max-height:236px; padding:4px 0; }
.sd-list::-webkit-scrollbar { width:6px; }
.sd-list::-webkit-scrollbar-thumb { background:#cbd5e1; border-radius:3px; }
.sd-list::-webkit-scrollbar-thumb:hover { background:#94a3b8; }

/* Items */
.sd-item {
    padding:10px 14px;
    cursor:pointer; font-size:14px; line-height:1.4; color:#001f3f;
    transition:background .15s ease, color .15s ease, padding-left .15s ease;
    border-left:3px solid transparent;
    position:relative;
}
.sd-item:hover { background:#f0f7ff; color:#0074D9; border-left-color:#0074D9; padding-left:18px; }
.sd-item.sd-selected {
    background:#0074D9; color:#fff; font-weight:600;
    border-left-color:#001f3f;
}
.sd-item.sd-selected:hover { background:#0066c0; color:#fff; }
.sd-item.sd-selected::after {
    content:"\f00c"; font-family:"Font Awesome 6 Free"; font-weight:900;
    position:absolute; right:14px; top:50%; transform:translateY(-50%);
    font-size:11px; opacity:.85;
}
.sd-item.sd-empty {
    color:#94a3b8; font-style:italic; cursor:default; text-align:center;
    padding:18px 12px; border-left-color:transparent;
}
.sd-item.sd-empty:hover { background:transparent; color:#94a3b8; padding-left:14px; border-left-color:transparent; }

/* Dark mode */
body.dark-mode .sd-trigger { background:#1e293b; border-color:#334155; color:#f1f5f9; }
body.dark-mode .sd-trigger:hover { background:#243245; border-color:#475569; }
body.dark-mode .sd-trigger.sd-open { background:#1e293b; border-color:#0074D9; }
body.dark-mode .sd-trigger .sd-placeholder { color:#94a3b8; }
body.dark-mode .sd-panel { background:#1e293b; border-color:#0074D9; box-shadow:0 12px 32px rgba(0,0,0,.5); }
body.dark-mode .sd-search { background:#0f172a; border-bottom-color:#334155; }
body.dark-mode .sd-search input { background:#0f172a; border-color:#334155; color:#f1f5f9; }
body.dark-mode .sd-search input::placeholder { color:#64748b; }
body.dark-mode .sd-list::-webkit-scrollbar-thumb { background:#475569; }
body.dark-mode .sd-item { color:#f1f5f9; }
body.dark-mode .sd-item:hover { background:#0f172a; color:#0074D9; }
body.dark-mode .sd-item.sd-selected { background:#0074D9; color:#fff; }
body.dark-mode .sd-item.sd-empty { color:#64748b; }
        `;
        const styleEl = document.createElement('style');
        styleEl.id = 'sd-styles';
        styleEl.textContent = css;
        document.head.appendChild(styleEl);
    }

    function SearchableDropdown(opts) {
        const select = typeof opts.selectId === 'string'
            ? document.getElementById(opts.selectId)
            : opts.selectId;
        if (!select || select.tagName !== 'SELECT') {
            console.warn('SearchableDropdown: select not found', opts.selectId);
            return;
        }
        if (select.dataset.sdInit) return; // already initialized
        select.dataset.sdInit = '1';

        const placeholder = opts.placeholder || 'Select...';
        const icon = opts.icon || '';
        const allowClear = opts.allowClear !== false;

        // hide original
        select.style.display = 'none';

        // wrapper
        const wrap = document.createElement('div');
        wrap.className = 'sd-wrap';
        select.parentNode.insertBefore(wrap, select.nextSibling);

        // trigger
        const trigger = document.createElement('div');
        trigger.className = 'sd-trigger';
        trigger.innerHTML = `${icon ? `<i class="${icon}"></i>` : ''}<span class="sd-label sd-placeholder">${placeholder}</span><span class="sd-arrow"><i class="fas fa-chevron-down"></i></span>`;
        wrap.appendChild(trigger);

        // panel
        const panel = document.createElement('div');
        panel.className = 'sd-panel';
        panel.style.display = 'none';
        panel.innerHTML = `
            <div class="sd-search"><input type="text" placeholder="Search..." autocomplete="off"></div>
            <div class="sd-list"></div>
        `;
        wrap.appendChild(panel);

        const labelSpan = trigger.querySelector('.sd-label');
        const list = panel.querySelector('.sd-list');
        const searchInput = panel.querySelector('input');

        function getOptions() {
            return Array.from(select.options).map(o => ({
                value: o.value,
                label: o.textContent.trim(),
                disabled: o.disabled
            }));
        }

        function renderList(filter) {
            const term = (filter || '').toLowerCase();
            const opts = getOptions();
            const filtered = opts.filter(o =>
                (allowClear && o.value === '') ? false : o.label.toLowerCase().includes(term)
            );
            list.innerHTML = '';
            if (allowClear) {
                const clear = document.createElement('div');
                clear.className = 'sd-item' + (select.value === '' ? ' sd-selected' : '');
                clear.textContent = placeholder;
                clear.onclick = () => choose('');
                list.appendChild(clear);
            }
            if (filtered.length === 0) {
                const empty = document.createElement('div');
                empty.className = 'sd-item sd-empty';
                empty.textContent = 'No results found';
                list.appendChild(empty);
                return;
            }
            filtered.forEach(o => {
                const item = document.createElement('div');
                item.className = 'sd-item' + (o.value === select.value ? ' sd-selected' : '');
                item.textContent = o.label;
                if (o.disabled) {
                    item.style.opacity = '0.5';
                    item.style.cursor = 'not-allowed';
                } else {
                    item.onclick = () => choose(o.value);
                }
                list.appendChild(item);
            });
        }

        function choose(value) {
            select.value = value;
            syncLabel();
            close();
            select.dispatchEvent(new Event('change', { bubbles: true }));
        }

        function syncLabel() {
            const opt = Array.from(select.options).find(o => o.value === select.value);
            const txt = opt ? opt.textContent.trim() : '';
            if (!txt || select.value === '') {
                labelSpan.textContent = placeholder;
                labelSpan.classList.add('sd-placeholder');
            } else {
                labelSpan.textContent = txt;
                labelSpan.classList.remove('sd-placeholder');
            }
        }

        function open() {
            panel.style.display = 'flex';
            trigger.classList.add('sd-open');
            searchInput.value = '';
            renderList('');
            setTimeout(() => searchInput.focus(), 30);
        }

        function close() {
            panel.style.display = 'none';
            trigger.classList.remove('sd-open');
        }

        trigger.addEventListener('click', e => {
            e.stopPropagation();
            if (panel.style.display === 'none') open(); else close();
        });

        searchInput.addEventListener('input', () => renderList(searchInput.value));
        searchInput.addEventListener('click', e => e.stopPropagation());

        document.addEventListener('click', e => {
            if (!wrap.contains(e.target)) close();
        });

        // re-sync when select changes externally (e.g., form reset, programmatic .value=)
        const observer = new MutationObserver(syncLabel);
        observer.observe(select, { childList: true, subtree: true, attributes: true });

        // public API
        select._sd = {
            refresh: () => { syncLabel(); if (panel.style.display !== 'none') renderList(searchInput.value); },
            destroy: () => { wrap.remove(); select.style.display = ''; delete select.dataset.sdInit; }
        };

        syncLabel();
    }

    SearchableDropdown.initAll = function (selector) {
        document.querySelectorAll(selector).forEach(el => {
            new SearchableDropdown({
                selectId: el,
                placeholder: el.dataset.placeholder || el.querySelector('option')?.textContent || 'Select...',
                icon: el.dataset.icon || ''
            });
        });
    };

    window.SearchableDropdown = SearchableDropdown;
})();

/**
 * Overflow action menu — delegated wiring.
 * Works for DataTables-rendered rows since listeners sit on document.
 * Idempotent: guarded by window flag so it can't double-bind.
 */
(function () {
    if (window.__actMoreBound) return; // already wired
    window.__actMoreBound = true;

    function closeAll(except) {
        document.querySelectorAll('.act-more.open').forEach(m => {
            if (m !== except) m.classList.remove('open');
        });
    }

    // click — toggle / outside-close
    document.addEventListener('click', e => {
        const btn = e.target.closest('.act-more-btn');
        if (btn) {
            e.preventDefault();
            const wrap = btn.closest('.act-more');
            if (!wrap) return;
            const wasOpen = wrap.classList.contains('open');
            closeAll(wrap);
            if (wasOpen) { wrap.classList.remove('open'); return; }
            wrap.classList.add('open');
            // flip up if menu would spill past viewport
            const menu = wrap.querySelector('.act-more-menu');
            if (menu) {
                menu.classList.remove('flip-up');
                const r = menu.getBoundingClientRect();
                if (r.bottom > window.innerHeight) menu.classList.add('flip-up');
            }
            return;
        }
        // clicked outside any act-more
        if (!e.target.closest('.act-more')) closeAll(null);
    });

    // esc — close all
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') closeAll(null);
    });
})();
