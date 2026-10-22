/* LQCMS — thư viện media: trang Media, hộp chọn ảnh LQ.pickMedia(), ảnh đại diện, field ảnh. */
(function () {
  'use strict';

  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));
  const LQ = window.LQ || {};
  const toast = (t, m) => (LQ.toast ? LQ.toast(t, m) : alert(m));
  const csrf = () => $('meta[name="csrf-token"]')?.content || '';
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const icons = () => window.lucide && lucide.createIcons();
  const TYPES = /^image\/(jpeg|png|gif|webp|avif)$/;

  /* ---------------- API ---------------- */
  const api = {
    list: (params) => fetch('/admin/api/media?' + new URLSearchParams(params), { headers: { Accept: 'application/json' } }).then((r) => r.json()),
    show: (id) => fetch('/admin/api/media/' + id, { headers: { Accept: 'application/json' } }).then((r) => r.json()),
    post: (url, data) => {
      const fd = data instanceof FormData ? data : new FormData();
      if (!(data instanceof FormData)) Object.entries(data || {}).forEach(([k, v]) => (Array.isArray(v) ? v.forEach((x) => fd.append(k + '[]', x)) : fd.append(k, v)));
      return fetch(url, { method: 'POST', body: fd, headers: { Accept: 'application/json', 'X-CSRF-Token': csrf() } }).then((r) => r.json());
    },
    // XHR để có tiến trình tải lên. name: tên muốn đặt cho tệp (máy chủ chuyển thành slug), bỏ trống = theo tên tệp gốc.
    upload: (file, onProgress, name = '') => new Promise((resolve) => {
      const fd = new FormData();
      fd.append('file', file);
      if (name) fd.append('name', name);
      const xhr = new XMLHttpRequest();
      xhr.open('POST', '/admin/media/upload');
      xhr.setRequestHeader('X-CSRF-Token', csrf());
      xhr.setRequestHeader('Accept', 'application/json');
      xhr.upload.onprogress = (e) => e.lengthComputable && onProgress && onProgress(e.loaded / e.total);
      xhr.onload = () => { let d = {}; try { d = JSON.parse(xhr.responseText); } catch (_) {} resolve(d.ok ? d : { ok: false, message: d.message || 'Tải lên thất bại (' + xhr.status + ').' }); };
      xhr.onerror = () => resolve({ ok: false, message: 'Mất kết nối khi tải lên.' });
      xhr.send(fd);
    }),
  };

  const maxMb = () => +($('[data-media-page]')?.dataset.maxMb || window.LQ_UPLOAD_MAX_MB || 8);
  /** Kiểm tra trước ở trình duyệt để báo lỗi nhanh (server vẫn kiểm tra lại). */
  const precheck = (file) => {
    if (!TYPES.test(file.type)) return `"${file.name}": chỉ hỗ trợ ảnh JPG, PNG, GIF, WEBP, AVIF.`;
    if (file.size > maxMb() * 1048576) return `"${file.name}" vượt quá ${maxMb()} MB.`;
    return null;
  };

  /** Ảnh tải lên trong phiên này mà chưa có tên mong muốn (vd. bài chưa có slug) — khung ảnh đại diện báo để lúc lưu bài đặt lại tên. */
  const unnamed = new Set();

  /** Tải nhiều tệp lần lượt, hiện tiến trình trong khung queue; trả về các ảnh đã lên. */
  async function uploadMany(files, queue, name = '') {
    const done = [];
    for (const file of files) {
      const err = precheck(file);
      if (err) { toast('error', err); continue; }
      const row = document.createElement('div');
      row.className = 'upload-row';
      row.innerHTML = `<span class="upload-name">${esc(file.name)}</span><span class="upload-bar"><span></span></span><span class="upload-pct">0%</span>`;
      if (queue) { queue.hidden = false; queue.appendChild(row); }
      const bar = $('.upload-bar span', row);
      const pct = $('.upload-pct', row);
      const wanted = typeof name === 'function' ? name() : name;
      const res = await api.upload(file, (p) => { bar.style.width = Math.round(p * 100) + '%'; pct.textContent = Math.round(p * 100) + '%'; }, wanted);
      if (res.ok) {
        if (!wanted) unnamed.add(res.item.id);
        row.classList.add('is-done');
        pct.textContent = 'Xong';
        done.push(res.item);
      } else {
        row.classList.add('is-error');
        pct.textContent = 'Lỗi';
        toast('error', res.message);
      }
      setTimeout(() => { row.remove(); if (queue && !queue.children.length) queue.hidden = true; }, res.ok ? 900 : 4000);
    }
    return done;
  }

  const tile = (m, { selectable = false } = {}) =>
    `<button type="button" class="media-tile" data-media-id="${m.id}" title="${esc(m.file_name)}">
       <img src="${esc(m.url)}" alt="${esc(m.alt)}" loading="lazy" decoding="async">
       <span class="media-tile-meta">${esc(m.ext)}${m.width ? ` · ${m.width}×${m.height}` : ''}</span>
       ${selectable ? '<span class="media-check"><i data-lucide="check"></i></span>' : ''}
     </button>`;

  /** Lưới ảnh có tìm kiếm, lọc tháng, tải dần khi cuộn. */
  function createGrid({ grid, more, empty, search, month, onRender }) {
    const state = { page: 1, q: '', month: '', loading: false, more: false, items: new Map() };
    // Lượt tải mới nhất: đổi từ khoá / tháng giữa lúc đang tải thì vẫn tải lại (không bỏ qua), kết quả của lượt cũ về sau bị bỏ.
    let seq = 0;
    const load = (reset) => {
      if (state.loading && !reset) return;
      const my = ++seq;
      if (reset) { state.page = 1; state.items.clear(); grid.innerHTML = '<div class="media-skel"></div>'.repeat(12); }
      state.loading = true;
      api.list({ q: state.q, month: state.month, page: state.page }).then((d) => {
        if (my !== seq) return;
        if (!d.ok) throw new Error(d.message);
        if (reset) grid.innerHTML = '';
        d.items.forEach((m) => { state.items.set(m.id, m); grid.insertAdjacentHTML('beforeend', tile(m, { selectable: true })); });
        state.more = d.more;
        if (more) more.hidden = !d.more;
        if (empty) empty.hidden = d.total > 0 || state.q !== '' || state.month !== '';
        if (!d.items.length && reset && (state.q || state.month)) grid.innerHTML = '<p class="media-none">Không có ảnh phù hợp.</p>';
        icons();
        onRender && onRender(d);
      }).catch(() => { if (my === seq) toast('error', 'Không tải được thư viện media.'); }).finally(() => { if (my === seq) state.loading = false; });
    };
    let t;
    search && search.addEventListener('input', () => { clearTimeout(t); t = setTimeout(() => { state.q = search.value.trim(); load(true); }, 250); });
    month && month.addEventListener('change', () => { state.month = month.value; load(true); });
    more && $('button', more).addEventListener('click', () => { state.page++; load(false); });
    // Tự tải thêm khi cuộn gần cuối.
    if (more && 'IntersectionObserver' in window) {
      new IntersectionObserver((es) => { if (es[0].isIntersecting && state.more && !state.loading) { state.page++; load(false); } }, { rootMargin: '400px' }).observe(more);
    }
    return {
      state,
      load,
      prepend(items) { items.forEach((m) => { state.items.set(m.id, m); grid.insertAdjacentHTML('afterbegin', tile(m, { selectable: true })); }); if (empty) empty.hidden = true; icons(); },
      remove(id) { state.items.delete(id); $(`[data-media-id="${id}"]`, grid)?.remove(); },
      update(m) { state.items.set(m.id, m); const el = $(`[data-media-id="${m.id}"] img`, grid); if (el) el.alt = m.alt; },
    };
  }

  /* ---------------- Trang Thư viện media ---------------- */
  const page = $('[data-media-page]');
  if (page) {
    const gridEl = $('[data-media-grid]', page);
    const queue = $('[data-upload-queue]', page);
    const drawer = $('[data-media-drawer]');
    const detail = $('[data-media-detail]');
    const bulk = $('[data-media-bulk]', page);
    const totalEl = $('[data-media-total]');
    let selectMode = false;
    const selected = new Set();
    let current = null;

    const grid = createGrid({ grid: gridEl, more: $('[data-media-more]', page), empty: $('[data-media-empty]', page), search: $('[data-media-search]', page), month: $('[data-media-month]', page) });
    grid.load(true);
    const bump = (n) => { if (totalEl) totalEl.textContent = (Math.max(0, parseInt(totalEl.textContent.replace(/\D/g, ''), 10) + n)).toLocaleString('vi-VN'); };

    const doUpload = async (files) => {
      const items = await uploadMany([...files], queue);
      if (items.length) { grid.prepend(items); bump(items.length); toast('success', `Đã tải lên ${items.length} ảnh.`); }
    };
    $('[data-media-upload-input]').addEventListener('change', (e) => { doUpload(e.target.files); e.target.value = ''; });

    // Kéo thả ảnh vào bất kỳ đâu trên trang.
    const overlay = $('[data-drop-overlay]');
    let depth = 0;
    const hasFiles = (e) => [...(e.dataTransfer?.types || [])].includes('Files');
    window.addEventListener('dragenter', (e) => { if (!hasFiles(e)) return; depth++; overlay.hidden = false; });
    window.addEventListener('dragleave', (e) => { if (!hasFiles(e)) return; if (--depth <= 0) { depth = 0; overlay.hidden = true; } });
    window.addEventListener('dragover', (e) => { if (hasFiles(e)) e.preventDefault(); });
    window.addEventListener('drop', (e) => { if (!hasFiles(e)) return; e.preventDefault(); depth = 0; overlay.hidden = true; doUpload(e.dataTransfer.files); });

    // Chọn nhiều để xoá
    const syncBulk = () => {
      bulk.hidden = !selectMode;
      $('[data-media-bulk-count]', bulk).textContent = selected.size;
      $('[data-media-bulk-delete]', bulk).disabled = !selected.size;
      gridEl.classList.toggle('is-selecting', selectMode);
      $$('.media-tile', gridEl).forEach((t) => t.classList.toggle('is-selected', selected.has(+t.dataset.mediaId)));
    };
    const exitSelect = () => { selectMode = false; selected.clear(); syncBulk(); };
    $('[data-media-select-mode]').addEventListener('click', () => { selectMode = !selectMode; selected.clear(); closeDrawer(); syncBulk(); });
    $('[data-media-bulk-cancel]', bulk).addEventListener('click', exitSelect);
    $('[data-media-bulk-delete]', bulk).addEventListener('click', () => {
      const ids = [...selected];
      LQ.confirm({ title: `Xoá vĩnh viễn ${ids.length} ảnh?`, text: 'Tệp ảnh sẽ bị xoá khỏi máy chủ. Bài viết đang dùng các ảnh này sẽ không còn hiển thị chúng.', danger: true, confirmText: 'Xoá' })
        .then((ok) => ok && api.post('/admin/media/bulk-delete', { ids }).then((d) => {
          if (!d.ok) { toast('error', d.message || 'Xoá thất bại.'); return; }
          // Chỉ gỡ ảnh máy chủ đã xoá thật — ảnh bị bỏ qua (không có quyền) vẫn còn trong thư viện.
          (d.ids || []).forEach((id) => grid.remove(id));
          bump(-d.deleted);
          toast(d.skipped ? 'warning' : 'success', `Đã xoá ${d.deleted} ảnh` + (d.skipped ? `, bỏ qua ${d.skipped} ảnh không có quyền xoá.` : '.'));
          exitSelect();
        }));
    });

    // Dọn dẹp: xem trước các ảnh không dùng đến (máy chủ dò), xác nhận rồi mới xoá.
    const cleanBtn = $('[data-media-cleanup]');
    cleanBtn?.addEventListener('click', async () => {
      cleanBtn.disabled = true;
      cleanBtn.classList.add('is-loading');
      const d = await fetch('/admin/media/unused', { headers: { Accept: 'application/json' } }).then((r) => r.json()).catch(() => ({ ok: false, message: 'Mất kết nối.' }));
      cleanBtn.disabled = false;
      cleanBtn.classList.remove('is-loading');
      if (!d.ok) { toast('error', d.message || 'Không quét được thư viện.'); return; }
      if (!d.items.length) { toast('success', 'Thư viện gọn gàng — không có ảnh nào thừa.'); return; }
      const n = d.items.length;
      const size = d.total >= 1048576 ? `${(d.total / 1048576).toFixed(1).replace('.', ',')} MB` : `${Math.max(1, Math.round(d.total / 1024))} KB`;
      const r = await Swal.fire({
        title: `${n} ảnh không dùng đến`,
        html: `<p class="ai-choose-sub">Không bài viết, trang, field tuỳ biến, cài đặt hay theme/plugin nào dùng các ảnh này · giải phóng ${size}. `
          + 'Ảnh tải lên chưa tới 24 giờ được giữ lại. Bỏ chọn ảnh muốn giữ.</p>'
          + `<div class="cleanup-grid">${d.items.map((m) => `<label class="cleanup-item" title="${esc(m.file_name)} · ${esc(m.size_label)}">`
            + `<input type="checkbox" value="${m.id}" checked><img src="${esc(m.url)}" alt="" loading="lazy"><span>${esc(m.url.split('/').pop())}</span></label>`).join('')}</div>`,
        showCancelButton: true, reverseButtons: true, buttonsStyling: false, focusCancel: true,
        confirmButtonText: `Xoá ${n} ảnh`, cancelButtonText: 'Huỷ',
        customClass: { popup: 'lq-swal lq-swal-wide', confirmButton: 'btn btn-danger', cancelButton: 'btn btn-secondary' },
        didOpen: (pop) => pop.addEventListener('change', () => {
          const k = $$('.cleanup-item input:checked', pop).length;
          Swal.getConfirmButton().textContent = `Xoá ${k} ảnh`;
          Swal.getConfirmButton().disabled = !k;
        }),
        preConfirm: () => $$('.cleanup-item input:checked', Swal.getPopup()).map((i) => +i.value),
      });
      if (!r.isConfirmed || !r.value.length) return;
      const res = await api.post('/admin/media/cleanup', { ids: r.value });
      if (!res.ok) { toast('error', res.message || 'Xoá thất bại.'); return; }
      (res.ids || []).forEach((id) => grid.remove(id)); // ảnh vừa được dùng trở lại thì máy chủ giữ, lưới cũng giữ
      bump(-res.deleted);
      toast(res.skipped ? 'warning' : 'success', `Đã xoá ${res.deleted} ảnh không dùng đến` + (res.skipped ? `, giữ lại ${res.skipped} ảnh vừa được dùng.` : '.'));
    });

    // Mở từ ⌘K: /admin/media#cleanup → hộp dọn dẹp; /admin/media?open=ID → chi tiết một ảnh.
    const deep = new URLSearchParams(location.search).get('open');
    if (location.hash === '#cleanup' || deep) {
      history.replaceState(null, '', location.pathname);
      setTimeout(() => (deep ? showDetail(+deep) : cleanBtn?.click()), 0); // showDetail khai báo bên dưới
    }
    // Đang ở trang Media mà chọn "Dọn dẹp" trong ⌘K: chỉ hash đổi, trang không tải lại.
    window.addEventListener('hashchange', () => {
      if (location.hash === '#cleanup') { history.replaceState(null, '', location.pathname); cleanBtn?.click(); }
    });

    // Bảng chi tiết
    const openDrawer = () => document.body.classList.add('drawer-open');
    function closeDrawer() { document.body.classList.remove('drawer-open'); $$('.media-tile.is-current', gridEl).forEach((t) => t.classList.remove('is-current')); current = null; }
    $('[data-media-drawer-close]').addEventListener('click', closeDrawer);
    // Bấm nhanh ảnh A rồi B: phản hồi của A về sau không được đè lên B (current sai thì phím Delete xoá nhầm ảnh A).
    let showSeq = 0;
    const showDetail = (id) => {
      const my = ++showSeq;
      current = null;
      $$('.media-tile', gridEl).forEach((t) => t.classList.toggle('is-current', +t.dataset.mediaId === id));
      detail.innerHTML = '<div class="media-skel" style="aspect-ratio:4/3"></div>';
      openDrawer();
      api.show(id).then((d) => {
        if (my !== showSeq) return;
        if (!d.ok) { toast('error', d.message || 'Không tải được thông tin ảnh.'); closeDrawer(); return; }
        current = d.item;
        const m = d.item;
        const abs = location.origin + m.url;
        $('[data-md-name]').textContent = m.file_name;
        detail.innerHTML = `
          <a class="md-preview" href="${esc(m.url)}" target="_blank" rel="noopener"><img src="${esc(m.url)}" alt="${esc(m.alt)}"></a>
          <dl class="kv compact">
            <dt>Loại</dt><dd>${esc(m.ext)}</dd>
            <dt>Kích thước</dt><dd>${m.width ? `${m.width} × ${m.height} px` : '—'}</dd>
            <dt>Dung lượng</dt><dd>${esc(m.size_label)}</dd>
            <dt>Tải lên</dt><dd>${esc(m.date_label)}</dd>
            <dt>Người tải</dt><dd>${esc(m.author_name || '—')}</dd>
          </dl>
          <div class="field">
            <label>Đường dẫn</label>
            <div class="copy-row"><input class="input mono" value="${esc(abs)}" readonly data-md-url><button type="button" class="btn btn-secondary btn-sm" data-md-copy><i data-lucide="copy"></i> Sao chép</button></div>
          </div>
          <form class="stack" style="gap:12px" data-md-form>
            <div class="field"><label>Văn bản thay thế (Alt)</label><input class="input" name="alt" value="${esc(m.alt)}" maxlength="255" placeholder="Mô tả ảnh cho người khiếm thị và SEO" ${m.can_edit ? '' : 'disabled'}></div>
            <div class="field"><label>Chú thích</label><textarea class="input" name="caption" rows="2" ${m.can_edit ? '' : 'disabled'}>${esc(m.caption)}</textarea></div>
            ${m.can_edit ? '<div><button class="btn btn-primary btn-sm" type="submit">Lưu thông tin</button></div>' : '<p class="help">Bạn chỉ sửa được ảnh do mình tải lên.</p>'}
          </form>
          <div class="field">
            <label>Đang được dùng ở</label>
            ${m.usage.length
              // hidden: bài chưa đăng của người khác (máy chủ không gửi tiêu đề); không sửa được thì không đặt link (bấm vào sẽ bị 403).
              ? '<ul class="mini-list">' + m.usage.map((u) => `<li>${u.hidden ? '<em>Bài chưa đăng của người khác</em>'
                : u.editable === false || !u.edit_url ? esc(u.title || '(Không tiêu đề)')
                : `<a href="${esc(u.edit_url)}">${esc(u.title || '(Không tiêu đề)')}</a>`}<span>${esc(u.kind)}</span></li>`).join('') + '</ul>'
              : '<p class="help">Chưa được bài viết hay trang nào dùng.</p>'}
          </div>
          <div class="md-actions">
            <a class="btn btn-secondary btn-sm" href="${esc(m.url)}" download><i data-lucide="download"></i> Tải về</a>
            ${m.can_edit ? '<button type="button" class="btn btn-danger-soft btn-sm" data-md-delete><i data-lucide="trash-2"></i> Xoá ảnh</button>' : ''}
          </div>`;
        icons();
      });
    };
    detail.addEventListener('click', (e) => {
      if (e.target.closest('[data-md-copy]')) {
        const inp = $('[data-md-url]', detail);
        (navigator.clipboard ? navigator.clipboard.writeText(inp.value) : Promise.reject()).then(() => toast('success', 'Đã sao chép đường dẫn.')).catch(() => { inp.select(); document.execCommand('copy'); toast('success', 'Đã sao chép đường dẫn.'); });
      }
      if (e.target.closest('[data-md-delete]') && current) {
        const m = current;
        // Tên tệp do người tải lên đặt: chỉ để ở text (title của SweetAlert được vẽ như HTML).
        LQ.confirm({ title: 'Xoá vĩnh viễn ảnh này?', text: `"${m.file_name}" — ` + (m.usage.length ? `ảnh đang được ${m.usage.length} bài viết/trang dùng; các chỗ đó sẽ không còn hiển thị ảnh này.` : 'tệp ảnh sẽ bị xoá khỏi máy chủ.'), danger: true, confirmText: 'Xoá ảnh' })
          .then((ok) => ok && api.post(`/admin/media/${m.id}/delete`).then((d) => {
            if (!d.ok) { toast('error', d.message); return; }
            grid.remove(m.id); bump(-1); closeDrawer(); toast('success', 'Đã xoá ảnh.');
          }));
      }
    });
    detail.addEventListener('submit', (e) => {
      const form = e.target.closest('[data-md-form]');
      if (!form || !current) return;
      e.preventDefault();
      const btn = $('button[type="submit"]', form);
      btn.disabled = true;
      api.post(`/admin/media/${current.id}/update`, { alt: form.alt.value, caption: form.caption.value }).then((d) => {
        if (!d.ok) { toast('error', d.message); return; }
        grid.update(d.item); current = { ...current, ...d.item }; toast('success', 'Đã lưu thông tin ảnh.');
      }).finally(() => { btn.disabled = false; });
    }, true);

    // Chọn nhiều như Finder/Explorer: giữ ⌘/Ctrl để chọn từng ảnh, Shift để chọn cả dải từ ảnh bấm trước đó.
    let anchor = null;
    const tileIds = () => $$('.media-tile', gridEl).map((t) => +t.dataset.mediaId);
    const enterSelect = () => { if (!selectMode) { selectMode = true; closeDrawer(); } };
    gridEl.addEventListener('click', (e) => {
      const t = e.target.closest('.media-tile');
      if (!t) return;
      const id = +t.dataset.mediaId;
      if (e.shiftKey && (selectMode || anchor !== null)) {
        enterSelect();
        const ids = tileIds();
        // Ảnh mốc đã bị xoá khỏi lưới (indexOf = -1): chỉ chọn ảnh vừa bấm, không lan từ đầu lưới.
        const from = ids.indexOf(anchor ?? id);
        const [a, b] = [from < 0 ? ids.indexOf(id) : from, ids.indexOf(id)].sort((x, y) => x - y);
        ids.slice(a, b + 1).forEach((x) => selected.add(x));
        syncBulk();
        return;
      }
      if (e.metaKey || e.ctrlKey) enterSelect();
      anchor = id;
      if (selectMode) { selected.has(id) ? selected.delete(id) : selected.add(id); syncBulk(); return; }
      showDetail(id);
    });
    const hint = $('[data-media-bulk-hint]', bulk);
    if (hint) hint.textContent = `${LQ.isMac ? '⌘' : 'Ctrl+'}A chọn tất cả · ${LQ.isMac ? '⌫' : 'Del'} xoá · Esc thoát`;
    document.addEventListener('keydown', (e) => {
      // Không cướp phím khi đang gõ chữ, hoặc khi có hộp thoại / thanh ⌘K / khung chat đang mở.
      const typing = e.target.closest?.('input, textarea, select, [contenteditable="true"]');
      const overlay = document.querySelector('.swal2-container, .cmdk:not([hidden])') || document.body.classList.contains('picker-open');
      if (e.key === 'Escape') {
        if (overlay || e.defaultPrevented) return; // ⌘K vừa đóng bằng chính lần nhấn này
        if (document.body.classList.contains('drawer-open')) closeDrawer(); else if (selectMode) exitSelect();
        return;
      }
      if (typing || overlay) return;
      if ((e.metaKey || e.ctrlKey) && !e.altKey && !e.shiftKey && e.key.toLowerCase() === 'a') {
        e.preventDefault();
        enterSelect();
        tileIds().forEach((x) => selected.add(x));
        syncBulk();
      } else if (e.key === 'Delete' || e.key === 'Backspace') {
        if (selectMode && selected.size) { e.preventDefault(); $('[data-media-bulk-delete]', bulk).click(); }
        else if (current) { e.preventDefault(); $('[data-md-delete]')?.click(); }
      }
    });
  }

  /* ---------------- Hộp chọn ảnh: LQ.pickMedia() → Promise<ảnh | null> ---------------- */
  let picker = null;
  function buildPicker() {
    const el = document.createElement('div');
    el.className = 'picker';
    el.hidden = true;
    el.innerHTML = `
      <div class="picker-backdrop" data-picker-close></div>
      <div class="picker-panel" role="dialog" aria-modal="true" aria-label="Chọn ảnh">
        <div class="picker-head">
          <h3 data-picker-title>Chọn ảnh</h3>
          <div class="input-icon picker-search"><i data-lucide="search"></i><input class="input" type="search" placeholder="Tìm ảnh…" data-picker-search></div>
          <label class="btn btn-secondary btn-sm"><i data-lucide="upload"></i> Tải lên<input type="file" accept="image/jpeg,image/png,image/gif,image/webp,image/avif" multiple hidden data-picker-upload></label>
          <button class="icon-btn" type="button" data-picker-close aria-label="Đóng"><i data-lucide="x"></i></button>
        </div>
        <div class="picker-body" data-picker-body>
          <div class="upload-queue" data-picker-queue hidden></div>
          <div class="media-grid is-picking" data-picker-grid></div>
          <div class="media-more" data-picker-more hidden><button class="btn btn-secondary btn-sm" type="button">Tải thêm</button></div>
          <div class="dash-empty" data-picker-empty hidden><i data-lucide="images"></i><h3>Thư viện chưa có ảnh</h3><p class="muted">Kéo ảnh vào đây hoặc bấm “Tải lên”.</p></div>
        </div>
        <div class="picker-foot">
          <span class="picker-info" data-picker-info>Chưa chọn ảnh nào</span>
          <button class="btn btn-ghost" type="button" data-picker-close>Huỷ</button>
          <button class="btn btn-accent" type="button" data-picker-ok disabled>Chọn ảnh</button>
        </div>
      </div>`;
    document.body.appendChild(el);
    icons();
    const gridEl = $('[data-picker-grid]', el);
    const g = createGrid({ grid: gridEl, more: $('[data-picker-more]', el), empty: $('[data-picker-empty]', el), search: $('[data-picker-search]', el) });
    const p = { el, grid: g, selected: null, resolve: null };
    const select = (id) => {
      p.selected = g.state.items.get(id) || null;
      $$('.media-tile', gridEl).forEach((t) => t.classList.toggle('is-selected', +t.dataset.mediaId === id));
      $('[data-picker-ok]', el).disabled = !p.selected;
      $('[data-picker-info]', el).textContent = p.selected ? `${p.selected.file_name}${p.selected.width ? ` · ${p.selected.width}×${p.selected.height}` : ''} · ${p.selected.size_label}` : 'Chưa chọn ảnh nào';
    };
    const finish = (val) => { el.hidden = true; document.body.classList.remove('picker-open'); const r = p.resolve; p.resolve = null; r && r(val); };
    gridEl.addEventListener('click', (e) => { const t = e.target.closest('.media-tile'); if (t) select(+t.dataset.mediaId); });
    gridEl.addEventListener('dblclick', (e) => { const t = e.target.closest('.media-tile'); if (t) { select(+t.dataset.mediaId); finish(p.selected); } });
    el.addEventListener('click', (e) => { if (e.target.closest('[data-picker-close]')) finish(null); if (e.target.closest('[data-picker-ok]')) finish(p.selected); });
    el.addEventListener('keydown', (e) => { if (e.key === 'Escape') { e.stopPropagation(); finish(null); } });
    const up = async (files) => {
      const items = await uploadMany([...files], $('[data-picker-queue]', el), p.name);
      if (items.length) { g.prepend(items); select(items[items.length - 1].id); }
    };
    $('[data-picker-upload]', el).addEventListener('change', (e) => { up(e.target.files); e.target.value = ''; });
    const body = $('[data-picker-body]', el);
    body.addEventListener('dragover', (e) => { e.preventDefault(); body.classList.add('is-over'); });
    body.addEventListener('dragleave', () => body.classList.remove('is-over'));
    body.addEventListener('drop', (e) => { e.preventDefault(); body.classList.remove('is-over'); if (e.dataTransfer.files.length) up(e.dataTransfer.files); });
    p.open = (opts = {}) => new Promise((resolve) => {
      p.resolve = resolve;
      p.name = opts.name || ''; // tên đặt cho ảnh tải lên trong lần mở này (vd. slug bài cho ảnh đại diện)
      $('[data-picker-title]', el).textContent = opts.title || 'Chọn ảnh';
      el.hidden = false;
      document.body.classList.add('picker-open');
      p.selected = null;
      $('[data-picker-ok]', el).disabled = true;
      $('[data-picker-info]', el).textContent = 'Chưa chọn ảnh nào';
      g.load(true);
      setTimeout(() => $('[data-picker-search]', el).focus(), 50);
    });
    return p;
  }
  LQ.pickMedia = (opts) => { picker = picker || buildPicker(); return picker.open(opts); };
  LQ.uploadMedia = (files) => uploadMany([...files], null);
  window.LQ = LQ;

  /* ---------------- Ảnh đại diện ---------------- */
  $$('[data-featured]').forEach((box) => {
    const card = box.closest('.card');
    const value = $('[data-featured-value]', box);
    const preview = $('.featured-preview', box);
    const img = $('[data-featured-img]', box);
    const drop = $('[data-featured-drop]', box);
    const clear = $('[data-featured-clear]', card);
    const pending = $('[data-featured-pending]', box);
    const set = (url, item = null) => {
      value.value = url || '';
      // Ảnh vừa tải lên khi bài chưa có slug: máy chủ đặt lại tên theo slug lúc lưu bài.
      if (pending) pending.value = item && unnamed.has(item.id) ? item.id : '';
      img.src = url || '';
      preview.hidden = !url;
      drop.hidden = !!url;
      clear.hidden = !url;
      value.dispatchEvent(new Event('change', { bubbles: true }));
    };
    const file = $('[data-featured-file]', box);
    // Ảnh đại diện tải lên mang tên theo slug bài/trang (ô slug, hoặc dựng từ tiêu đề nếu chưa có) — tốt cho SEO ảnh.
    const form = box.closest('form');
    const slugName = () => (form?.querySelector('[name="slug"]')?.value.trim() || (LQ.slugify ? LQ.slugify(form?.querySelector('[name="title"]')?.value || '') : ''));
    const upload = (f) => {
      if (!f) return;
      drop.classList.add('is-loading');
      uploadMany([f], null, slugName).then((items) => { if (items[0]) { set(items[0].url, items[0]); toast('success', 'Đã tải ảnh lên thư viện.'); } }).finally(() => drop.classList.remove('is-loading'));
    };
    box.addEventListener('click', (e) => {
      // Nút "Chọn từ thư viện" (và ảnh đang có) mở thư viện; bấm chỗ khác trong khung kéo thả thì mở hộp chọn tệp để tải lên.
      if (e.target.closest('[data-featured-pick]')) LQ.pickMedia({ title: 'Chọn ảnh đại diện', name: slugName }).then((m) => m && set(m.url, m));
      else if (e.target.closest('[data-featured-drop]') && e.target !== file) file.click();
    });
    drop.addEventListener('keydown', (e) => {
      if (e.target === drop && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); file.click(); }
    });
    file.addEventListener('change', () => { upload(file.files[0]); file.value = ''; });
    clear.addEventListener('click', () => set(''));
    ['dragenter', 'dragover'].forEach((ev) => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.add('is-over'); }));
    ['dragleave', 'drop'].forEach((ev) => drop.addEventListener(ev, () => drop.classList.remove('is-over')));
    drop.addEventListener('drop', (e) => {
      e.preventDefault();
      upload(e.dataTransfer.files[0]);
    });
  });

  /* ---------------- Field kiểu ảnh của theme/plugin ---------------- */
  document.addEventListener('click', (e) => {
    const pick = e.target.closest('[data-image-pick]');
    if (!pick) return;
    const box = pick.closest('[data-image-field]');
    LQ.pickMedia().then((m) => {
      if (!m) return;
      $('[data-image-value]', box).value = m.url;
      const im = $('[data-image-preview]', box);
      im.src = m.url; im.hidden = false;
      $('[data-image-clear]', box).hidden = false;
      $('[data-image-label]', box).textContent = 'Đổi ảnh';
      box.dispatchEvent(new Event('input', { bubbles: true }));
    });
  });
})();
