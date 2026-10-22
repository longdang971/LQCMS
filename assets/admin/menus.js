/* Giao diện › Menu: dựng cây menu kéo thả lồng cấp (SortableJS), thêm mục từ cột nguồn, lưu JSON lên /admin/menus/save. */
(() => {
  const root = document.querySelector('[data-menu-editor]');
  if (!root) return;
  const $ = (s, el = document) => el.querySelector(s);
  const $$ = (s, el = document) => [...el.querySelectorAll(s)];
  const tree = $('[data-menu-tree]', root);
  const empty = $('[data-menu-empty]', root);
  const nameInput = $('[data-menu-name]', root);
  let dirty = false;

  $('[data-menu-pick]')?.addEventListener('change', (e) => {
    if (dirty && !window.confirm('Menu có thay đổi chưa lưu. Vẫn chuyển sang menu khác?')) { e.target.value = root.dataset.menuId || 'new'; return; }
    dirty = false;
    e.target.form.submit();
  });
  const touch = () => { dirty = true; empty.hidden = !!tree.children.length; };
  window.addEventListener('beforeunload', (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });

  const uid = () => Math.random().toString(36).slice(2, 10);
  const el = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text !== undefined) n.textContent = text; return n; };

  /** Một mục menu: hàng (tay kéo, nhãn, loại, nút mở) + khung sửa + danh sách con (để thả mục con vào). */
  function node(it) {
    const li = el('li', 'menu-node' + (it.missing ? ' is-missing' : ''));
    li.dataset.id = it.id || uid();
    li.dataset.type = it.type;
    li.dataset.object = it.object ?? '';
    li.dataset.auto = it.auto || '';

    const row = el('div', 'menu-node-row');
    const handle = el('span', 'menu-node-handle');
    handle.innerHTML = '<i data-lucide="grip-vertical"></i>';
    handle.title = 'Kéo để sắp xếp';
    const label = el('span', 'menu-node-label', it.label || it.auto || it.url || '(không tên)');
    const kind = el('span', 'menu-node-kind', it.missing ? 'Không còn — sẽ ẩn' : (it.kind || ''));
    const toggle = el('button', 'icon-btn sm menu-node-toggle');
    toggle.type = 'button';
    toggle.innerHTML = '<i data-lucide="chevron-down"></i>';
    toggle.setAttribute('aria-label', 'Sửa mục');
    row.append(handle, label, kind, toggle);

    const edit = el('div', 'menu-node-edit');
    edit.hidden = true;
    const field = (labelText, input) => { const f = el('label', 'field'); f.append(el('span', 'menu-field-label', labelText), input); return f; };
    const lab = el('input', 'input input-sm');
    lab.value = it.label || '';
    lab.placeholder = it.auto || 'Nhãn hiển thị';
    lab.maxLength = 100;
    lab.dataset.f = 'label';
    lab.addEventListener('input', () => { label.textContent = lab.value || it.auto || url?.value || '(không tên)'; touch(); });
    edit.append(field(it.type === 'custom' ? 'Nhãn' : 'Nhãn (để trống = theo ' + (it.kind || 'nội dung').toLowerCase() + ')', lab));
    let url = null;
    if (it.type === 'custom') {
      url = el('input', 'input input-sm mono');
      url.value = it.url || '';
      url.dataset.f = 'url';
      url.addEventListener('input', touch);
      edit.append(field('Địa chỉ', url));
    }
    const cls = el('input', 'input input-sm mono');
    cls.value = it.classes || '';
    cls.placeholder = 'vd. nut-noi-bat';
    cls.dataset.f = 'classes';
    cls.addEventListener('input', touch);
    edit.append(field('Class CSS (tuỳ chọn)', cls));
    const tab = el('label', 'check');
    const tabInput = el('input');
    tabInput.type = 'checkbox';
    tabInput.checked = !!it.new_tab;
    tabInput.dataset.f = 'new_tab';
    tabInput.addEventListener('change', touch);
    tab.append(tabInput, el('span', '', 'Mở trong tab mới'));
    const remove = el('button', 'btn-link-danger', 'Gỡ khỏi menu');
    remove.type = 'button';
    remove.addEventListener('click', () => {
      // Gỡ mục cha: các mục con lên thế chỗ (không mất cả nhánh).
      const kids = [...li.querySelector(':scope > ol').children];
      kids.forEach((k) => li.before(k));
      li.remove();
      touch();
    });
    const foot = el('div', 'menu-node-foot');
    foot.append(tab, remove);
    edit.append(foot);

    toggle.addEventListener('click', () => { edit.hidden = !edit.hidden; li.classList.toggle('is-open', !edit.hidden); });
    label.addEventListener('click', () => toggle.click());

    const kids = el('ol', 'menu-children');
    (it.children || []).forEach((c) => kids.append(node(c)));
    li.append(row, edit, kids);
    sortable(kids);
    return li;
  }

  function sortable(ol) {
    window.Sortable?.create(ol, {
      group: 'menu', handle: '.menu-node-handle', animation: 150, fallbackOnBody: true, swapThreshold: 0.65,
      onEnd: touch,
    });
  }

  function serialize(ol) {
    return [...ol.children].map((li) => {
      const f = (k) => $(`:scope > .menu-node-edit [data-f="${k}"]`, li);
      return {
        id: li.dataset.id,
        type: li.dataset.type,
        object: li.dataset.object === '' ? null : li.dataset.object,
        label: f('label')?.value.trim() || '',
        url: f('url')?.value.trim() || '',
        classes: f('classes')?.value.trim() || '',
        new_tab: !!f('new_tab')?.checked,
        children: serialize($(':scope > ol', li)),
      };
    });
  }

  // Dựng cây ban đầu.
  JSON.parse($('#menu-data')?.textContent || '[]').forEach((it) => tree.append(node(it)));
  sortable(tree);
  empty.hidden = !!tree.children.length;
  window.lucide?.createIcons();

  /* ---------- Thêm mục ---------- */
  $$('[data-src-filter]').forEach((input) => input.addEventListener('input', () => {
    const q = input.value.trim().toLowerCase();
    $$('.check', input.closest('.menu-src')).forEach((c) => { c.hidden = q !== '' && !c.textContent.toLowerCase().includes(q); });
  }));
  const append = (items) => {
    items.forEach((it) => {
      const n = node(it);
      tree.append(n);
      n.classList.add('is-new');
      setTimeout(() => n.classList.remove('is-new'), 1200);
    });
    window.lucide?.createIcons();
    touch();
  };
  $$('[data-src-add]').forEach((btn) => btn.addEventListener('click', () => {
    const box = btn.closest('.menu-src');
    const picked = $$('[data-src-item]:checked', box);
    if (!picked.length) { LQ.toast('warning', 'Hãy tích ít nhất một mục.'); return; }
    append(picked.map((c) => ({ type: c.dataset.type, object: c.dataset.object || null, label: '', auto: c.dataset.label, kind: c.dataset.kind })));
    picked.forEach((c) => { c.checked = false; });
  }));
  $('[data-custom-add]')?.addEventListener('click', () => {
    const urlEl = $('[data-custom-url]');
    const labEl = $('[data-custom-label]');
    const url = urlEl.value.trim();
    if (!/^(https?:\/\/|\/|#|mailto:|tel:)/i.test(url)) { LQ.toast('error', 'Địa chỉ phải bắt đầu bằng http(s)://, /, #, mailto: hoặc tel:'); urlEl.focus(); return; }
    append([{ type: 'custom', url, label: labEl.value.trim() || url, kind: 'Liên kết' }]);
    urlEl.value = '';
    labEl.value = '';
  });

  nameInput.addEventListener('input', touch);
  $$('[data-menu-location]').forEach((c) => c.addEventListener('change', touch));

  /* ---------- Lưu ---------- */
  const saveBtn = $('[data-menu-save]', root);
  saveBtn.addEventListener('click', async () => {
    if (!nameInput.value.trim()) { LQ.toast('error', 'Đặt tên cho menu trước khi lưu.'); nameInput.focus(); return; }
    saveBtn.disabled = true;
    try {
      const r = await fetch('/admin/menus/save', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': LQ.csrf() },
        body: JSON.stringify({
          id: +root.dataset.menuId || 0,
          name: nameInput.value.trim(),
          items: serialize(tree),
          locations: $$('[data-menu-location]:checked').map((c) => c.value),
        }),
      });
      const d = await r.json().catch(() => ({ ok: false, message: `Máy chủ trả về lỗi (${r.status}).` }));
      if (!d.ok) { LQ.toast('error', d.message || 'Không lưu được menu.'); return; }
      dirty = false;
      if (!+root.dataset.menuId) { location.href = d.url; return; } // menu mới: tải lại để có id + danh sách menu
      LQ.toast('success', d.message);
    } catch (e) {
      LQ.toast('error', 'Không kết nối được máy chủ.');
    } finally {
      saveBtn.disabled = false;
    }
  });
  document.addEventListener('keydown', (e) => {
    if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 's') { e.preventDefault(); saveBtn.click(); }
  });
})();
