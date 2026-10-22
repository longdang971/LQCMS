// Trình sửa mã theme: cây thư mục, nhiều tab (mỗi tab một EditSession của Ace, giữ undo riêng),
// lưu bằng AJAX (⌘S), kiểm tra lỗi cú pháp phía máy chủ, khôi phục bản trước, tạo tệp mới.
(() => {
  const root = document.querySelector('[data-theme-editor]');
  if (!root) return;

  const $ = (s) => root.querySelector(s);
  const $$ = (s) => [...root.querySelectorAll(s)];
  const theme = root.dataset.theme;
  const store = {
    get(k, d) { try { const v = localStorage.getItem(k); return v === null ? d : JSON.parse(v); } catch (e) { return d; } },
    set(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} },
    sget(k, d) { try { const v = sessionStorage.getItem(k); return v === null ? d : JSON.parse(v); } catch (e) { return d; } },
    sset(k, v) { try { sessionStorage.setItem(k, JSON.stringify(v)); } catch (e) {} },
  };
  const toast = (type, msg) => (window.LQ?.toast ? LQ.toast(type, msg) : alert(msg));
  const ask = (opts) => (window.LQ?.confirm ? LQ.confirm(opts) : Promise.resolve(confirm(opts.title + '\n\n' + (opts.text || ''))));
  const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';
  const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  const initial = JSON.parse($('[data-te-initial]').textContent);
  /** @type {Map<string, {file, session, saved, hash, writable, hasBackup, desc, icon, kind, mode, savedAt, loaded}>} */
  const tabs = new Map();
  let active = null;
  const LANGS = { php: 'PHP', css: 'CSS', javascript: 'JavaScript', json: 'JSON', html: 'HTML', markdown: 'Markdown', xml: 'XML / SVG', text: 'Văn bản' };

  const el = {
    source: $('[data-te-source]'), editor: $('[data-te-editor]'), loading: $('[data-te-loading]'),
    tabs: $('[data-te-tabs]'), crumbs: $('[data-te-crumbs]'), desc: $('[data-te-desc]'),
    save: $('[data-te-save]'), saveLabel: $('[data-te-save-label]'), restore: $('[data-te-restore]'),
    readonly: $('[data-te-readonly]'), pos: $('[data-te-pos]'), size: $('[data-te-size]'),
    lang: $('[data-te-lang]'), savedAt: $('[data-te-saved-at] span'),
  };
  // Lời báo chỉ đọc: mặc định là thiếu quyền ghi; tệp không phải UTF-8 thì nói đúng lý do.
  // Khai báo ở đây (không cạnh renderBar): nhánh không có Ace bên dưới gọi renderBar sớm.
  const readonlyText = el.readonly.querySelector('span');
  const readonlyDefault = readonlyText.innerHTML;

  /* ---------- Cây thư mục: nhớ thư mục đang mở, thu gọn, tìm tệp ---------- */
  const dirKey = `te-dirs:${theme}`;
  const openDirs = new Set(store.get(dirKey, []));
  $$('details.tree-dir').forEach((d) => {
    if (openDirs.has(d.dataset.dir)) d.open = true;
    d.addEventListener('toggle', () => {
      if (filtering) return;
      d.open ? openDirs.add(d.dataset.dir) : openDirs.delete(d.dataset.dir);
      store.set(dirKey, [...openDirs]);
    });
  });
  $('[data-te-collapse]')?.addEventListener('click', () => {
    $$('details.tree-dir').forEach((d) => { d.open = false; });
    openDirs.clear();
    store.set(dirKey, []);
  });

  let filtering = false;
  const filter = $('[data-te-filter]');
  filter?.addEventListener('input', () => {
    const q = filter.value.trim().toLowerCase();
    filtering = q !== '';
    let shown = 0;
    $$('[data-te-file]').forEach((a) => {
      const hit = !filtering || a.dataset.teFile.toLowerCase().includes(q) || (a.dataset.tip || '').toLowerCase().includes(q);
      a.hidden = !hit;
      if (hit) shown++;
    });
    $$('details.tree-dir').forEach((d) => {
      const any = !!d.querySelector('[data-te-file]:not([hidden])');
      d.hidden = filtering && !any;
      d.open = filtering ? any : openDirs.has(d.dataset.dir) || d.contains($('.tree-file.is-current'));
    });
    $('[data-te-tree-empty]').hidden = shown > 0;
  });
  // Khai báo sớm: các handler dưới đây chạy được cả khi không tải được Ace (editor vẫn null).
  let editor = null;
  filter?.addEventListener('keydown', (e) => {
    if (e.isComposing || e.keyCode === 229) return; // đang chốt chữ bằng bộ gõ (IME)
    if (e.key === 'Enter') {
      e.preventDefault();
      const first = $('[data-te-file]:not([hidden])');
      if (first) { first.click(); filter.value = ''; filter.dispatchEvent(new Event('input')); }
    } else if (e.key === 'Escape') {
      filter.value = '';
      filter.dispatchEvent(new Event('input'));
      editor?.focus();
    }
  });

  $('[data-te-theme]')?.addEventListener('change', (e) => {
    location.href = '/admin/themes/editor?theme=' + encodeURIComponent(e.target.value);
  });

  /* ---------- Ẩn/hiện danh sách tệp ---------- */
  const collapseKey = 'te-explorer-collapsed';
  const setCollapsed = (v) => {
    root.classList.toggle('is-collapsed', v);
    store.set(collapseKey, v);
    setTimeout(() => editor?.resize(), 220);
  };
  if (store.get(collapseKey, false)) root.classList.add('is-collapsed');
  $('[data-te-toggle-explorer]')?.addEventListener('click', () => setCollapsed(!root.classList.contains('is-collapsed')));

  /* ---------- Không tải được Ace: dùng textarea + điều hướng thường ---------- */
  if (!window.ace) {
    el.source.hidden = false;
    let saved = el.source.value;
    let hash = initial.hash;
    renderBar(initial);
    el.source.addEventListener('input', () => setDirty(el.source.value !== saved));
    const fallbackSave = async () => {
      const res = await post('/admin/themes/editor/save', { theme, file: initial.file, content: el.source.value, hash });
      if (res.ok) { saved = el.source.value; hash = res.hash; setDirty(false); toast('success', res.message); } else toast('error', res.message);
    };
    el.save.addEventListener('click', fallbackSave);
    return;
  }

  /* ---------- Ace ---------- */
  ace.config.set('basePath', 'https://cdn.jsdelivr.net/npm/ace-builds@1.36.5/src-min-noconflict/');
  const isDark = () => {
    const t = document.documentElement.dataset.theme;
    return t ? t === 'dark' : matchMedia('(prefers-color-scheme: dark)').matches;
  };
  const aceTheme = () => (isDark() ? 'ace/theme/github_dark' : 'ace/theme/github_light_default');
  el.editor.hidden = false;
  el.source.hidden = true;
  // Đặt line-height TRƯỚC khi tạo editor để Ace đo đúng chiều cao dòng (lệch là số dòng ở lề sai vị trí).
  el.editor.style.lineHeight = '1.7';
  // Ngắt dòng dài mặc định BẬT. Đổi tên khoá (te-wrap → te-wrap-on) để trình duyệt từng lưu "tắt" cũng bắt đầu lại ở trạng thái bật.
  const wrapKey = 'te-wrap-on';
  let wrap = store.get(wrapKey, true);
  editor = ace.edit(el.editor, {
    theme: aceTheme(),
    fontFamily: '"Geist Mono", ui-monospace, "SF Mono", Menlo, monospace',
    fontSize: 13.5,
    showPrintMargin: false,
    highlightActiveLine: true,
    highlightGutterLine: true,
    displayIndentGuides: true,
    highlightIndentGuides: true,
    scrollPastEnd: 0.5,
    enableBasicAutocompletion: true,
    animatedScroll: true,
    fixedWidthGutter: true,
    cursorStyle: 'smooth',
    showFoldWidgets: true,
    fadeFoldWidgets: true,
  });
  editor.renderer.setPadding(14);
  editor.renderer.setScrollMargin(10, 10, 0, 0);
  // Font Geist Mono tải muộn hơn editor → đo lại khi font sẵn sàng.
  document.fonts?.ready.then(() => editor.renderer.updateFontSize());
  new MutationObserver(() => editor.setTheme(aceTheme()))
    .observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });

  const wrapBtn = $('[data-te-wrap]');
  const applyWrap = () => {
    wrapBtn.setAttribute('aria-pressed', String(wrap));
    wrapBtn.classList.toggle('is-on', wrap);
    tabs.forEach((t) => t.session?.setUseWrapMode(wrap));
  };
  wrapBtn.addEventListener('click', () => { wrap = !wrap; store.set(wrapKey, wrap); applyWrap(); });

  /* ---------- Tab ---------- */
  const tabsKey = `te-tabs:${theme}`;

  const treeRow = (file) => root.querySelector(`[data-te-file="${CSS.escape(file)}"]`);
  const iconHtml = (file) => treeRow(file)?.querySelector('.tree-icon')?.outerHTML || '<span class="tree-icon kind-txt"></span>';
  const isDirty = (t) => t.loaded && t.session.getValue() !== t.saved;
  const persistTabs = () => store.sset(tabsKey, { list: [...tabs.keys()], active });

  function makeSession(data) {
    const s = ace.createEditSession(data.content, 'ace/mode/' + data.mode);
    s.setUndoManager(new ace.UndoManager());
    s.setTabSize(4);
    s.setUseSoftTabs(true);
    s.setUseWrapMode(wrap);
    s.on('change', () => { if (tabs.get(active)?.session === s) refresh(); renderTabs(); });
    return s;
  }

  function addTab(data) {
    const t = tabs.get(data.file) || { file: data.file, loaded: false };
    if (data.content !== undefined) {
      Object.assign(t, data, { session: makeSession(data), saved: data.content, loaded: true, savedAt: null });
    }
    tabs.set(data.file, t);
    return t;
  }

  async function load(file) {
    el.loading.hidden = false;
    try {
      const r = await fetch(`/admin/themes/editor/file?theme=${encodeURIComponent(theme)}&file=${encodeURIComponent(file)}`, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
      const data = await r.json();
      if (!data.ok) throw new Error(data.message || 'Không mở được tệp.');
      return addTab(data);
    } finally {
      el.loading.hidden = true;
    }
  }

  async function open(file, { line = 0 } = {}) {
    let t = tabs.get(file);
    try {
      if (!t || !t.loaded) t = await load(file);
    } catch (e) {
      toast('error', e.message);
      if (tabs.has(file) && !tabs.get(file).loaded) { tabs.delete(file); renderTabs(); persistTabs(); }
      return;
    }
    active = file;
    editor.setSession(t.session);
    editor.setReadOnly(!t.writable);
    $$('.tree-file').forEach((a) => {
      const on = a.dataset.teFile === file;
      a.classList.toggle('is-current', on);
      on ? a.setAttribute('aria-current', 'page') : a.removeAttribute('aria-current');
    });
    // Mở thư mục chứa tệp để thấy tệp đang sửa.
    let d = treeRow(file)?.closest('details');
    while (d) { d.open = true; d = d.parentElement.closest('details'); }
    treeRow(file)?.scrollIntoView({ block: 'nearest' });
    history.replaceState(null, '', `/admin/themes/editor?theme=${encodeURIComponent(theme)}&file=${encodeURIComponent(file)}`);
    window.LQ?.setSubject?.(`${file} (${root.dataset.themeName})`);
    renderTabs();
    renderBar(t);
    refresh();
    persistTabs();
    if (line > 0) {
      editor.gotoLine(line, 0, false);
      editor.scrollToLine(line - 1, true, false);
      mark(line, 'Dòng gây lỗi khi chạy theme');
    }
    editor.focus();
  }

  async function close(file) {
    const t = tabs.get(file);
    if (!t) return;
    if (isDirty(t)) {
      const ok = await ask({ title: `Đóng ${file.split('/').pop()}?`, text: 'Tệp có thay đổi chưa lưu. Đóng tab sẽ bỏ các thay đổi này.', danger: true, confirmText: 'Bỏ thay đổi' });
      if (!ok) return;
    }
    const keys = [...tabs.keys()];
    const idx = keys.indexOf(file);
    tabs.delete(file);
    if (active === file) {
      const next = keys[idx + 1] ?? keys[idx - 1];
      if (next && tabs.has(next)) return open(next);
      active = null;
      editor.setSession(ace.createEditSession('', 'ace/mode/text'));
      editor.setReadOnly(true);
      renderBar(null);
    }
    renderTabs();
    persistTabs();
  }

  function renderTabs() {
    el.tabs.innerHTML = [...tabs.values()].map((t) => {
      const name = t.file.split('/').pop();
      const dirty = isDirty(t);
      return `<div class="te-tab${t.file === active ? ' is-active' : ''}${dirty ? ' is-dirty' : ''}" role="tab" aria-selected="${t.file === active}" data-tab="${esc(t.file)}" title="${esc(t.file)}">
        ${iconHtml(t.file)}<span class="te-tab-name">${esc(name)}</span>
        <button class="te-tab-close" type="button" data-tab-close aria-label="Đóng ${esc(name)}"><span class="te-tab-dot"></span><svg viewBox="0 0 24 24"><path d="M18 6 6 18M6 6l12 12"/></svg></button>
      </div>`;
    }).join('');
    el.tabs.hidden = tabs.size === 0;
  }
  el.tabs.addEventListener('click', (e) => {
    const tab = e.target.closest('[data-tab]');
    if (!tab) return;
    if (e.target.closest('[data-tab-close]')) close(tab.dataset.tab);
    else if (tab.dataset.tab !== active) open(tab.dataset.tab);
  });
  el.tabs.addEventListener('auxclick', (e) => {
    const tab = e.target.closest('[data-tab]');
    if (tab && e.button === 1) { e.preventDefault(); close(tab.dataset.tab); }
  });

  // Bấm tệp trong cây: mở tab thay vì tải lại trang.
  $('[data-te-tree]').addEventListener('click', (e) => {
    const a = e.target.closest('[data-te-file]');
    if (!a || e.metaKey || e.ctrlKey || e.shiftKey) return;
    e.preventDefault();
    open(a.dataset.teFile);
  });

  /* ---------- Thanh trên, thanh trạng thái ---------- */
  function renderBar(t) {
    if (!t) {
      el.crumbs.innerHTML = '<span class="muted">Chọn một tệp bên trái để bắt đầu</span>';
      el.desc.hidden = true;
      el.restore.hidden = true;
      el.readonly.hidden = true;
      el.lang.textContent = '';
      el.size.textContent = '';
      setDirty(false);
      return;
    }
    const parts = t.file.split('/');
    const name = parts.pop();
    el.crumbs.innerHTML = `<span class="te-crumb-root">${esc(theme)}</span>`
      + parts.map((p) => `<span class="te-crumb-sep">/</span><span>${esc(p)}</span>`).join('')
      + `<span class="te-crumb-sep">/</span>${iconHtml(t.file)}<strong>${esc(name)}</strong>`;
    el.desc.textContent = t.desc || '';
    el.desc.hidden = !t.desc;
    el.restore.hidden = !t.hasBackup;
    el.readonly.hidden = t.writable !== false;
    if (t.binary) readonlyText.textContent = 'Tệp không phải văn bản UTF-8 nên chỉ xem được — lưu từ trình duyệt sẽ làm hỏng các byte không đọc được.';
    else readonlyText.innerHTML = readonlyDefault;
    el.lang.textContent = LANGS[t.mode] || t.mode;
  }

  function setDirty(dirty, saving = false) {
    el.save.disabled = !dirty || saving || !(tabs.get(active)?.writable ?? true);
    el.save.classList.toggle('is-dirty', dirty);
    el.saveLabel.textContent = saving ? 'Đang lưu…' : dirty ? 'Lưu' : 'Đã lưu';
  }

  function refresh() {
    const t = tabs.get(active);
    if (!t) return;
    const dirty = isDirty(t);
    setDirty(dirty);
    const bytes = new Blob([t.session.getValue()]).size;
    el.size.textContent = bytes < 1024 ? `${bytes} B` : `${(bytes / 1024).toFixed(1).replace('.', ',')} KB`;
    el.savedAt.textContent = dirty ? 'Có thay đổi chưa lưu' : t.savedAt ? `Đã lưu lúc ${t.savedAt}` : 'Chưa sửa';
    el.savedAt.parentElement.classList.toggle('is-dirty', dirty);
    t.session.clearAnnotations();
  }

  editor.selection.on('changeCursor', () => {
    const c = editor.getCursorPosition();
    el.pos.textContent = `Dòng ${c.row + 1}, Cột ${c.column + 1}`;
  });

  function mark(line, text) {
    if (!line) return;
    editor.session.setAnnotations([{ row: line - 1, column: 0, text, type: 'error' }]);
    editor.gotoLine(line, 0, true);
  }

  /* ---------- Lưu / khôi phục ---------- */
  async function post(url, data) {
    const fd = new FormData();
    Object.entries(data).forEach(([k, v]) => fd.append(k, v));
    fd.append('_token', csrf());
    const r = await fetch(url, { method: 'POST', body: fd, headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
    try { return { status: r.status, ...(await r.json()) }; } catch (e) { return { ok: false, message: `Máy chủ trả về lỗi (${r.status}).` }; }
  }

  let saving = false;
  async function save(force = false) {
    const t = tabs.get(active);
    if (!t || saving || !t.writable || (!force && !isDirty(t))) return;
    saving = true;
    setDirty(true, true);
    const content = t.session.getValue();
    let res = null;
    let retry = false;
    try {
      res = await post('/admin/themes/editor/save', { theme, file: t.file, content, hash: t.hash, force: force ? '1' : '' });
      if (res.ok) {
        Object.assign(t, { hash: res.hash, saved: content, hasBackup: true, savedAt: new Date().toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit' }) });
        toast('success', res.message);
        renderBar(t);
      } else if (res.conflict) {
        // Lưu đè chạy SAU finally, nếu không finally của lượt này đặt saving = false khi lượt lưu đè còn chạy.
        retry = await ask({ title: 'Tệp đã bị thay đổi', text: res.message, danger: true, confirmText: 'Vẫn lưu đè' });
      } else {
        toast('error', res.message || 'Lưu thất bại.');
      }
    } catch (e) {
      toast('error', 'Không kết nối được máy chủ.');
    } finally {
      saving = false;
      refresh();
      renderTabs();
      // Đánh dấu sau refresh() vì refresh() xoá chú thích cũ.
      if (res?.line) mark(res.line, res.message);
    }
    if (retry) return save(true);
  }

  editor.commands.addCommand({ name: 'save', bindKey: { win: 'Ctrl-S', mac: 'Command-S' }, exec: () => save() });
  editor.commands.addCommand({ name: 'findFile', bindKey: { win: 'Ctrl-P', mac: 'Command-P' }, exec: () => filter?.focus() });
  el.save.addEventListener('click', () => save());
  document.addEventListener('keydown', (e) => {
    const k = e.key.toLowerCase();
    if ((e.metaKey || e.ctrlKey) && k === 's' && !editor.isFocused()) { e.preventDefault(); save(); }
    if ((e.metaKey || e.ctrlKey) && k === 'p' && !editor.isFocused()) { e.preventDefault(); filter?.focus(); filter?.select(); }
  });

  el.restore.addEventListener('click', async () => {
    const t = tabs.get(active);
    if (!t) return;
    // setValue xoá luôn lịch sử ⌘Z của tab: thay đổi chưa lưu sẽ mất hẳn, phải nói trước.
    const ok = await ask({ title: 'Khôi phục bản trước?', text: 'Tệp sẽ trở về nội dung ngay trước lần lưu gần nhất. Bản hiện tại được giữ làm bản sao lưu, bấm lại để quay về.'
      + (isDirty(t) ? ' Các thay đổi CHƯA LƯU trong tab này sẽ mất.' : ''), danger: isDirty(t), confirmText: 'Khôi phục' });
    if (!ok) return;
    const r = await post('/admin/themes/editor/restore', { theme, file: t.file });
    if (!r.ok) return toast('error', r.message);
    t.session.setValue(r.content);
    Object.assign(t, { saved: r.content, hash: r.hash, savedAt: new Date().toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit' }) });
    refresh();
    renderTabs();
    toast('success', r.message);
  });

  window.addEventListener('beforeunload', (e) => {
    if ([...tabs.values()].some(isDirty)) { e.preventDefault(); e.returnValue = ''; }
  });

  /* ---------- Tệp mới ---------- */
  const newForm = $('[data-te-new-form]');
  $('[data-te-new]')?.addEventListener('click', () => {
    newForm.hidden = false;
    const dir = active && active.includes('/') ? active.slice(0, active.lastIndexOf('/') + 1) : '';
    newForm.file.value = dir;
    newForm.file.focus();
  });
  const hideNew = () => { newForm.hidden = true; newForm.reset(); };
  $('[data-te-new-cancel]')?.addEventListener('click', hideNew);
  newForm?.file.addEventListener('keydown', (e) => { if (e.key === 'Escape') hideNew(); });
  newForm?.addEventListener('submit', async (e) => {
    e.preventDefault();
    if ([...tabs.values()].some(isDirty)) {
      const ok = await ask({ title: 'Có tệp chưa lưu', text: 'Tạo tệp sẽ tải lại trình sửa, các thay đổi chưa lưu sẽ mất.', danger: true, confirmText: 'Vẫn tạo' });
      if (!ok) return;
      tabs.forEach((t) => { if (t.loaded) t.saved = t.session.getValue(); });
    }
    const r = await post('/admin/themes/editor/create', { theme, file: newForm.file.value.trim() });
    if (!r.ok) return toast('error', r.message);
    const name = new URL(r.url, location.href).searchParams.get('file');
    const saved = store.sget(tabsKey, { list: [] });
    store.sset(tabsKey, { list: [...new Set([...(saved.list || []), name])], active: name });
    location.href = r.url;
  });

  /* ---------- Khởi động: khôi phục tab của phiên trước, mở tệp trên URL ---------- */
  const prev = store.sget(tabsKey, { list: [] });
  (prev.list || []).forEach((f) => { if (f !== initial.file && treeRow(f)) tabs.set(f, { file: f, loaded: false }); });
  // Giữ thứ tự tab cũ, chèn tệp trên URL vào đúng chỗ (hoặc cuối).
  const order = (prev.list || []).filter((f) => treeRow(f));
  if (!order.includes(initial.file)) order.push(initial.file);
  addTab(initial);
  const sorted = new Map(order.map((f) => [f, tabs.get(f)]).filter(([, t]) => t));
  tabs.clear();
  sorted.forEach((t, f) => tabs.set(f, t));
  applyWrap();
  open(initial.file, { line: parseInt(root.dataset.line, 10) || 0 });
  // Cho trợ lý AI (ai.js) đọc tệp đang mở và đưa bản sửa vào trình soạn thảo — người dùng vẫn tự bấm Lưu.
  window.LQ = Object.assign(window.LQ || {}, {
    themeEditor: {
      theme,
      current: () => (active && tabs.has(active) ? { file: active, content: tabs.get(active).session.getValue(), writable: tabs.get(active).writable } : null),
      // Thay qua Document để vẫn hoàn tác được bằng ⌘Z.
      replace: (text) => { if (active && tabs.has(active)) tabs.get(active).session.getDocument().setValue(text); },
      gotoLine: (n) => { editor.gotoLine(n, 0, true); editor.focus(); },
      open: (file, line = 0) => open(file, { line }),
      // Tệp mã của theme theo thứ tự trong cây (để rà toàn bộ theme).
      files: () => $$('[data-te-file]').map((a) => a.dataset.teFile),
    },
  });
})();
