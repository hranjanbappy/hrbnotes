/* =========================================================================
   Hrb Notes - client application
   Vanilla JS. Handles theme, instant search, vault rescan, and the
   note workspace (load / render / edit / save / autosave / upload).
   ========================================================================= */
(function () {
    'use strict';

    const BASE = (document.querySelector('meta[name="base-url"]') || {}).content || '';
    const CSRF = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    const api = (route, params = {}) => {
        const q = new URLSearchParams(Object.assign({ route }, params));
        return BASE + '/?' + q.toString();
    };

    async function getJSON(url) {
        const r = await fetch(url, { headers: { 'Accept': 'application/json' } });
        if (!r.ok) throw await asError(r);
        return r.json();
    }
    async function postJSON(url, body) {
        const r = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-Token': CSRF,
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(body || {})
        });
        if (!r.ok) throw await asError(r);
        return r.json();
    }
    async function asError(r) {
        let msg = 'Request failed (' + r.status + ')';
        try { const j = await r.json(); if (j.message || j.error) msg = j.message || j.error; } catch (e) {}
        const e = new Error(msg); e.status = r.status; return e;
    }

    const escapeHtml = (s) => (s || '').replace(/[&<>"']/g, c => (
        { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
    ));

    /* ----------------------------------------------------------------------
       Theme toggle (persisted in a cookie read server-side too).
       ---------------------------------------------------------------------- */
    const themeToggle = document.getElementById('themeToggle');
    if (themeToggle) {
        themeToggle.addEventListener('click', () => {
            const html = document.documentElement;
            const next = html.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
            html.setAttribute('data-bs-theme', next);
            document.cookie = 'pkh_theme=' + next + ';path=/;max-age=' + (60 * 60 * 24 * 365);
        });
    }

    /* ----------------------------------------------------------------------
       Rescan vault.
       ---------------------------------------------------------------------- */
    function showStatus(elId, msg, hide) {
        const el = document.getElementById(elId);
        if (!el) return;
        el.textContent = msg;
        el.style.display = msg ? '' : 'none';
        if (hide) setTimeout(() => { el.style.display = 'none'; }, hide);
    }

    async function doRescan() {
        showStatus('rescanStatus', 'Rescanning\u2026');
        try {
            const res = await postJSON(api('rescan'), {});
            const s = res.stats || {};
            showStatus('rescanStatus',
                'Scanned ' + s.scanned + ' \u00b7 +' + s.added + ' \u223c' + s.updated + ' -' + s.removed,
                2500);
            setTimeout(() => location.reload(), 800);
        } catch (e) {
            showStatus('rescanStatus', 'Error: ' + e.message);
        }
    }
    ['btnRescan', 'btnRescan2'].forEach(id => {
        const b = document.getElementById(id);
        if (b) b.addEventListener('click', doRescan);
    });

    /* ----------------------------------------------------------------------
       Vault import (ZIP upload).
       ---------------------------------------------------------------------- */
    const vaultImportInput = document.getElementById('vaultImportInput');
    if (vaultImportInput) {
        vaultImportInput.addEventListener('change', async () => {
            const file = vaultImportInput.files[0];
            if (!file) return;
            showStatus('vaultActionStatus', 'Uploading ZIP\u2026 please wait');
            const fd = new FormData();
            fd.append('vault_zip', file);
            try {
                const r = await fetch(api('vault.import'), {
                    method: 'POST',
                    headers: { 'X-CSRF-Token': CSRF, 'X-Requested-With': 'XMLHttpRequest' },
                    body: fd
                });
                const j = await r.json();
                if (!r.ok) throw new Error(j.error || 'Import failed');
                const s = j.stats || {};
                showStatus('vaultActionStatus',
                    'Imported ' + j.extracted + ' files (' + j.skipped + ' skipped). Rescanned: '
                    + s.scanned + ' notes.');
                setTimeout(() => location.reload(), 1200);
            } catch (e) {
                showStatus('vaultActionStatus', 'Import error: ' + e.message);
            } finally {
                vaultImportInput.value = '';
            }
        });
    }

    /* ----------------------------------------------------------------------
       Danger Zone (Clear Vault / Clear Uploads)
       ---------------------------------------------------------------------- */
    const btnClearVault = document.getElementById('btnClearVault');
    if (btnClearVault) {
        btnClearVault.addEventListener('click', async () => {
            if (!confirm('WARNING: This will permanently delete ALL notes and folders inside the vault. This action CANNOT be undone.\n\nAre you absolutely sure you want to proceed?')) {
                return;
            }
            if (!confirm('DOUBLE CONFIRMATION: Type OK in your mind... Just kidding, are you really sure? Click OK to completely wipe the vault.')) {
                return;
            }
            try {
                btnClearVault.disabled = true;
                btnClearVault.textContent = 'Clearing Vault...';
                const res = await postJSON(api('vault.clear'), {});
                alert('Vault successfully cleared.');
                location.reload();
            } catch (e) {
                alert('Failed to clear vault: ' + e.message);
                btnClearVault.disabled = false;
                btnClearVault.textContent = 'Delete Entire Vault';
            }
        });
    }

    const btnClearUploads = document.getElementById('btnClearUploads');
    if (btnClearUploads) {
        btnClearUploads.addEventListener('click', async () => {
            if (!confirm('WARNING: This will permanently delete ALL uploaded images, PDFs, and files. References to these files in notes will break.\n\nAre you sure you want to proceed?')) {
                return;
            }
            try {
                btnClearUploads.disabled = true;
                btnClearUploads.textContent = 'Clearing Uploads...';
                const res = await postJSON(api('uploads.clear'), {});
                alert('Uploads successfully cleared.');
                location.reload();
            } catch (e) {
                alert('Failed to clear uploads: ' + e.message);
                btnClearUploads.disabled = false;
                btnClearUploads.textContent = 'Delete Uploads';
            }
        });
    }

    // Delete folder recursively (admins only)
    document.querySelectorAll('.btn-delete-folder').forEach(btn => {
        btn.addEventListener('click', async (e) => {
            e.preventDefault();
            e.stopPropagation();
            const folder = btn.getAttribute('data-folder');
            if (!confirm('Are you sure you want to permanently delete folder "' + folder + '" and all of its notes/folders? This action CANNOT be undone.')) {
                return;
            }
            try {
                btn.disabled = true;
                const res = await postJSON(api('vault.delete_folder'), { folder });
                alert('Folder deleted successfully.');
                location.reload();
            } catch (err) {
                alert('Failed to delete folder: ' + err.message);
                btn.disabled = false;
            }
        });
    });

    /* ----------------------------------------------------------------------
       Instant search dropdown in the navbar.
       ---------------------------------------------------------------------- */
    const gs = document.getElementById('globalSearch');
    const dd = document.getElementById('searchDropdown');
    if (gs && dd) {
        let t = null;
        const renderSnippet = (s) => escapeHtml(s || '')
            .replaceAll('@@HL@@', '<mark>').replaceAll('@@/HL@@', '</mark>');

        gs.addEventListener('input', () => {
            clearTimeout(t);
            const q = gs.value.trim();
            if (q.length < 2) { dd.classList.remove('show'); dd.innerHTML = ''; return; }
            t = setTimeout(async () => {
                try {
                    const res = await getJSON(api('search.api', { q }));
                    if (!res.results.length) {
                        dd.innerHTML = '<div class="px-3 py-2 text-muted small">No matches</div>';
                    } else {
                        dd.innerHTML = res.results.map(r =>
                            `<a href="${api('workspace', { path: r.path })}" data-path="${escapeHtml(r.path)}">
                                <div class="sd-title">${escapeHtml(r.title)}</div>
                                <div class="sd-snippet">${renderSnippet(r.snippet)}</div>
                             </a>`).join('');
                    }
                    dd.classList.add('show');
                } catch (e) { dd.classList.remove('show'); }
            }, 180);
        });
        document.addEventListener('click', (e) => {
            if (!dd.contains(e.target) && e.target !== gs) dd.classList.remove('show');
        });

        // Intercept search result selection on workspace page for dynamic loading
        dd.addEventListener('click', (e) => {
            const anchor = e.target.closest('a[data-path]');
            if (anchor && document.getElementById('workspace')) {
                e.preventDefault();
                const path = anchor.getAttribute('data-path');
                loadNote(path);
                dd.classList.remove('show');
                gs.value = '';
                gs.blur();
            }
        });

        // Prevent full page form submission on workspace page
        const searchForm = gs.closest('form');
        if (searchForm) {
            searchForm.addEventListener('submit', (e) => {
                if (document.getElementById('workspace')) {
                    e.preventDefault();
                    gs.blur();
                }
            });
        }
    }

    /* ----------------------------------------------------------------------
       Markdown rendering with Obsidian extras (wiki links + tags + media).
       ---------------------------------------------------------------------- */
    function buildLinkMap(outlinks) {
        const map = {};
        (outlinks || []).forEach(l => {
            map[(l.target_title || '').toLowerCase()] = l.path || null;
        });
        return map;
    }

    // Replace [[Target]] / [[Target|Alias]] with anchors before Markdown parse.
    function preprocessWikiLinks(md, linkMap) {
        return md.replace(/\[\[([^\[\]]+?)\]\]/g, (full, inner) => {
            const parts = inner.split('|');
            const target = parts[0].split(/[#^]/)[0].trim();
            const alias = (parts[1] || target).trim();
            const path = linkMap[target.toLowerCase()];
            if (path) {
                const href = api('workspace', { path });
                return `<a class="wikilink" href="${href}" data-path="${escapeHtml(path)}">${escapeHtml(alias)}</a>`;
            }
            return `<a class="wikilink missing" href="${api('search', { q: target })}">${escapeHtml(alias)}</a>`;
        });
    }

    // Rewrite relative image/link sources to the media route, relative to the
    // note's folder. Leaves absolute URLs and our own ?route= links untouched.
    function rewriteMedia(container, folder) {
        container.querySelectorAll('img[src], a[href]').forEach(el => {
            const attr = el.tagName === 'IMG' ? 'src' : 'href';
            const v = el.getAttribute(attr) || '';
            if (/^(https?:)?\/\//i.test(v) || v.startsWith('?') || v.startsWith('#')
                || v.startsWith('mailto:') || v.startsWith(BASE + '/?') || v.startsWith('/')) {
                return;
            }
            if (el.tagName === 'IMG') {
                const rel = (folder ? folder + '/' : '') + v;
                el.setAttribute('src', api('media', { path: rel }));
            }
        });
    }

    // Turn inline #tags into tag links, skipping code/pre/headings/anchors.
    function linkifyTags(container) {
        const skip = new Set(['CODE', 'PRE', 'A', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6']);
        const walker = document.createTreeWalker(container, NodeFilter.SHOW_TEXT, {
            acceptNode(node) {
                let p = node.parentNode;
                while (p && p !== container) {
                    if (skip.has(p.tagName)) return NodeFilter.FILTER_REJECT;
                    p = p.parentNode;
                }
                return /(^|\s)#[A-Za-z][\w\/\-]*/.test(node.nodeValue)
                    ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_REJECT;
            }
        });
        const targets = [];
        while (walker.nextNode()) targets.push(walker.currentNode);
        targets.forEach(node => {
            const frag = document.createDocumentFragment();
            let last = 0; const text = node.nodeValue;
            const re = /(^|\s)#([A-Za-z][\w\/\-]*)/g; let m;
            while ((m = re.exec(text)) !== null) {
                frag.appendChild(document.createTextNode(text.slice(last, m.index + m[1].length)));
                const a = document.createElement('a');
                a.className = 'taglink';
                a.href = api('tag', { tag: m[2] });
                a.textContent = '#' + m[2];
                frag.appendChild(a);
                last = re.lastIndex;
            }
            frag.appendChild(document.createTextNode(text.slice(last)));
            node.parentNode.replaceChild(frag, node);
        });
    }

    function renderMarkdown(targetEl, md, note) {
        const linkMap = buildLinkMap(note ? note.outlinks : []);
        let pre = preprocessWikiLinks(md, linkMap);
        const rawHtml = window.marked.parse(pre, { breaks: false, gfm: true });
        const clean = window.DOMPurify.sanitize(rawHtml, {
            ADD_ATTR: ['data-path', 'class', 'target'],
            ALLOWED_URI_REGEXP: /^(?:(?:https?|mailto|tel):|[^a-z]|[a-z+.\-]+(?:[^a-z+.\-:]|$))/i
        });
        targetEl.innerHTML = clean;
        rewriteMedia(targetEl, note ? note.folder : '');
        linkifyTags(targetEl);
        // Intercept internal wiki/note links for SPA navigation.
        targetEl.querySelectorAll('a[data-path]').forEach(a => {
                a.addEventListener('click', (e) => {
                e.preventDefault();
                loadNote(a.getAttribute('data-path'));
            });
        });
    }

    function isMobile() { return window.innerWidth < 992; }

    function syncBodyLayoutClass() {
        const ws = document.getElementById('workspace');
        if (!ws) return;
        const isNoteActive = ws.classList.contains('show-note');
        if (isMobile() && isNoteActive) {
            document.body.classList.add('mobile-note-active');
        } else {
            document.body.classList.remove('mobile-note-active');
        }
    }

    /* -- Global New Note Modal -- */
    async function submitNewNoteModal(path) {
        try {
            const res = await postJSON(api('note.create'), { path });
            const modalEl = document.getElementById('newNoteModal');
            if (modalEl) {
                const modalInstance = bootstrap.Modal.getInstance(modalEl);
                if (modalInstance) modalInstance.hide();
            }
            const pathInput = document.getElementById('newNoteModalPath');
            if (pathInput) pathInput.value = '';

            const ws = document.getElementById('workspace');
            if (ws) {
                loadNote(res.path);
                setTimeout(async () => { await refreshTree(); }, 300);
            } else {
                window.location.href = api('workspace', { path: res.path });
            }
        } catch (e) {
            alert('Could not create note: ' + e.message);
        }
    }

    const newNoteModalEl = document.getElementById('newNoteModal');
    if (newNoteModalEl) {
        const modalConfirmBtn = document.getElementById('btnNewNoteModalConfirm');
        const modalPathInput = document.getElementById('newNoteModalPath');
        let bsModal = null;

        const openModal = () => {
            if (!bsModal) {
                bsModal = new bootstrap.Modal(newNoteModalEl);
            }
            if (modalPathInput) modalPathInput.value = '';
            bsModal.show();
            setTimeout(() => { if (modalPathInput) modalPathInput.focus(); }, 400);
        };

        const submitModalForm = () => {
            const path = modalPathInput ? modalPathInput.value.trim() : '';
            if (!path) {
                if (modalPathInput) modalPathInput.focus();
                return;
            }
            submitNewNoteModal(path);
        };

        const navNewNote = document.getElementById('navNewNote');
        if (navNewNote) {
            navNewNote.addEventListener('click', (e) => {
                e.preventDefault();
                openModal();
            });
        }

        const mbnNewBtn = document.getElementById('mbnNewBtn');
        if (mbnNewBtn) {
            mbnNewBtn.addEventListener('click', (e) => {
                e.preventDefault();
                openModal();
            });
        }

        const btnNew = document.getElementById('btnNew');
        if (btnNew) {
            btnNew.addEventListener('click', (e) => {
                e.preventDefault();
                openModal();
            });
        }

        if (modalConfirmBtn) {
            modalConfirmBtn.addEventListener('click', submitModalForm);
        }
        if (modalPathInput) {
            modalPathInput.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') submitModalForm();
            });
        }
    }

    const globalMbn = {
        vault:  document.getElementById('mbnVault'),
        search: document.getElementById('mbnSearch'),
        newBtn: document.getElementById('mbnNewBtn'),
        mode:   document.getElementById('mbnModeBtn'),
        save:   document.getElementById('mbnSaveBtn'),
    };

    const ws = document.getElementById('workspace');
    if (!ws) {
        // Handle global bottom nav navigation on non-workspace pages
        if (globalMbn.vault) {
            globalMbn.vault.addEventListener('click', () => {
                window.location.href = api('workspace');
            });
        }

        if (globalMbn.search) {
            globalMbn.search.addEventListener('click', (e) => {
                e.preventDefault();
                const gs = document.getElementById('globalSearch');
                if (gs) {
                    gs.focus();
                    gs.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            });
        }
        if (globalMbn.mode) {
            globalMbn.mode.disabled = true;
            globalMbn.mode.style.opacity = '0.35';
        }
        if (globalMbn.save) {
            globalMbn.save.disabled = true;
            globalMbn.save.style.opacity = '0.35';
        }
        return; // stop execution here since we're not on the workspace page
    }

    const els = {
        view:         document.getElementById('noteView'),
        editor:       document.getElementById('noteEditor'),
        area:         document.getElementById('editorArea'),
        crumb:        document.getElementById('noteBreadcrumb'),
        save:         document.getElementById('btnSave'),
        btnView:      document.getElementById('btnView'),
        btnEdit:      document.getElementById('btnEdit'),
        btnNew:       document.getElementById('btnNew'),
        btnDelete:    document.getElementById('btnDelete'),
        status:       document.getElementById('saveStatus'),
        metaTags:     document.getElementById('metaTags'),
        metaBack:     document.getElementById('metaBacklinks'),
        metaOut:      document.getElementById('metaOutlinks'),
        metaInfo:     document.getElementById('metaInfo'),
        upload:       document.getElementById('uploadInput'),
        uploadStatus: document.getElementById('uploadStatus'),
        // New note bar
        newBar:       document.getElementById('newNoteBar'),
        newPath:      document.getElementById('newNotePath'),
        newConfirm:   document.getElementById('btnNewConfirm'),
        newCancel:    document.getElementById('btnNewCancel'),
        // Sidebar toggles
        toggleLeft:   document.getElementById('btnToggleLeft'),
        toggleRight:  document.getElementById('btnToggleRight'),
        mbnVault:     document.getElementById('mbnVault'),
        mbnSearch:    document.getElementById('mbnSearch'),
        mbnNewBtn:    document.getElementById('mbnNewBtn'),
        mbnMode:      document.getElementById('mbnModeBtn'),
        mbnModeIcon:  document.getElementById('mbnModeIcon'),
        mbnModeLabel: document.getElementById('mbnModeLabel'),
        mbnSave:      document.getElementById('mbnSaveBtn'),
    };

    let current = null;   // loaded note object {path, content, mtime, ...}
    let savedContent = ''; // content as last saved/loaded, used for dirty check
    let mode = 'view';
    let easy = null;      // EasyMDE instance
    let autosaveTimer = null;

    function isDirty() {
        if (!easy) return false;
        return easy.value() !== savedContent;
    }

    /* -- Sidebar panel toggles -------------------------------------------- */

    function applyPanelState() {
        const leftKey  = 'pkh-left-col';
        const rightKey = 'pkh-right-col';

        // Default: collapsed on mobile, open on desktop.
        const defaultLeft  = isMobile() ? '1' : '0';
        const defaultRight = isMobile() ? '1' : '0';

        const leftCol  = localStorage.getItem(leftKey)  ?? defaultLeft;
        const rightCol = localStorage.getItem(rightKey) ?? defaultRight;

        ws.classList.toggle('left-collapsed',  leftCol  === '1');
        ws.classList.toggle('right-collapsed', rightCol === '1');
        updatePanelButtons();
    }

    function updatePanelButtons() {
        const lc = ws.classList.contains('left-collapsed');
        const rc = ws.classList.contains('right-collapsed');
        if (els.toggleLeft)  els.toggleLeft.classList.toggle('active', lc);
        if (els.toggleRight) els.toggleRight.classList.toggle('active', rc);
        // Mobile bottom nav active state
        if (els.mbnVault) els.mbnVault.classList.toggle('active', !lc);
        if (els.mbnInfo)  els.mbnInfo.classList.toggle('active',  !rc);
    }

    function toggleLeft() {
        ws.classList.toggle('left-collapsed');
        localStorage.setItem('pkh-left-col', ws.classList.contains('left-collapsed') ? '1' : '0');
        updatePanelButtons();
    }
    function toggleRight() {
        ws.classList.toggle('right-collapsed');
        localStorage.setItem('pkh-right-col', ws.classList.contains('right-collapsed') ? '1' : '0');
        updatePanelButtons();
    }

    if (els.toggleLeft)  els.toggleLeft.addEventListener('click',  toggleLeft);
    if (els.toggleRight) els.toggleRight.addEventListener('click', toggleRight);

    applyPanelState();

    /* -- Editor ------------------------------------------------------------ */
    function initEditor() {
        if (easy) return;
        easy = new EasyMDE({
            element: els.area,
            autofocus: false,
            spellChecker: false,
            status: false,
            autoDownloadFontAwesome: false,
            toolbar: ['bold', 'italic', 'heading', '|', 'quote', 'unordered-list',
                'ordered-list', 'code', 'table', '|', 'link', {
                    name: 'image',
                    action: function(editor) {
                        const uploadInput = document.getElementById('uploadInput');
                        if (uploadInput) {
                            uploadInput.click();
                        }
                    },
                    className: 'fa fa-image',
                    title: 'Upload Image'
                }, '|', 'preview'],
            previewRender: (plainText) => {
                const tmp = document.createElement('div');
                renderMarkdown(tmp, plainText, current);
                return tmp.innerHTML;
            }
        });
        easy.codemirror.on('change', () => {
            if (mode === 'edit') updateDirtyUI();
        });
    }

    function updateDirtyUI() {
        const d = isDirty();
        els.save.disabled = !d;
        if (els.mbnSave) els.mbnSave.disabled = !d;
        if (!d) setStatus('', '');
    }

    function setStatus(text, cls) {
        els.status.textContent = text;
        els.status.className = 'save-status' + (cls ? ' ' + cls : '');
    }

    function updateMobileMode() {
        if (!els.mbnModeIcon || !els.mbnModeLabel) return;
        if (mode === 'edit') {
            els.mbnModeIcon.textContent = '\uD83D\uDC41';  // 👁
            els.mbnModeLabel.textContent = 'View';
            if (els.mbnMode) els.mbnMode.classList.add('active');
        } else {
            els.mbnModeIcon.textContent = '\u270F\uFE0F';  // ✏️
            els.mbnModeLabel.textContent = 'Edit';
            if (els.mbnMode) els.mbnMode.classList.remove('active');
        }
    }

    function setMode(next) {
        mode = next;
        els.btnView.classList.toggle('active', next === 'view');
        els.btnEdit.classList.toggle('active', next === 'edit');
        if (next === 'edit') {
            initEditor();
            els.editor.classList.remove('d-none');
            els.view.classList.add('d-none');
            if (current) easy.value(current._draft != null ? current._draft : current.content);
            // Multiple refresh calls ensure CodeMirror reflows correctly on mobile
            setTimeout(() => { if (easy) easy.codemirror.refresh(); }, 50);
            setTimeout(() => { if (easy) easy.codemirror.refresh(); }, 250);
            setTimeout(() => { if (easy) easy.codemirror.refresh(); }, 600);
            if (isMobile()) {
                document.body.classList.add('mobile-editing');
                ws.classList.remove('show-sidebar');
                ws.classList.add('show-note');
                if (els.mbnVault) els.mbnVault.classList.remove('active');
                // Extra refresh after mobile layout settles
                setTimeout(() => { if (easy) easy.codemirror.refresh(); }, 150);
                setTimeout(() => { if (easy) easy.codemirror.refresh(); }, 400);
            }
            if (current) {
                els.crumb.innerHTML = `<input type="text" id="editNotePath" class="form-control form-control-sm d-inline-block" style="width: 200px; max-width: 55vw; display: inline-block; padding: 0.25rem 0.5rem; height: auto; min-height: unset;" value="${escapeHtml(current.path)}" placeholder="path/to/note.md">`;
                const editPathEl = document.getElementById('editNotePath');
                if (editPathEl) {
                    editPathEl.addEventListener('keydown', (e) => {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            saveNote();
                        }
                    });
                }
            }
        } else {
            // Render current draft (if editing) or saved content.
            const md = (easy && isDirty()) ? easy.value() : (current ? current.content : '');
            if (current) current._draft = (easy && isDirty()) ? easy.value() : current.content;
            renderMarkdown(els.view, md, current);
            els.editor.classList.add('d-none');
            els.view.classList.remove('d-none');
            if (isMobile()) {
                document.body.classList.remove('mobile-editing');
            }
            if (current) {
                els.crumb.textContent = current.path;
            } else {
                els.crumb.innerHTML = '<span class="text-muted">Select a note from the sidebar</span>';
            }
        }
        updateMobileMode();
        syncBodyLayoutClass();
    }

    async function loadNote(path) {
        if (isDirty() && !confirm('Discard unsaved changes?')) return;
        setStatus('', '');
        try {
            const note = await getJSON(api('note.get', { path }));
            current = note; current._draft = null;
            savedContent = note.content;
            els.crumb.textContent = note.path;
            renderMetadata(note);
            if (easy) easy.value(note.content);
            setMode('view');
            history.replaceState(null, '', api('workspace', { path }));
            highlightTree(path);
            updateDirtyUI();
            
            // On mobile, show the note page and hide the sidebar
            if (isMobile()) {
                ws.classList.remove('show-sidebar');
                ws.classList.add('show-note');
                if (els.mbnVault) els.mbnVault.classList.remove('active');
            }
            syncBodyLayoutClass();
        } catch (e) {
            els.view.classList.remove('d-none');
            els.editor.classList.add('d-none');
            els.view.innerHTML = '<div class="alert alert-danger">' + escapeHtml(e.message) + '</div>';
        }
    }

    function renderMetadata(note) {
        els.metaTags.innerHTML = (note.tags && note.tags.length)
            ? note.tags.map(t => `<a class="tag-chip" href="${api('tag', { tag: t })}">#${escapeHtml(t)}</a>`).join('')
            : '<span class="text-muted small">No tags</span>';

        els.metaBack.innerHTML = (note.backlinks && note.backlinks.length)
            ? note.backlinks.map(b =>
                `<li><a href="#" data-path="${escapeHtml(b.path)}">${escapeHtml(b.title)}</a></li>`).join('')
            : '<li class="text-muted small">No backlinks</li>';

        els.metaOut.innerHTML = (note.outlinks && note.outlinks.length)
            ? note.outlinks.map(o => o.path
                ? `<li><a href="#" data-path="${escapeHtml(o.path)}">${escapeHtml(o.target_title)}</a></li>`
                : `<li class="text-muted small">${escapeHtml(o.target_title)} (missing)</li>`).join('')
            : '<li class="text-muted small">No outgoing links</li>';

        els.metaInfo.innerHTML =
            `Folder: ${escapeHtml(note.folder || '/')}<br>Modified: ${escapeHtml(note.modified || '')}`;

        ws.querySelectorAll('.meta-rail a[data-path]').forEach(a =>
            a.addEventListener('click', (e) => { e.preventDefault(); loadNote(a.getAttribute('data-path')); }));
    }

    function highlightTree(path) {
        ws.querySelectorAll('.note-link').forEach(a => {
            const isActive = a.getAttribute('data-path') === path;
            a.classList.toggle('active', isActive);
            if (isActive) {
                let parent = a.closest('details');
                while (parent) {
                    parent.open = true;
                    parent = parent.parentElement.closest('details');
                }
            }
        });
    }

    async function saveNote() {
        if (!current || mode !== 'edit') return;
        const content = easy.value();
        const editPathEl = document.getElementById('editNotePath');
        const newPath = editPathEl ? editPathEl.value.trim() : current.path;
        if (!newPath) {
            alert('Note path cannot be empty.');
            if (editPathEl) editPathEl.focus();
            return;
        }
        setStatus('Saving\u2026', 'saving'); els.save.disabled = true;
        if (els.mbnSave) els.mbnSave.disabled = true;
        try {
            const res = await postJSON(api('note.save'),
                { path: current.path, newPath, content, mtime: current.mtime });
            current.content = content; current._draft = null;
            savedContent = content;
            current.mtime = res.mtime; current.modified = res.modified;
            current.tags = res.tags || current.tags;
            
            const pathChanged = (res.path && res.path !== current.path);
            if (pathChanged) {
                current.path = res.path;
                if (editPathEl) {
                    editPathEl.value = res.path;
                } else {
                    els.crumb.textContent = res.path;
                }
                history.replaceState(null, '', api('workspace', { path: res.path }));
                highlightTree(res.path);
                await refreshTree();
            }
            
            renderMetadata(current);
            updateDirtyUI();
            setStatus('Saved', 'saved');
        } catch (e) {
            els.save.disabled = false;
            if (els.mbnSave) els.mbnSave.disabled = false;
            if (e.status === 409) {
                setStatus('Conflict \u2014 reload', 'error');
                alert(e.message);
            } else {
                setStatus('Error', 'error');
                alert(e.message || 'Unable to save note.');
            }
        }
    }



    /* -- Delete note ---------------------------------------------------- */
    if (els.btnDelete) {
        els.btnDelete.addEventListener('click', async () => {
            if (!current) return;
            if (!confirm('Delete "' + current.path + '"? This cannot be undone.')) return;
            try {
                await postJSON(api('note.delete'), { path: current.path });
                current = null;
                setStatus('', '');
                window.location.href = api('dashboard');
            } catch (e) {
                alert('Could not delete: ' + e.message);
            }
        });
    }

    /* -- Sidebar note links -> SPA load ------------------------------------ */
    ws.querySelectorAll('.note-link').forEach(a => {
        a.addEventListener('click', (e) => {
            e.preventDefault();
            loadNote(a.getAttribute('data-path'));
        });
    });

    els.btnView.addEventListener('click', () => setMode('view'));
    els.btnEdit.addEventListener('click', () => setMode('edit'));
    els.save.addEventListener('click', saveNote);

    /* -- Image / file upload -> insert markdown at cursor ------------------ */
    if (els.upload) {
        els.upload.addEventListener('change', async () => {
            const file = els.upload.files[0];
            if (!file) return;
            els.uploadStatus.textContent = 'Uploading\u2026';
            const fd = new FormData();
            fd.append('file', file);
            try {
                const r = await fetch(api('upload'), {
                    method: 'POST',
                    headers: { 'X-CSRF-Token': CSRF, 'X-Requested-With': 'XMLHttpRequest' },
                    body: fd
                });
                const j = await r.json();
                if (!r.ok) throw new Error(j.error || 'Upload failed');
                if (mode !== 'edit') setMode('edit');
                const cm = easy.codemirror;
                cm.replaceSelection(j.markdown + '\n');
                updateDirtyUI();
                els.uploadStatus.textContent = 'Inserted ' + j.name;
            } catch (e) {
                els.uploadStatus.textContent = 'Error: ' + e.message;
            } finally {
                els.upload.value = '';
            }
        });
    }

    function renderTreeNodes(nodes, isAdmin) {
        if (!nodes || !nodes.length) return '';
        let html = '<ul class="tree-list">';
        for (const node of nodes) {
            if (node.type === 'folder') {
                html += '<li class="tree-folder">';
                html += '<details><summary class="d-flex align-items-center justify-content-between">';
                html += `<span><span class="ico">&#128193;</span>${escapeHtml(node.name)}</span>`;
                if (isAdmin) {
                    html += `<button class="btn-delete-folder btn btn-link p-0 text-danger border-0 opacity-50 hover-opacity-100" data-folder="${escapeHtml(node.path)}" title="Delete folder and all its contents" style="font-size: 0.8rem; line-height: 1; text-decoration: none; min-height: unset;">&#128465;</button>`;
                }
                html += '</summary>';
                html += renderTreeNodes(node.children, isAdmin);
                html += '</details></li>';
            } else {
                const url = api('workspace', { path: node.path });
                const label = node.title !== '' ? node.title : node.name;
                const activeClass = (current && current.path === node.path) ? 'active' : '';
                html += `<li class="tree-note">`;
                html += `<a href="${escapeHtml(url)}" data-path="${escapeHtml(node.path)}" class="note-link ${activeClass}">`;
                html += `<span class="ico">&#128196;</span>${escapeHtml(label)}</a></li>`;
            }
        }
        html += '</ul>';
        return html;
    }

    async function refreshTree() {
        try {
            const res = await getJSON(api('tree'));
            const container = document.getElementById('vaultTreeNodes');
            if (container && res.tree) {
                const isAdmin = container.getAttribute('data-is-admin') === '1';
                if (res.tree.length > 0) {
                    container.innerHTML = renderTreeNodes(res.tree, isAdmin);
                } else {
                    container.innerHTML = '<p class="text-muted small px-2 py-2">No notes yet. Add .md files to the vault, import a ZIP, or rescan.</p>';
                }
                // Re-bind click events on note-links
                container.querySelectorAll('.note-link').forEach(a => {
                    a.addEventListener('click', (e) => {
                        e.preventDefault();
                        loadNote(a.getAttribute('data-path'));
                    });
                });
                // Re-bind click events on btn-delete-folder
                container.querySelectorAll('.btn-delete-folder').forEach(btn => {
                    btn.addEventListener('click', async (e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        const folder = btn.getAttribute('data-folder');
                        if (!confirm('Are you sure you want to permanently delete folder "' + folder + '" and all of its notes/folders? This action CANNOT be undone.')) {
                            return;
                        }
                        try {
                            btn.disabled = true;
                            const res = await postJSON(api('vault.delete_folder'), { folder });
                            alert('Folder deleted successfully.');
                            location.reload();
                        } catch (err) {
                            alert('Failed to delete folder: ' + err.message);
                            btn.disabled = false;
                        }
                    });
                });
                if (current) {
                    highlightTree(current.path);
                }
            }
        } catch (e) {
            console.error('Failed to refresh tree dynamically:', e);
        }
    }

    /* -- Mobile bottom nav ------------------------------------------------- */
    if (els.mbnVault) {
        els.mbnVault.addEventListener('click', () => {
            if (isMobile()) {
                if (ws.classList.contains('show-sidebar')) {
                    if (current) {
                        ws.classList.remove('show-sidebar');
                        ws.classList.add('show-note');
                        els.mbnVault.classList.remove('active');
                    }
                } else {
                    ws.classList.remove('show-note');
                    ws.classList.add('show-sidebar');
                    els.mbnVault.classList.add('active');
                }
                syncBodyLayoutClass();
            } else {
                toggleLeft();
            }
        });
    }
    if (els.mbnSearch) {
        els.mbnSearch.addEventListener('click', (e) => {
            e.preventDefault();
            const gs = document.getElementById('globalSearch');
            if (gs) {
                if (mode === 'edit') {
                    setMode('view');
                }
                if (isMobile()) {
                    ws.classList.remove('show-sidebar');
                    ws.classList.add('show-note');
                    if (els.mbnVault) els.mbnVault.classList.remove('active');
                }
                gs.focus();
                gs.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        });
    }

    if (els.mbnMode) {
        els.mbnMode.addEventListener('click', () => {
            if (!current) return;
            setMode(mode === 'view' ? 'edit' : 'view');
        });
    }
    if (els.mbnSave) {
        els.mbnSave.addEventListener('click', saveNote);
    }

    // Sync initial mobile mode label.
    updateMobileMode();

    /* -- Autosave ---------------------------------------------------------- */
    autosaveTimer = setInterval(() => {
        if (mode === 'edit' && isDirty() && current) saveNote();
    }, 30000);

    // Warn on unload with unsaved changes.
    window.addEventListener('beforeunload', (e) => {
        if (isDirty()) { e.preventDefault(); e.returnValue = ''; }
    });

    // Initial page class on mobile
    if (isMobile()) {
        const initial = ws.getAttribute('data-initial-path');
        if (!initial) {
            ws.classList.add('show-sidebar');
            if (els.mbnVault) els.mbnVault.classList.add('active');
        } else {
            ws.classList.add('show-note');
        }
        syncBodyLayoutClass();
    }

    // Initial note from ?path=.
    const initial = ws.getAttribute('data-initial-path');
    if (initial) loadNote(initial);

    // Check if new note requested via query param
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('new') === '1') {
        const newNoteModalEl = document.getElementById('newNoteModal');
        if (newNoteModalEl) {
            const bsModal = new bootstrap.Modal(newNoteModalEl);
            bsModal.show();
            const modalPathInput = document.getElementById('newNoteModalPath');
            setTimeout(() => { if (modalPathInput) modalPathInput.focus(); }, 400);
        }
    }
})();
