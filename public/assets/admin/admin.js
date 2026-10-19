/* LQCMS Admin — hành vi giao diện. Không cần build, chạy thẳng trên trình duyệt. */
(function () {
  'use strict';

  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));
  const csrf = () => $('meta[name="csrf-token"]')?.content || '';

  const refreshIcons = () => window.lucide && lucide.createIcons();
  refreshIcons();

  // Bỏ dấu để tìm kiếm không dấu: "bai viet" khớp "Bài viết".
  const fold = (t) => String(t).normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/đ/g, 'd').replace(/Đ/g, 'D').toLowerCase();
  const escapeHtml = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  /* ------------------------------------------------------------------
   * Thông báo dạng toast — Notyf
   * ------------------------------------------------------------------ */
  const ICONS = {
    success: '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
    error: '<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6M9 9l6 6"/>',
    warning: '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4M12 17h.01"/>',
    info: '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>',
  };
  const toastIcon = () => ({ className: 'lq-toast-icon', tagName: 'span', text: '' });
  const notyf = window.Notyf ? new Notyf({
    duration: 4200,
    dismissible: true,
    ripple: false,
    position: { x: 'right', y: 'bottom' },
    types: [
      { type: 'success', background: 'var(--primary)', icon: toastIcon() },
      { type: 'error', background: 'var(--primary)', icon: toastIcon(), duration: 7000 },
      { type: 'warning', background: 'var(--primary)', icon: toastIcon() },
      { type: 'info', background: 'var(--primary)', icon: toastIcon() },
    ],
  }) : null;

  function toast(type, message) {
    if (!notyf) { alert(message); return null; }
    return notyf.open({ type, message: escapeHtml(message) });
  }
  // Notyf chỉ nhận icon dạng class/text → chèn SVG vào ô icon khi toast xuất hiện.
  if (notyf) {
    new MutationObserver(() => {
      $$('.notyf__toast').forEach((t) => {
        const holder = $('.lq-toast-icon', t);
        if (!holder || holder.childElementCount) return;
        const type = ['success', 'error', 'warning', 'info'].find((k) => t.classList.contains('notyf__toast--' + k)) || 'info';
        holder.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${ICONS[type]}</svg>`;
      });
    }).observe(document.body, { childList: true, subtree: true });
  }

  (window.LQ_FLASH || []).forEach((f, i) => setTimeout(() => toast(f.type, f.message), 120 + i * 150));

  /* ------------------------------------------------------------------
   * Hộp xác nhận — SweetAlert2
   * ------------------------------------------------------------------ */
  const swalBase = () => window.Swal && Swal.mixin({
    customClass: {
      popup: 'lq-swal',
      confirmButton: 'btn btn-primary',
      cancelButton: 'btn btn-secondary',
      denyButton: 'btn btn-danger-soft',
    },
    buttonsStyling: false,
    reverseButtons: true,
    focusCancel: true,
    showClass: { popup: 'swal2-show' },
  });

  function confirmDialog({ title, text = '', danger = false, confirmText, cancelText = 'Huỷ' }) {
    const S = swalBase();
    if (!S) return Promise.resolve(window.confirm(title + (text ? '\n\n' + text : '')));
    return S.fire({
      title,
      text,
      showCancelButton: true,
      showClass: { popup: '', backdrop: 'swal2-backdrop-show' },
      confirmButtonText: confirmText || (danger ? 'Xoá' : 'Đồng ý'),
      cancelButtonText: cancelText,
      customClass: {
        popup: 'lq-swal',
        confirmButton: danger ? 'btn btn-danger' : 'btn btn-primary',
        cancelButton: 'btn btn-secondary',
      },
    }).then((r) => r.isConfirmed);
  }

  // Mọi form có data-confirm phải được xác nhận trước khi gửi.
  document.addEventListener('submit', (e) => {
    const form = e.target;
    if (!(form instanceof HTMLFormElement) || !form.dataset.confirm || form.dataset.confirmed === '1') return;
    e.preventDefault();
    confirmDialog({
      title: form.dataset.confirm,
      text: form.dataset.confirmText || '',
      danger: form.hasAttribute('data-confirm-danger'),
      confirmText: form.dataset.confirmLabel,
    }).then((ok) => {
      if (!ok) return;
      form.dataset.confirmed = '1';
      // Nút submit bằng form="" nằm ngoài form → gửi trực tiếp.
      HTMLFormElement.prototype.submit.call(form);
    });
  }, true);

  // Nút "đang xử lý" để tránh bấm 2 lần.
  document.addEventListener('submit', (e) => {
    const form = e.target;
    if (e.defaultPrevented || !(form instanceof HTMLFormElement)) return;
    const btn = e.submitter || $('button[type="submit"]', form);
    if (btn && btn.classList.contains('btn')) {
      // Disable sau khi trình duyệt đã gom dữ liệu form, để name/value của nút (vd. action=publish) vẫn được gửi.
      setTimeout(() => { btn.classList.add('is-loading'); btn.disabled = true; }, 0);
    }
  });

  /* ------------------------------------------------------------------
   * Khung: sidebar mobile, dropdown, sáng/tối
   * ------------------------------------------------------------------ */
  document.addEventListener('click', (e) => {
    if (e.target.closest('[data-sidebar-open]')) document.body.classList.add('sidebar-open');
    if (e.target.closest('[data-sidebar-close]')) document.body.classList.remove('sidebar-open');

    const toggle = e.target.closest('[data-dropdown-toggle]');
    $$('[data-dropdown].is-open').forEach((d) => { if (!toggle || !d.contains(toggle)) d.classList.remove('is-open'); });
    if (toggle) toggle.closest('[data-dropdown]').classList.toggle('is-open');

    if (e.target.closest('[data-theme-toggle]')) {
      const dark = document.documentElement.dataset.theme !== 'dark';
      document.documentElement.dataset.theme = dark ? 'dark' : 'light';
      try { localStorage.setItem('lq-theme', dark ? 'dark' : 'light'); } catch (_) {}
      document.dispatchEvent(new Event('lq:theme'));
    }

    const pw = e.target.closest('[data-toggle-password]');
    if (pw) {
      const input = pw.parentElement.querySelector('input');
      const show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      pw.innerHTML = `<i data-lucide="${show ? 'eye-off' : 'eye'}"></i>`;
      refreshIcons();
    }
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      $$('[data-dropdown].is-open').forEach((d) => d.classList.remove('is-open'));
      document.body.classList.remove('sidebar-open');
    }
  });

  // Mục menu có con: bấm vào cha khi đang ở trang con chỉ mở/đóng nhóm.
  $$('.nav-group').forEach((g) => {
    const link = $('.nav-link', g);
    if (!$('.nav-children', g)) return;
    link.addEventListener('click', (e) => {
      if (g.classList.contains('is-open') && !link.classList.contains('is-active')) return;
      if (!g.classList.contains('is-open')) { e.preventDefault(); g.classList.add('is-open'); }
    });
  });

  // Nút "Sao chép" cạnh ô chỉ đọc (vd. lệnh crontab)
  document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-copy]');
    if (!btn) return;
    const src = btn.parentElement.querySelector('[data-copy-source]');
    (navigator.clipboard ? navigator.clipboard.writeText(src.value) : Promise.reject())
      .catch(() => { src.select(); document.execCommand('copy'); })
      .finally(() => toast('success', 'Đã sao chép.'));
  });

  $$('[data-autosubmit]').forEach((el) => el.addEventListener('change', () => el.form && el.form.requestSubmit()));

  /* ------------------------------------------------------------------
   * Slug tiếng Việt: "Bài viết đầu tiên" → "bai-viet-dau-tien"
   * ------------------------------------------------------------------ */
  function slugify(text) {
    return String(text)
      .normalize('NFD').replace(/[̀-ͯ]/g, '')
      .replace(/đ/g, 'd').replace(/Đ/g, 'D')
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-+|-+$/g, '')
      .slice(0, 190);
  }
  window.LQ = Object.assign(window.LQ || {}, { toast, confirm: confirmDialog, slugify, csrf });

  $$('[data-slug-source]').forEach((src) => {
    const form = src.form;
    const target = form && $('[data-slug-target]', form);
    if (!target) return;
    // Chỉ tự sinh khi slug đang trống hoặc chưa bị sửa tay.
    let manual = target.value !== '' && target.value !== slugify(src.value);
    target.addEventListener('input', () => { manual = target.value !== ''; });
    target.addEventListener('blur', () => { if (target.value) target.value = slugify(target.value); });
    src.addEventListener('input', () => { if (!manual) target.value = slugify(src.value); });
  });

  /* ------------------------------------------------------------------
   * Chọn nhiều dòng & thao tác hàng loạt
   * ------------------------------------------------------------------ */
  $$('[data-bulk-form]').forEach((form) => {
    const all = $('[data-check-all]', form);
    const items = $$('[data-check-item]', form);
    const bar = $('[data-bulk-bar]', form);
    const count = $('[data-bulk-count]', form);
    const sync = () => {
      const n = items.filter((i) => i.checked).length;
      items.forEach((i) => i.closest('tr').classList.toggle('is-selected', i.checked));
      if (all) { all.checked = n === items.length && n > 0; all.indeterminate = n > 0 && n < items.length; }
      if (bar) bar.hidden = n === 0;
      if (count) count.textContent = n;
    };
    all && all.addEventListener('change', () => { items.forEach((i) => (i.checked = all.checked)); sync(); });
    items.forEach((i) => i.addEventListener('change', sync));
    form.addEventListener('submit', (e) => {
      if (form.dataset.confirmed === '1') return;
      const sel = $('select[name="bulk_action"]', form);
      const n = items.filter((i) => i.checked).length;
      e.preventDefault();
      const label = sel.options[sel.selectedIndex].text;
      confirmDialog({
        title: `${label} ${n} bài viết?`,
        text: sel.value === 'delete' ? 'Không thể hoàn tác thao tác này.' : '',
        danger: ['delete', 'trash'].includes(sel.value),
        confirmText: label,
      }).then((ok) => { if (ok) { form.dataset.confirmed = '1'; HTMLFormElement.prototype.submit.call(form); } });
    });
  });

  /* ------------------------------------------------------------------
   * Upload theme/plugin: chọn file là gửi luôn
   * ------------------------------------------------------------------ */
  $$('[data-upload-form]').forEach((form) => {
    const input = $('[data-upload-input]', form);
    input.addEventListener('change', () => {
      if (!input.files.length) return;
      const f = input.files[0];
      if (!/\.zip$/i.test(f.name)) { toast('error', 'Vui lòng chọn tệp .zip'); input.value = ''; return; }
      confirmDialog({ title: `Cài đặt "${f.name}"?`, text: `Dung lượng ${(f.size / 1024).toFixed(0)} KB`, confirmText: 'Cài đặt' })
        .then((ok) => {
          if (!ok) { input.value = ''; return; }
          if (window.Swal) Swal.fire({ title: 'Đang cài đặt…', allowOutsideClick: false, customClass: { popup: 'lq-swal lq-swal-loading' }, didOpen: () => Swal.showLoading() });
          form.submit();
        });
    });
  });

  /* ------------------------------------------------------------------
   * Độ mạnh mật khẩu
   * ------------------------------------------------------------------ */
  $$('[data-strength]').forEach((input) => {
    const bar = input.closest('.field').querySelector('[data-strength-bar] span');
    if (!bar) return;
    input.addEventListener('input', () => {
      const v = input.value;
      let s = 0;
      if (v.length >= 8) s++;
      if (v.length >= 12) s++;
      if (/[a-z]/.test(v) && /[A-Z]/.test(v)) s++;
      if (/\d/.test(v)) s++;
      if (/[^A-Za-z0-9]/.test(v)) s++;
      const colors = ['#dc2626', '#dc2626', '#ca8a04', '#ca8a04', '#16a34a', '#16a34a'];
      bar.style.width = v ? Math.max(12, (s / 5) * 100) + '%' : '0';
      bar.style.background = colors[s];
    });
  });

  /* ------------------------------------------------------------------
   * Cảnh báo khi rời trang mà chưa lưu
   * ------------------------------------------------------------------ */
  $$('[data-dirty-guard]').forEach((form) => {
    let dirty = false;
    form.addEventListener('input', () => { dirty = true; });
    form.addEventListener('change', () => { dirty = true; });
    form.addEventListener('submit', () => { dirty = false; });
    window.addEventListener('beforeunload', (e) => {
      if (window.tinymce && tinymce.activeEditor && tinymce.activeEditor.isDirty()) dirty = true;
      if (dirty) { e.preventDefault(); e.returnValue = ''; }
    });
    form.addEventListener('lq:clean', () => { dirty = false; });
  });

  /* ------------------------------------------------------------------
   * Form bài viết: TinyMCE, thẻ, ảnh đại diện
   * ------------------------------------------------------------------ */
  const editorEl = $('[data-editor]');
  // Kiểu chữ trong vùng soạn thảo (iframe): giống trang đọc bài, màu theo chế độ sáng/tối của admin.
  function editorCss(dark) {
    const c = dark
      ? { bg: '#131313', text: '#e5e5e5', strong: '#fafafa', muted: '#a1a1a1', border: '#2a2a2a', soft: '#1e1e1e', link: '#d9f99d', mark: 'rgba(198,244,50,.25)' }
      : { bg: '#ffffff', text: '#27272a', strong: '#0a0a0a', muted: '#71716d', border: '#e6e6e2', soft: '#f5f5f3', link: '#3f6212', mark: '#ecfccb' };
    return `
      html{background:${c.bg}}
      body{font-family:Geist,-apple-system,'Segoe UI',sans-serif;font-size:16.5px;line-height:1.75;color:${c.text};background:${c.bg};
           max-width:740px;margin:28px auto;padding:0 24px;-webkit-font-smoothing:antialiased}
      h1,h2,h3,h4{color:${c.strong};line-height:1.3;letter-spacing:-.02em;font-weight:600;margin:1.6em 0 .6em}
      h1{font-size:1.9em}h2{font-size:1.5em}h3{font-size:1.25em}
      p{margin:0 0 1.1em}
      a{color:${c.link};text-decoration:underline;text-underline-offset:3px}
      strong,b{color:${c.strong};font-weight:600}
      blockquote{margin:1.4em 0;padding:.2em 0 .2em 1.1em;border-left:3px solid #c6f432;color:${c.muted};font-style:normal}
      code{font-family:'Geist Mono',ui-monospace,Menlo,monospace;font-size:.88em;background:${c.soft};padding:.15em .4em;border-radius:5px}
      pre{font-family:'Geist Mono',ui-monospace,Menlo,monospace;font-size:14px;background:${c.soft};padding:14px 16px;border-radius:10px;overflow:auto}
      hr{border:0;border-top:1px solid ${c.border};margin:2em 0}
      img{max-width:100%;height:auto;border-radius:10px}
      figure{margin:1.4em 0}figcaption{color:${c.muted};font-size:.88em;text-align:center;margin-top:.5em}
      table{border-collapse:collapse;width:100%}td,th{border:1px solid ${c.border};padding:8px 10px}th{background:${c.soft};color:${c.strong}}
      ::selection{background:${c.mark}}
      .mce-content-body [data-mce-selected]{outline:2px solid #c6f432 !important;outline-offset:2px}
      .mce-content-body[data-mce-placeholder]:not(.mce-visualblocks)::before{color:${c.muted}}
    `;
  }
  function initEditor() {
    const dark = document.documentElement.dataset.theme === 'dark';
    return tinymce.init({
      target: editorEl,
      license_key: 'gpl',
      height: 560,
      menubar: false,
      language: 'vi',
      language_url: 'https://cdn.jsdelivr.net/npm/tinymce-i18n@24/langs7/vi.js',
      placeholder: 'Bắt đầu viết nội dung…',
      branding: false,
      promotion: false,
      skin: dark ? 'oxide-dark' : 'oxide',
      // Không dùng content_css dựng sẵn (dark của TinyMCE có nền xanh navy); tự định kiểu ở content_style.
      content_css: ['https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600;700&family=Geist+Mono&display=swap'],
      plugins: 'autolink link image lists table code fullscreen media wordcount searchreplace autoresize',
      toolbar: 'blocks | bold italic underline strikethrough | forecolor | alignleft aligncenter alignright | bullist numlist blockquote | link image media table | removeformat code fullscreen',
      toolbar_sticky: true,
      toolbar_sticky_offset: 64,
      min_height: 480,
      max_height: 1400,
      autoresize_bottom_margin: 40,
      relative_urls: false,
      convert_urls: false,
      image_caption: true,
      content_style: editorCss(dark),
      // Nút "Duyệt" trong hộp thoại chèn ảnh mở thư viện media.
      file_picker_types: 'image',
      file_picker_callback: (cb) => {
        if (!window.LQ || !LQ.pickMedia) return;
        LQ.pickMedia({ title: 'Chèn ảnh vào bài' }).then((m) => m && cb(m.url, { alt: m.alt || '' }));
      },
      images_upload_handler: (blobInfo, progress) => new Promise((resolve, reject) => {
        const fd = new FormData();
        fd.append('file', blobInfo.blob(), blobInfo.filename());
        const xhr = new XMLHttpRequest();
        xhr.open('POST', '/admin/upload');
        xhr.setRequestHeader('X-CSRF-Token', csrf());
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.upload.onprogress = (e) => progress((e.loaded / e.total) * 100);
        xhr.onload = () => {
          let json = {};
          try { json = JSON.parse(xhr.responseText); } catch (_) {}
          if (xhr.status === 200 && json.location) { resolve(json.location); return; }
          const msg = (json.error && json.error.message) || json.message || 'Tải ảnh thất bại.';
          toast('error', msg);
          reject({ message: msg, remove: true });
        };
        xhr.onerror = () => reject({ message: 'Lỗi mạng khi tải ảnh.', remove: true });
        xhr.send(fd);
      }),
    });
  }
  if (editorEl && window.tinymce) {
    initEditor();
    // Đổi sáng/tối: dựng lại editor với skin tương ứng, giữ nguyên nội dung và trạng thái "đã sửa".
    let editorDark = document.documentElement.dataset.theme === 'dark';
    new MutationObserver(() => {
      const nowDark = document.documentElement.dataset.theme === 'dark';
      if (nowDark === editorDark) return;
      editorDark = nowDark;
      const ed = tinymce.get(editorEl.id);
      if (!ed) return;
      const dirty = ed.isDirty();
      ed.save();
      ed.remove();
      initEditor().then(([e]) => { if (dirty && e) e.setDirty(true); });
    }).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
  }

  // Ô nhập dạng chip: Thẻ và mọi taxonomy không phân cấp (data-tax="actor"…). Mỗi ô chạy độc lập.
  $$('[data-tag-input]').forEach((tagBox) => {
    const tax = tagBox.dataset.tax || 'tag';
    const chips = $('[data-tag-chips]', tagBox);
    const entry = $('[data-tag-entry]', tagBox);
    const hidden = $('[data-tag-value]', tagBox);
    const suggest = $('[data-tag-suggest]', tagBox);
    let tags = hidden.value.split(',').map((t) => t.trim()).filter(Boolean);
    let active = -1;

    const changed = () => hidden.dispatchEvent(new Event('change', { bubbles: true }));
    const render = () => {
      chips.innerHTML = tags.map((t, i) =>
        `<span class="tag-chip">${escapeHtml(t)}<button type="button" data-remove="${i}" aria-label="Gỡ"><i data-lucide="x"></i></button></span>`).join('');
      hidden.value = tags.join(', ');
      refreshIcons();
    };
    const add = (raw) => {
      raw.split(',').map((t) => t.trim()).filter(Boolean).forEach((t) => {
        if (t.length <= 191 && !tags.some((x) => x.toLowerCase() === t.toLowerCase())) tags.push(t);
      });
      entry.value = '';
      suggest.hidden = true;
      render();
      changed();
    };

    tagBox.addEventListener('click', (e) => {
      const rm = e.target.closest('[data-remove]');
      if (rm) { tags.splice(+rm.dataset.remove, 1); render(); changed(); return; }
      const pick = e.target.closest('[data-pick]');
      if (pick) { add(pick.dataset.pick); entry.focus(); return; }
      entry.focus();
    });
    // Nút "Hay dùng" nằm cùng khung
    $$('[data-tag-add]', tagBox.closest('.card') || document).forEach((b) => b.addEventListener('click', () => add(b.dataset.tagAdd)));

    let timer;
    entry.addEventListener('input', () => {
      if (entry.value.includes(',')) { add(entry.value); return; }
      clearTimeout(timer);
      const q = entry.value.trim();
      if (!q) { suggest.hidden = true; return; }
      timer = setTimeout(() => {
        fetch('/admin/api/terms?tax=' + encodeURIComponent(tax) + '&q=' + encodeURIComponent(q), { headers: { Accept: 'application/json' } })
          .then((r) => r.json())
          .then((d) => {
            const list = (d.tags || []).filter((t) => !tags.includes(t));
            active = -1;
            suggest.innerHTML = list.map((t) => `<button type="button" data-pick="${escapeHtml(t)}">${escapeHtml(t)}</button>`).join('');
            suggest.hidden = !list.length;
          }).catch(() => {});
      }, 180);
    });
    entry.addEventListener('keydown', (e) => {
      const opts = $$('button', suggest);
      if (e.key === 'ArrowDown' && !suggest.hidden) { e.preventDefault(); active = (active + 1) % opts.length; }
      else if (e.key === 'ArrowUp' && !suggest.hidden) { e.preventDefault(); active = (active - 1 + opts.length) % opts.length; }
      else if (e.key === 'Enter') {
        e.preventDefault();
        if (active >= 0 && opts[active] && !suggest.hidden) add(opts[active].dataset.pick);
        else if (entry.value.trim()) add(entry.value);
        return;
      } else if (e.key === 'Backspace' && !entry.value && tags.length) { tags.pop(); render(); changed(); return; }
      else if (e.key === 'Escape') { suggest.hidden = true; return; }
      else return;
      opts.forEach((o, i) => o.classList.toggle('is-active', i === active));
    });
    entry.addEventListener('blur', () => setTimeout(() => { suggest.hidden = true; }, 150));
    render();
  });

  /* ------------------------------------------------------------------
   * Field tuỳ biến: ảnh (tự upload), màu, nhiều dòng (repeat)
   * ------------------------------------------------------------------ */
  document.addEventListener('change', (e) => {
    const color = e.target.closest('.color-field input[type="color"]');
    if (color) $('[data-color-code]', color.parentElement).textContent = color.value;
  });
  document.addEventListener('input', (e) => {
    const color = e.target.closest('.color-field input[type="color"]');
    if (color) $('[data-color-code]', color.parentElement).textContent = color.value;
  });
  document.addEventListener('click', (e) => {
    const clear = e.target.closest('[data-image-clear]');
    if (clear) {
      const box = clear.closest('[data-image-field]');
      $('[data-image-value]', box).value = '';
      const img = $('[data-image-preview]', box); img.hidden = true; img.removeAttribute('src');
      clear.hidden = true;
      $('[data-image-label]', box).textContent = 'Chọn ảnh';
      box.dispatchEvent(new Event('input', { bubbles: true }));
    }

    const addBtn = e.target.closest('[data-repeat-add]');
    if (addBtn) {
      const rep = addBtn.closest('[data-repeat]');
      const rows = $('[data-repeat-rows]', rep);
      if (rows.children.length >= +rep.dataset.max) { toast('warning', `Tối đa ${rep.dataset.max} dòng.`); return; }
      rows.appendChild($('[data-repeat-template]', rep).content.firstElementChild.cloneNode(true));
      refreshIcons();
      const input = rows.lastElementChild.querySelector('input:not([type="hidden"]):not([type="file"])');
      if (input) input.focus();
    }
    const rmBtn = e.target.closest('[data-repeat-remove]');
    if (rmBtn) {
      const rep = rmBtn.closest('[data-repeat]');
      const rows = $('[data-repeat-rows]', rep);
      const row = rmBtn.closest('[data-repeat-row]');
      // Giữ lại ít nhất một dòng trống để còn chỗ nhập.
      if (rows.children.length > 1) row.remove();
      else row.querySelectorAll('input').forEach((i) => { if (i.type !== 'file') i.value = ''; });
      rep.dispatchEvent(new Event('input', { bubbles: true }));
    }
  });
  if (window.Sortable) {
    $$('[data-repeat-rows]').forEach((rows) => Sortable.create(rows, { handle: '.repeat-handle', animation: 150, onSort: () => rows.dispatchEvent(new Event('input', { bubbles: true })) }));
  }

  // Form trang: khung field theo mẫu trang + đường dẫn theo trang cha.
  const tplSelect = $('[data-page-template]');
  if (tplSelect) {
    const syncTpl = () => {
      const v = tplSelect.value;
      $$('[data-for-template]').forEach((box) => { box.hidden = !box.dataset.forTemplate.split(' ').includes(v); });
      $$('[data-template-help]').forEach((h) => { h.hidden = h.dataset.templateHelp !== v; });
    };
    tplSelect.addEventListener('change', syncTpl);
    syncTpl();
  }
  const parentSelect = $('[data-page-parent]');
  const prefixEl = $('[data-page-prefix]');
  if (parentSelect && prefixEl) {
    const root = (prefixEl.textContent.match(/^https?:\/\/[^/]+/) || [''])[0];
    parentSelect.addEventListener('change', () => {
      const path = parentSelect.selectedOptions[0]?.dataset.path || '';
      prefixEl.textContent = root + '/' + (path ? path + '/' : '');
    });
  }

  // Bài đăng có ngày tương lai → đổi nhãn nút "Đăng" thành "Lên lịch".
  const publishBtn = $('[data-publish-btn]');
  const dateInput = $('#published_at');
  if (publishBtn && dateInput) {
    const label = publishBtn.innerHTML;
    const sync = () => {
      const future = dateInput.value && new Date(dateInput.value) > new Date();
      publishBtn.innerHTML = future ? 'Lên lịch' : label;
    };
    dateInput.addEventListener('change', sync);
    sync();
  }

  /* ------------------------------------------------------------------
   * Cài đặt đường dẫn tĩnh: chọn mẫu, chèn thẻ, xem trước
   * ------------------------------------------------------------------ */
  const pform = $('[data-permalink-form]');
  if (pform) {
    const site = pform.dataset.site || '';
    const sample = JSON.parse(pform.dataset.sample || '{}');
    const custom = $('[data-custom-structure]', pform);
    const preview = $('[data-permalink-preview]', pform);
    const radios = $$('input[name="preset"]', pform);
    const current = () => radios.find((r) => r.checked);
    const structure = () => {
      const r = current();
      return !r ? '' : r.value === 'custom' ? custom.value.trim() : r.dataset.structure;
    };
    const update = () => {
      radios.forEach((r) => r.closest('.preset').classList.toggle('is-checked', r.checked));
      const s = structure();
      if (s === '') { preview.textContent = site + '/?p=' + (sample['%post_id%'] || 123); return; }
      let url = s.replace(/%[a-z_]+%/g, (t) => (t in sample ? sample[t] : t));
      preview.textContent = site + (url.startsWith('/') ? url : '/' + url);
      const ok = /%(postname|post_id)%/.test(s);
      preview.style.color = ok ? '' : 'var(--danger)';
      if (!ok) preview.textContent += '  ← thiếu %postname% hoặc %post_id%';
    };
    radios.forEach((r) => r.addEventListener('change', () => {
      // Chọn mẫu có sẵn → đổ sẵn vào ô tuỳ chỉnh để sửa tiếp nếu muốn.
      if (r.value !== 'custom' && r.dataset.structure) custom.value = r.dataset.structure;
      update();
    }));
    custom.addEventListener('focus', () => { const c = radios.find((r) => r.value === 'custom'); c.checked = true; update(); });
    custom.addEventListener('input', update);
    $$('[data-insert-tag]', pform).forEach((b) => b.addEventListener('click', () => {
      const tag = b.dataset.insertTag;
      const c = radios.find((r) => r.value === 'custom');
      c.checked = true;
      let v = custom.value;
      if (v.includes(tag)) { toast('warning', `Thẻ ${tag} đã có trong cấu trúc.`); return; }
      const pos = custom.selectionStart ?? v.length;
      const before = v.slice(0, pos), after = v.slice(pos);
      const sep = before && !before.endsWith('/') ? '/' : '';
      custom.value = before + sep + tag + (after.startsWith('/') || !after ? '/' : '/') + after.replace(/^\//, '');
      custom.value = custom.value.replace(/\/{2,}/g, '/');
      custom.focus();
      update();
      pform.dispatchEvent(new Event('input'));
    }));
    update();

    // Xem trước URL chuyên mục/thẻ theo tiền tố; ô "Không dùng tiền tố" khoá ô nhập và đưa term về gốc site.
    $$('[data-base-field]', pform).forEach((field) => {
      const inp = $('[data-base-input]', field);
      const noBase = $('[data-no-base]', field);
      const out = $('[data-base-preview]', field);
      const sync = () => {
        const base = noBase && noBase.checked ? '' : (inp.value.trim().replace(/^\/+|\/+$/g, '') || inp.placeholder);
        out.textContent = site + '/' + (base ? base + '/' : '') + out.dataset.sample + '/';
      };
      inp.addEventListener('input', sync);
      if (noBase) {
        noBase.addEventListener('change', () => {
          inp.disabled = noBase.checked;
          if (!noBase.checked) {
            if (!inp.value.trim()) inp.value = inp.placeholder;
            inp.focus();
          }
          sync();
        });
      }
      sync();
    });
  }

  /* ------------------------------------------------------------------
   * Tooltip cho biểu đồ (mọi phần tử có data-tip)
   * ------------------------------------------------------------------ */
  const tip = $('[data-tooltip]');
  if (tip) {
    const show = (el) => {
      tip.textContent = el.dataset.tip;
      const r = el.getBoundingClientRect();
      const bar = el.querySelector('.col-bar');
      const top = bar ? bar.getBoundingClientRect().top : r.top;
      tip.style.left = r.left + r.width / 2 + 'px';
      tip.style.top = Math.max(top, 40) + 'px';
      tip.hidden = false;
    };
    const hide = () => { tip.hidden = true; };
    document.addEventListener('pointerover', (e) => { const el = e.target.closest('[data-tip]'); el ? show(el) : hide(); });
    document.addEventListener('focusin', (e) => { const el = e.target.closest('[data-tip]'); if (el) show(el); });
    document.addEventListener('focusout', hide);
    window.addEventListener('scroll', hide, { passive: true });
  }

  /* ------------------------------------------------------------------
   * Số liệu đếm lên khi tải trang
   * ------------------------------------------------------------------ */
  const reduceMotion = matchMedia('(prefers-reduced-motion: reduce)').matches;
  function countUp(root) {
    if (reduceMotion) return;
    $$('[data-count]', root).forEach((el) => {
      const target = +el.dataset.count;
      if (!target) return;
      const fmt = (n) => n.toLocaleString('vi-VN');
      const start = performance.now();
      const dur = 700;
      const tick = (t) => {
        const k = Math.min(1, (t - start) / dur);
        el.textContent = fmt(Math.round(target * (1 - Math.pow(1 - k, 3))));
        if (k < 1) requestAnimationFrame(tick);
      };
      el.textContent = '0';
      requestAnimationFrame(tick);
    });
  }
  countUp(document);

  /* ------------------------------------------------------------------
   * Thanh lệnh ⌘K / Ctrl+K
   * ------------------------------------------------------------------ */
  const cmdk = $('[data-cmdk]');
  if (cmdk) {
    const isMac = /Mac|iPhone|iPad/.test(navigator.platform);
    $$('[data-kbd-mod]').forEach((k) => { k.textContent = isMac ? '⌘K' : 'Ctrl K'; });

    const input = $('[data-cmdk-input]', cmdk);
    const list = $('[data-cmdk-list]', cmdk);
    const nav = window.LQ_NAV || [];
    const can = window.LQ_CAN || {};
    const actions = [
      can.edit_posts && { label: 'Viết bài mới', url: '/admin/posts/new', icon: 'pen-line', hint: 'Tạo' },
      can.manage_terms && { label: 'Thêm chuyên mục', url: '/admin/categories', icon: 'folder-plus', hint: 'Tạo' },
      { label: 'Đổi giao diện sáng / tối', run: () => $('[data-theme-toggle]').click(), icon: 'sun-moon', hint: 'Lệnh' },
      { label: 'Xem trang web', url: '/', icon: 'globe', hint: 'Mở', blank: true },
      { label: 'Đăng xuất', run: () => $('form[action="/admin/logout"]').submit(), icon: 'log-out', hint: 'Lệnh' },
    ].filter(Boolean);

    let items = [];
    let active = 0;
    let timer;
    let seq = 0;

    const highlight = (text, q) => {
      if (!q) return escapeHtml(text);
      const i = fold(text).indexOf(fold(q));
      if (i < 0) return escapeHtml(text);
      return escapeHtml(text.slice(0, i)) + '<mark>' + escapeHtml(text.slice(i, i + q.length)) + '</mark>' + escapeHtml(text.slice(i + q.length));
    };

    const render = (groups, q, loading) => {
      items = [];
      let html = '';
      groups.forEach(([title, rows]) => {
        if (!rows.length) return;
        html += `<div class="cmdk-group">${escapeHtml(title)}</div>`;
        rows.forEach((r) => {
          const idx = items.push(r) - 1;
          html += `<button type="button" class="cmdk-item" data-idx="${idx}" role="option">`
            + `<i data-lucide="${escapeHtml(r.icon || 'circle')}"></i>`
            + `<span class="cmdk-text">${highlight(r.label, q)}</span>`
            + (r.hint ? `<span class="cmdk-hint">${escapeHtml(r.hint)}</span>` : '')
            + '</button>';
        });
      });
      if (loading) html += '<div class="cmdk-group">Bài viết</div><div class="cmdk-skel"></div><div class="cmdk-skel"></div>';
      if (!items.length && !loading) html = `<div class="cmdk-empty">Không tìm thấy kết quả cho “${escapeHtml(q)}”</div>`;
      list.innerHTML = html;
      active = Math.min(active, Math.max(0, items.length - 1));
      paint();
      refreshIcons();
    };
    const paint = () => {
      $$('.cmdk-item', list).forEach((b, i) => b.classList.toggle('is-active', i === active));
      const cur = $('.cmdk-item.is-active', list);
      if (cur) cur.scrollIntoView({ block: 'nearest' });
    };

    const local = (q) => {
      const f = fold(q);
      const pages = nav.filter((n) => !q || fold(n.label).includes(f) || fold(n.parent || '').includes(f))
        .map((n) => ({ label: n.label, url: n.url, icon: n.icon, hint: n.parent || 'Trang' }));
      const acts = actions.filter((a) => !q || fold(a.label).includes(f));
      return [['Thao tác', acts], ['Đi tới', pages]];
    };

    const search = () => {
      const q = input.value.trim();
      const base = local(q);
      active = 0;
      clearTimeout(timer);
      if (!q || !can.edit_posts) { render(base, q, false); return; }
      render(base, q, true);
      const my = ++seq;
      timer = setTimeout(() => {
        fetch('/admin/api/search?q=' + encodeURIComponent(q), { headers: { Accept: 'application/json' } })
          .then((r) => r.json())
          .then((d) => {
            if (my !== seq) return;
            const posts = (d.posts || []).map((p) => ({ label: p.title, url: p.url, icon: p.type === 'page' ? 'file' : 'file-text', hint: p.status }));
            const terms = (d.terms || []).map((t) => ({ label: t.name, url: t.url, icon: t.type === 'Thẻ' ? 'hash' : 'folder', hint: t.type }));
            render([['Bài viết & trang', posts], ['Chuyên mục & thẻ', terms], ...base], q, false);
          })
          .catch(() => { if (my === seq) render(base, q, false); });
      }, 160);
    };

    const open = () => {
      cmdk.hidden = false;
      input.value = '';
      search();
      requestAnimationFrame(() => input.focus());
    };
    const close = () => { cmdk.hidden = true; };
    const go = (item) => {
      if (!item) return;
      close();
      if (item.run) { item.run(); return; }
      if (item.blank) window.open(item.url, '_blank', 'noopener');
      else location.href = item.url;
    };

    input.addEventListener('input', search);
    input.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowDown') { e.preventDefault(); active = (active + 1) % Math.max(1, items.length); paint(); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); active = (active - 1 + items.length) % Math.max(1, items.length); paint(); }
      else if (e.key === 'Enter') { e.preventDefault(); go(items[active]); }
    });
    list.addEventListener('click', (e) => { const b = e.target.closest('[data-idx]'); if (b) go(items[+b.dataset.idx]); });
    list.addEventListener('pointermove', (e) => {
      const b = e.target.closest('[data-idx]');
      if (b && +b.dataset.idx !== active) { active = +b.dataset.idx; $$('.cmdk-item', list).forEach((x, i) => x.classList.toggle('is-active', i === active)); }
    });
    document.addEventListener('click', (e) => {
      if (e.target.closest('[data-cmdk-open]')) { open(); document.body.classList.remove('sidebar-open'); }
      if (e.target.closest('[data-cmdk-close]')) close();
    });
    document.addEventListener('keydown', (e) => {
      if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); cmdk.hidden ? open() : close(); }
      else if (e.key === 'Escape' && !cmdk.hidden) { e.preventDefault(); close(); }
    });
  }

  /* ------------------------------------------------------------------
   * Bảng tin dạng widget: tuỳ chỉnh, kéo thả (SortableJS), đổi kích thước, thêm/gỡ
   * ------------------------------------------------------------------ */
  const grid = $('[data-widget-grid]');
  if (grid && window.Sortable) {
    const SIZES = window.LQ_WIDGET_SIZES || {};
    const editbar = $('[data-dash-editbar]');
    const actions = $('[data-dash-actions]');
    const empty = $('[data-dash-empty]');
    const drawerSearch = $('[data-drawer-search]');

    const layout = () => $$('[data-widget]', grid).map((w) => ({ id: w.dataset.widget, size: +w.dataset.size }));
    const syncCatalog = () => {
      const used = new Set(layout().map((l) => l.id));
      $$('[data-catalog-id]').forEach((c) => c.classList.toggle('is-added', used.has(c.dataset.catalogId)));
      empty.hidden = used.size > 0 || !!$('.widget-loading', grid);
    };

    let saveTimer;
    const save = () => {
      clearTimeout(saveTimer);
      saveTimer = setTimeout(() => {
        fetch('/admin/dashboard/layout', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': csrf() },
          body: JSON.stringify({ layout: layout() }),
        }).then((r) => r.json()).then((d) => {
          if (!d.ok) toast('error', d.message || 'Không lưu được bố cục.');
        }).catch(() => toast('error', 'Mất kết nối, chưa lưu được bố cục.'));
      }, 350);
    };

    const sortable = Sortable.create(grid, {
      group: { name: 'widgets', pull: false, put: true },
      draggable: '.widget, .catalog-item',
      filter: '[data-widget-size], [data-widget-remove]',
      preventOnFilter: false,
      animation: 220,
      easing: 'cubic-bezier(.2, .8, .2, 1)',
      ghostClass: 'widget-ghost',
      chosenClass: 'widget-chosen',
      dragClass: 'widget-drag',
      disabled: true,
      onSort: (e) => { if (!e.item.classList.contains('catalog-item')) save(); },
      onAdd: (e) => insertWidget(e.item),
    });
    $$('[data-widget-catalog]').forEach((list) => Sortable.create(list, {
      group: { name: 'widgets', pull: 'clone', put: false },
      sort: false,
      filter: '.is-added, [data-catalog-add]',
      preventOnFilter: false,
      animation: 150,
      ghostClass: 'widget-ghost',
    }));

    // Biến phần tử tạm (thẻ catalog vừa thả vào, hoặc ô mới) thành widget thật lấy từ server.
    function insertWidget(placeholder) {
      const id = placeholder.dataset.catalogId;
      const size = placeholder.dataset.size || 4;
      placeholder.className = 'widget widget-loading span-' + size;
      placeholder.removeAttribute('data-catalog-id');
      syncCatalog();
      fetch('/admin/dashboard/widget?id=' + encodeURIComponent(id) + '&size=' + size, { headers: { Accept: 'application/json' } })
        .then((r) => r.json())
        .then((d) => {
          if (!d.ok) throw new Error(d.message);
          const tpl = document.createElement('template');
          tpl.innerHTML = d.html.trim();
          const node = tpl.content.firstElementChild;
          node.classList.add('is-new');
          placeholder.replaceWith(node);
          refreshIcons();
          countUp(node);
          syncCatalog();
          save();
        })
        .catch((err) => {
          placeholder.remove();
          syncCatalog();
          toast('error', (err && err.message) || 'Không thêm được widget.');
        });
    }

    const openDrawer = () => { document.body.classList.add('drawer-open'); setTimeout(() => drawerSearch && drawerSearch.focus(), 250); };
    const closeDrawer = () => document.body.classList.remove('drawer-open');
    const setEditing = (on) => {
      document.body.classList.toggle('dash-editing', on);
      editbar.hidden = !on;
      actions.hidden = on;
      sortable.option('disabled', !on);
      if (!on) closeDrawer();
      syncCatalog();
    };

    document.addEventListener('click', (e) => {
      if (e.target.closest('[data-dash-edit]')) setEditing(true);
      if (e.target.closest('[data-dash-done]')) { setEditing(false); toast('success', 'Đã lưu bố cục Bảng tin.'); }
      if (e.target.closest('[data-drawer-open]')) { setEditing(true); openDrawer(); }
      if (e.target.closest('[data-drawer-close]')) closeDrawer();

      const add = e.target.closest('[data-catalog-add]');
      if (add) {
        const item = add.closest('[data-catalog-id]');
        const ph = document.createElement('div');
        ph.dataset.catalogId = item.dataset.catalogId;
        ph.dataset.size = item.dataset.size;
        grid.appendChild(ph);
        insertWidget(ph);
        ph.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }

      const sizeBtn = e.target.closest('[data-widget-size]');
      if (sizeBtn) {
        const w = sizeBtn.closest('[data-widget]');
        const list = w.dataset.sizes.split(',').map(Number);
        const next = list[(list.indexOf(+w.dataset.size) + 1) % list.length];
        w.classList.remove('span-' + w.dataset.size);
        w.classList.add('span-' + next);
        w.dataset.size = next;
        $('span', sizeBtn).textContent = SIZES[next] || '';
        save();
      }

      const rm = e.target.closest('[data-widget-remove]');
      if (rm) {
        const w = rm.closest('[data-widget]');
        w.classList.add('is-leaving');
        setTimeout(() => { w.remove(); syncCatalog(); save(); }, 200);
      }
    });
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && document.body.classList.contains('dash-editing') && $('[data-cmdk]').hidden) {
        document.body.classList.contains('drawer-open') ? closeDrawer() : setEditing(false);
      }
    });

    if (drawerSearch) {
      drawerSearch.addEventListener('input', () => {
        const q = fold(drawerSearch.value.trim());
        $$('[data-catalog-id]').forEach((c) => { c.hidden = q !== '' && !fold(c.dataset.search).includes(q); });
        $$('[data-drawer-group]').forEach((g) => { g.hidden = !$$('[data-catalog-id]:not([hidden])', g).length; });
      });
    }
    syncCatalog();
  }

  /* ------------------------------------------------------------------
   * Chống mất bài: tự lưu, khôi phục bản tự lưu, khoá khi nhiều người cùng sửa
   * ------------------------------------------------------------------ */
  const guardForm = $('[data-post-form]');
  if (guardForm && guardForm.dataset.base) {
    const base = guardForm.dataset.base;
    let postId = +guardForm.dataset.postId || 0;
    const statusEl = $('[data-autosave-status]', guardForm);
    const titleEl = $('[name="title"]', guardForm);
    const editor = () => (window.tinymce ? tinymce.get('content') : null);
    const getContent = () => (editor() ? editor().getContent() : ($('#content')?.value || ''));
    const snap = () => JSON.stringify([titleEl.value, getContent()]);
    const post = (url, data) => {
      const fd = new FormData();
      Object.entries(data).forEach(([k, v]) => fd.append(k, v));
      return fetch(url, { method: 'POST', body: fd, headers: { Accept: 'application/json', 'X-CSRF-Token': csrf() } }).then((r) => r.json());
    };
    const setStatus = (cls, text) => { if (statusEl) { statusEl.className = cls; statusEl.textContent = text; } };

    // Mốc so sánh: lấy sau khi TinyMCE dựng xong (nội dung đã được chuẩn hoá).
    let lastSnap = null;
    const waitEditor = setInterval(() => { if (editor() && editor().initialized) { lastSnap = snap(); clearInterval(waitEditor); } }, 300);
    setTimeout(() => clearInterval(waitEditor), 20000);

    let submitting = false;
    let inFlight = false;
    guardForm.addEventListener('submit', () => { submitting = true; });

    const autosave = () => {
      if (submitting || inFlight || lastSnap === null) return;
      const cur = snap();
      if (cur === lastSnap) return;
      inFlight = true;
      setStatus('is-saving', 'Đang lưu…');
      post(base + '/autosave', { id: postId || '', title: titleEl.value, content: getContent() })
        .then((d) => {
          if (!d.ok) throw new Error(d.message || 'Lỗi');
          if (d.skipped) { setStatus('muted', '—'); return; }
          lastSnap = cur;
          if (d.created) {
            // Bài mới vừa được tạo thành bản nháp: chuyển form sang chế độ sửa bài này.
            postId = d.id;
            guardForm.dataset.postId = d.id;
            const idInput = document.createElement('input');
            idInput.type = 'hidden'; idInput.name = 'id'; idInput.value = d.id;
            guardForm.prepend(idInput);
            const loaded = $('[data-loaded-at]', guardForm);
            if (loaded) loaded.value = d.loaded_at;
            const slug = $('[data-slug-target]', guardForm);
            if (slug && !slug.value) slug.value = d.slug;
            history.replaceState(null, '', d.edit_url);
            startHeartbeat();
          }
          setStatus('is-saved', 'Đã lưu lúc ' + d.saved_at);
        })
        .catch(() => setStatus('is-error', 'Chưa tự lưu được'))
        .finally(() => { inFlight = false; });
    };
    setInterval(autosave, 30000);
    document.addEventListener('visibilitychange', () => { if (document.hidden) autosave(); });

    // Banner khôi phục bản tự lưu
    const banner = $('[data-autosave-banner]');
    if (banner) {
      banner.addEventListener('click', (e) => {
        if (e.target.closest('[data-autosave-dismiss]')) banner.remove();
        if (e.target.closest('[data-autosave-restore]')) {
          const data = JSON.parse($('[data-autosave-data]', banner).textContent);
          titleEl.value = data.title;
          if (editor()) editor().setContent(data.content); else $('#content').value = data.content;
          banner.remove();
          guardForm.dispatchEvent(new Event('input'));
          toast('success', 'Đã khôi phục bản tự lưu. Nhớ bấm Lưu để giữ lại.');
        }
      });
    }

    // Khoá chỉnh sửa: nhịp 30 giây; báo khi người khác đang sửa hoặc giành quyền.
    const takeover = $('[data-takeover-banner]');
    let hbTimer = null;
    const showTakeover = (name) => {
      $('[data-takeover-text]', takeover).innerHTML = `<strong>${escapeHtml(name)}</strong> đang sửa ${base.endsWith('pages') ? 'trang' : 'bài'} này. Nếu cả hai cùng lưu, bản của người lưu sau sẽ đè bản trước. `
        + '<button type="button" class="link btn-plain" data-take-lock>Giành quyền sửa</button>';
      takeover.hidden = false;
    };
    const heartbeat = (take) => post(`${base}/${postId}/lock`, take ? { take: 1 } : {}).then((d) => {
      if (d.locked_by) showTakeover(d.locked_by.name); else takeover.hidden = true;
    }).catch(() => {});
    function startHeartbeat() { if (!hbTimer && postId) hbTimer = setInterval(() => heartbeat(false), 30000); }
    takeover.addEventListener('click', (e) => { if (e.target.closest('[data-take-lock]')) heartbeat(true); });

    const lockedBy = $('[data-locked-by]');
    if (lockedBy && postId) {
      confirmDialog({
        title: `${lockedBy.dataset.lockedBy} đang sửa ${base.endsWith('pages') ? 'trang' : 'bài'} này`,
        text: 'Nếu hai người cùng sửa, người lưu sau sẽ ghi đè thay đổi của người trước. Bạn vẫn muốn sửa?',
        confirmText: 'Vẫn sửa',
        cancelText: 'Quay lại',
      }).then((ok) => {
        if (ok) { heartbeat(true); startHeartbeat(); } else { location.href = base; }
      });
    } else {
      startHeartbeat();
    }
    // Rời trang: nhả khoá để người khác không bị cảnh báo nhầm.
    window.addEventListener('pagehide', () => {
      if (!postId || !navigator.sendBeacon) return;
      const fd = new FormData();
      fd.append('_token', csrf());
      navigator.sendBeacon(`${base}/${postId}/unlock`, fd);
    });
  }
})();
