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
      // titleText (không phải title): title của SweetAlert2 là HTML, mà tiêu đề hay chứa tên bài/term/plugin/người dùng
      // (data-confirm, data-locked-by) → tên "<img onerror=…>" sẽ chạy script trong admin.
      titleText: title,
      text,
      showCancelButton: true,
      showClass: { popup: 'swal2-show', backdrop: 'swal2-backdrop-show' },
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
    const sub = e.submitter;
    confirmDialog({
      title: form.dataset.confirm,
      text: form.dataset.confirmText || '',
      danger: form.hasAttribute('data-confirm-danger'),
      confirmText: form.dataset.confirmLabel,
    }).then((ok) => {
      if (!ok) return;
      form.dataset.confirmed = '1';
      // form.submit() không gửi name/value của nút đã bấm (plugin hay dùng <button name="action" value="…">) và bỏ qua
      // formaction của nút → chép lại thành ô ẩn / action của form trước khi gửi.
      if (sub && sub.name) {
        const h = document.createElement('input');
        h.type = 'hidden'; h.name = sub.name; h.value = sub.value;
        form.append(h);
      }
      if (sub && sub.hasAttribute('formaction')) form.action = sub.formAction;
      // data-confirm-busy="Đang …": việc chạy lâu (vd. cập nhật lõi) — khoá nút và đổi chữ để người dùng biết đang chạy.
      if (form.dataset.confirmBusy) {
        $$('button[type="submit"], button:not([type])', form).forEach((b) => { b.disabled = true; b.textContent = form.dataset.confirmBusy; });
      }
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
    // Esc chỉ đóng lớp trên cùng: đang mở thanh ⌘K thì để nó tự đóng, không đóng luôn dropdown/sidebar phía dưới.
    if (e.key === 'Escape' && $('[data-cmdk]')?.hidden !== false) {
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
  // Mac/iPhone/iPad dùng ⌘, còn lại dùng Ctrl. userAgentData mới hơn navigator.platform (đã lỗi thời).
  const isMac = /mac|iphone|ipad|ios/i.test(navigator.userAgentData?.platform || navigator.platform || navigator.userAgent);
  // Đổi tên thứ đang sửa trên tab trình duyệt mà không tải lại trang (gõ tiêu đề, đổi tệp trong trình sửa mã).
  function setSubject(subject) {
    const t = $('title');
    subject = String(subject).trim();
    if (t?.dataset.page) document.title = (subject ? subject + ' — ' : '') + t.dataset.page + ' · ' + t.dataset.site;
  }

  // Thông báo của theme/plugin (lq_admin_notice): nút ẩn nhớ theo người dùng ở máy chủ.
  document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-notice-dismiss]');
    if (!btn) return;
    const box = btn.closest('[data-notice]');
    box.remove();
    const body = new URLSearchParams({ id: box.dataset.notice, _token: csrf() });
    fetch('/admin/notices/dismiss', { method: 'POST', body, headers: { Accept: 'application/json', 'X-CSRF-Token': csrf() } }).catch(() => {});
  });

  window.LQ = Object.assign(window.LQ || {}, { toast, confirm: confirmDialog, slugify, csrf, isMac, mod: isMac ? '⌘' : 'Ctrl ', setSubject });

  // data-off-when="tên checkbox": khối bị làm mờ + khoá khi checkbox đó đang bật (vd. "Giữ kích thước gốc").
  $$('[data-off-when]').forEach((box) => {
    const cb = $(`input[type="checkbox"][name="${box.dataset.offWhen}"]`);
    if (!cb) return;
    // readonly chứ không disabled: ô disabled không được gửi lên nên lưu xong sẽ mất số đã nhập.
    const sync = () => { box.classList.toggle('is-off', cb.checked); $$('input, textarea', box).forEach((i) => { i.readOnly = cb.checked; }); };
    cb.addEventListener('change', sync);
    sync();
  });

  // Ô tiêu đề bài viết/trang: tab và breadcrumb đi theo khi gõ.
  $$('.title-input[name="title"]').forEach((input) => input.addEventListener('input', () => setSubject(input.value)));

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
      // Form hàng loạt dùng chung cho bài viết và trang tĩnh — trước đây trang cũng bị hỏi "… 3 bài viết?".
      const noun = form.dataset.bulkNoun || (/\/admin\/pages\//.test(form.getAttribute('action') || '') ? 'trang' : 'bài viết');
      confirmDialog({
        title: `${label} ${n} ${noun}?`,
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
      // Chưa cài ngay: máy chủ giải nén vào thư mục tạm, quét rồi mở trang kiểm tra để người dùng quyết định.
      if (window.Swal) Swal.fire({ title: 'Đang tải lên và kiểm tra…', allowOutsideClick: false, customClass: { popup: 'lq-swal lq-swal-loading' }, didOpen: () => Swal.showLoading() });
      form.submit();
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
           margin:22px 24px;padding:0;position:relative;-webkit-font-smoothing:antialiased}
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
  /*
   * Nội dung lưu KHÔNG có <p> bọc đoạn văn (kiểu WordPress): các đoạn cách nhau một dòng trống, xuống dòng đơn = <br>.
   * TinyMCE bắt buộc có khối bọc nên: lúc lấy nội dung ra thì gỡ <p> trơn ở cấp ngoài cùng (removeP),
   * lúc nạp vào thì bọc lại (autoP). Frontend bọc lại bằng lq_autop() (app/template.php) — hai bên phải khớp nhau.
   */
  const BLOCK_RE = /^<\/?(p|h[1-6]|ul|ol|li|dl|blockquote|pre|table|thead|tbody|tfoot|tr|figure|figcaption|div|hr|section|article|aside|header|footer|nav|iframe|video|audio|details|summary|form|address)\b/i;
  const escHtml = (t) => t.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  function removeP(html) {
    const tpl = document.createElement('template');
    tpl.innerHTML = html;
    const out = [];
    let inline = '';
    const flush = () => { if (inline.trim()) out.push(inline.trim()); inline = ''; };
    tpl.content.childNodes.forEach((n) => {
      if (n.nodeType === 3) { inline += escHtml(n.data); return; }
      if (n.nodeType !== 1) return;
      // <p> có thuộc tính (vd. căn giữa) phải giữ nguyên, không thì mất định dạng.
      if (n.tagName === 'P' && !n.attributes.length) {
        flush();
        out.push(n.innerHTML.replace(/<br\s*\/?>\n?/gi, '\n').trim());
      } else if (BLOCK_RE.test(n.outerHTML)) {
        flush();
        out.push(n.outerHTML);
      } else {
        inline += n.outerHTML;
      }
    });
    flush();
    return out.join('\n\n');
  }
  function autoP(text) {
    if (!text.trim()) return '';
    // <pre> có thể chứa dòng trống — tách riêng ra để không bị cắt thành nhiều đoạn.
    const keep = [];
    text = text.replace(/<(pre|script|style|textarea)\b[\s\S]*?<\/\1>/gi, (m) => `\u0000${keep.push(m) - 1}\u0000`);
    return text.split(/\n\s*\n/).map((c) => c.trim()).filter(Boolean)
      .map((c) => (BLOCK_RE.test(c) || /^\u0000\d+\u0000$/.test(c) ? c : `<p>${c.replace(/\n/g, '<br>\n')}</p>`))
      .join('\n')
      .replace(/\u0000(\d+)\u0000/g, (_, i) => keep[i]);
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
      // Nút AI đứng đầu: đặt cuối sẽ bị dồn vào menu "…" khi khung soạn thảo hẹp.
      toolbar: (window.LQ_AI?.enabled ? 'lqai | ' : '') + 'blocks | bold italic underline strikethrough | forecolor | alignleft aligncenter alignright | bullist numlist blockquote | link image media table | removeformat code fullscreen',
      // Thanh công cụ đứng yên ở đầu khung soạn thảo, không trôi theo khi cuộn trang.
      toolbar_sticky: false,
      min_height: 480,
      max_height: 1400,
      autoresize_bottom_margin: 40,
      relative_urls: false,
      convert_urls: false,
      image_caption: true,
      content_style: editorCss(dark),
      setup: (ed) => {
        // ⌘S / Ctrl+S trong khung soạn thảo (iframe nên phím không tới document): lưu bài.
        ed.addShortcut('meta+s', 'Đăng', () => window.LQ?.quickSave?.(true));
        ed.addShortcut('meta+shift+s', 'Lưu nháp', () => window.LQ?.quickSave?.());
        // Nút AI: danh sách thao tác lấy từ ai.js lúc bấm (ai.js nạp sau admin.js).
        if (window.LQ_AI?.enabled) {
          ed.ui.registry.addMenuButton('lqai', {
            icon: 'ai',
            tooltip: 'Trợ lý AI — sửa đoạn đang bôi đen',
            fetch: (done) => done(window.LQ.aiMenu ? window.LQ.aiMenu(ed) : []),
          });
        }
        // Bỏ qua chèn/dán vào giữa (selection) — chỉ xử lý khi nạp và lấy toàn bộ nội dung.
        ed.on('BeforeSetContent', (e) => { if (!e.selection && e.format !== 'raw' && e.content) e.content = autoP(e.content); });
        ed.on('GetContent', (e) => { if (!e.selection && e.format === 'html') e.content = removeP(e.content); });
      },
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
    // Code khác (trợ lý AI) thêm thẻ: tagBox.dispatchEvent(new CustomEvent('lq:add', { detail: 'PHP, MariaDB' }))
    tagBox.addEventListener('lq:add', (e) => add(String(e.detail || '')));
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
      // Bộ gõ có gạch chân (IME, vd. Telex mặc định của macOS): Enter lúc này là để chốt chữ, không phải thêm thẻ.
      // Safari báo isComposing = false cho cú Enter chốt chữ nhưng keyCode = 229.
      if (e.isComposing || e.keyCode === 229) return;
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
    // Nhãn phím tắt: server đã in theo User-Agent, ở đây sửa lại theo nền tảng thật của trình duyệt.
    $$('[data-kbd]').forEach((k) => { k.textContent = (LQ.isMac ? '⌘' : 'Ctrl ') + k.dataset.kbd; });

    const input = $('[data-cmdk-input]', cmdk);
    const list = $('[data-cmdk-list]', cmdk);
    const nav = window.LQ_NAV || [];
    const can = window.LQ_CAN || {};
    const aiOn = !!window.LQ_AI?.enabled;
    // Chọn tệp ngay trong cú bấm/Enter của người dùng (trình duyệt chỉ cho mở hộp chọn tệp khi có thao tác thật).
    const pickFiles = (accept, multiple, onPick) => {
      const f = document.createElement('input');
      f.type = 'file';
      f.accept = accept;
      f.multiple = multiple;
      f.addEventListener('change', () => f.files.length && onPick(f));
      f.click();
    };
    // Tải gói plugin/theme: gửi đúng form của trang Plugin/Theme (POST multipart) với tệp vừa chọn.
    const uploadPackage = (kind) => pickFiles('.zip,application/zip', false, (f) => {
      const form = document.createElement('form');
      form.method = 'post';
      form.action = `/admin/${kind}/upload`;
      form.enctype = 'multipart/form-data';
      form.hidden = true;
      form.innerHTML = `<input type="hidden" name="_token" value="${escapeHtml(csrf())}">`;
      f.name = 'package';
      form.append(f);
      document.body.append(form);
      form.submit();
    });
    const actions = [
      can.edit_posts && { label: 'Viết bài mới', url: '/admin/posts/new', icon: 'pen-line', hint: 'Tạo', keywords: 'bài viết mới, soạn bài' },
      can.edit_pages && { label: 'Thêm trang mới', url: '/admin/pages/new', icon: 'file-plus', hint: 'Tạo', keywords: 'trang tĩnh mới' },
      can.edit_posts && aiOn && { label: 'Viết bài từ tiêu đề bằng AI', url: '/admin/posts/new?ai_title=', icon: 'sparkles', hint: 'AI', keywords: 'tạo nội dung, viết tự động, gemini, claude, chatgpt' },
      can.manage_terms && { label: 'Thêm chuyên mục', url: '/admin/categories', icon: 'folder-plus', hint: 'Tạo', keywords: 'danh mục mới' },
      can.upload_files && { label: 'Tải ảnh lên', icon: 'image-up', hint: 'Media', keywords: 'upload ảnh, thêm ảnh, hình',
        run: () => pickFiles('image/jpeg,image/png,image/gif,image/webp,image/avif', true, (f) => LQ.uploadMedia(f.files).then((items) => {
          if (!items.length) return;
          toast('success', `Đã tải lên ${items.length} ảnh.`);
          if (location.pathname === '/admin/media') location.reload();
        })) },
      can.manage_options && { label: 'Dọn dẹp ảnh không dùng', url: '/admin/media#cleanup', icon: 'eraser', hint: 'Media', keywords: 'xoá ảnh thừa, dọn media, giải phóng dung lượng' },
      can.manage_plugins && { label: 'Tải lên plugin (.zip)', icon: 'puzzle', hint: 'Cài', keywords: 'cài plugin, upload plugin', run: () => uploadPackage('plugins') },
      can.manage_themes && { label: 'Tải lên theme (.zip)', icon: 'palette', hint: 'Cài', keywords: 'cài theme, cài giao diện, upload theme', run: () => uploadPackage('themes') },
      can.edit_posts && { label: 'Bài viết nháp', url: '/admin/posts?status=draft', icon: 'file-pen', hint: 'Lọc', keywords: 'bản nháp, draft' },
      can.edit_posts && { label: 'Bài chờ duyệt', url: '/admin/posts?status=pending', icon: 'hourglass', hint: 'Lọc', keywords: 'pending, duyệt bài' },
      can.edit_posts && { label: 'Thùng rác bài viết', url: '/admin/posts?status=trash', icon: 'trash-2', hint: 'Lọc', keywords: 'bài đã xoá, khôi phục bài' },
      can.edit_pages && { label: 'Thùng rác trang', url: '/admin/pages?status=trash', icon: 'trash-2', hint: 'Lọc', keywords: 'trang đã xoá, khôi phục trang' },
      { label: 'Tuỳ chỉnh Bảng tin', url: '/admin#customize', icon: 'layout-dashboard', hint: 'Lệnh', keywords: 'sắp xếp widget, thêm widget, kéo thả' },
      aiOn && window.LQ_AI.chat && { label: 'Mở trợ lý AI', run: () => LQ.aiChat?.open?.(), icon: 'bot', hint: (LQ.isMac ? '⌘' : 'Ctrl ') + 'J', keywords: 'chat, hỏi đáp, mcp' },
      { label: 'Đổi giao diện sáng / tối', run: () => $('[data-theme-toggle]').click(), icon: 'sun-moon', hint: 'Lệnh', keywords: 'dark mode, chế độ tối' },
      { label: 'Phím tắt bàn phím', run: () => showShortcuts(), icon: 'keyboard', hint: '?', keywords: 'shortcut, hotkey' },
      { label: 'Xem trang web', url: '/', icon: 'globe', hint: 'Mở', blank: true, keywords: 'trang chủ, website, frontend' },
      { label: 'Đăng xuất', run: () => $('form[action="/admin/logout"]').submit(), icon: 'log-out', hint: 'Lệnh', keywords: 'thoát' },
    ].filter(Boolean);

    let items = [];
    let active = 0;
    let timer;
    let seq = 0;
    let moved = false; // người dùng đã tự chọn mục (phím mũi tên / rê chuột) kể từ lần gõ gần nhất

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
      // Mục "Hỏi AI" đứng đầu cho dễ thấy nhưng không chiếm Enter: Enter vẫn mở kết quả tìm được, hỏi AI bằng Tab hoặc bấm chuột.
      if (active === 0 && items[0]?.ai) { const first = items.findIndex((x, i) => i > 0 && !x.aiGroup); if (first > 0) active = first; }
      active = Math.min(active, Math.max(0, items.length - 1));
      paint();
      refreshIcons();
    };
    const paint = () => {
      $$('.cmdk-item', list).forEach((b, i) => b.classList.toggle('is-active', i === active));
      const cur = $('.cmdk-item.is-active', list);
      if (cur) cur.scrollIntoView({ block: 'nearest' });
    };

    // Khớp theo tên, nhóm cha hoặc từ khoá mô tả nội dung trang (vd. "múi giờ" → Cài đặt › Tổng quan).
    // Trùng tên/nhóm xếp trước, chỉ trùng từ khoá xếp sau.
    const local = (q) => {
      const f = fold(q);
      const score = (o) => (!q ? 1 : fold(o.label).includes(f) || fold(o.parent || '').includes(f) ? 2 : fold(o.keywords || '').includes(f) ? 1 : 0);
      const rank = (list) => list.map((o) => [o, score(o)]).filter(([, s]) => s).sort((a, b) => b[1] - a[1]).map(([o]) => o);
      const pages = rank(nav).map((n) => ({ label: n.label, url: n.url, icon: n.icon, hint: n.parent || 'Trang' }));
      return [['Thao tác', rank(actions)], ['Đi tới', pages]];
    };

    // Đề xuất hỏi trợ lý AI với đúng từ khoá đang gõ (chỉ khi khung chat AI có trên trang).
    const askAI = (q) => {
      if (!q || !aiOn) return [];
      const rows = [
        window.LQ?.aiChat?.ask && { label: `Hỏi ${window.LQ_AI.provider || 'AI'}: ${q}`, icon: 'sparkles', hint: 'Tab', ai: true, run: () => LQ.aiChat.ask(q) },
        can.edit_posts && { label: `Viết bài bằng AI: ${q}`, icon: 'pen-line', hint: 'Tạo', aiGroup: true, url: '/admin/posts/new?ai_title=' + encodeURIComponent(q) },
      ].filter(Boolean);
      return rows.length ? [['Trợ lý AI', rows]] : [];
    };

    const search = () => {
      const q = input.value.trim();
      const base = [...askAI(q), ...local(q)];
      active = 0;
      moved = false;
      clearTimeout(timer);
      // Tăng seq cả khi ô trống (xoá hết chữ, đóng rồi mở lại ⌘K): kết quả của từ khoá cũ đang bay về phải bị bỏ,
      // không thì nó đè lên danh sách mặc định dù ô tìm kiếm đã trống.
      const my = ++seq;
      if (!q) { render(base, q, false); return; }
      render(base, q, true);
      timer = setTimeout(() => {
        fetch('/admin/api/search?q=' + encodeURIComponent(q), { headers: { Accept: 'application/json' } })
          .then((r) => r.json())
          .then((d) => {
            if (my !== seq) return;
            const posts = (d.posts || []).map((p) => ({ label: p.title, url: p.url, icon: p.statusKey === 'trash' ? 'trash-2' : p.type === 'page' ? 'file' : 'file-text', hint: p.status }));
            const terms = (d.terms || []).map((t) => ({ label: t.name, url: t.url, icon: t.taxonomy === 'tag' ? 'hash' : t.taxonomy === 'category' ? 'folder' : 'tags', hint: t.type }));
            const media = (d.media || []).map((m) => ({ label: m.name, url: m.url, icon: 'image', hint: m.hint || 'Ảnh' }));
            const ext = [...(d.plugins || []).map((p) => ({ label: p.name, url: p.url, icon: 'puzzle', hint: p.hint })),
              ...(d.themes || []).map((t) => ({ label: t.name, url: t.url, icon: 'palette', hint: t.hint }))];
            const files = (d.files || []).map((x) => ({ label: x.name, url: x.url, icon: 'file-code', hint: 'Tệp theme' }));
            // Kết quả chèn thêm nhóm phía trên → giữ đúng mục người dùng đã chọn trong lúc chờ, không để Enter mở nhầm mục khác.
            const keep = moved ? items[active] : null;
            render([...askAI(q), ['Bài viết & trang', posts], ['Phân loại', terms], ['Ảnh', media], ['Plugin & theme', ext], ['Tệp theme', files], ...local(q)], q, false);
            const i = keep ? items.findIndex((x) => x === keep || (x.label === keep.label && x.url === keep.url && x.run === keep.run)) : -1;
            if (i >= 0) { active = i; paint(); }
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
      if (e.isComposing || e.keyCode === 229) return; // đang chốt chữ bằng bộ gõ: Enter không được mở kết quả
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') moved = true;
      if (e.key === 'ArrowDown') { e.preventDefault(); active = (active + 1) % Math.max(1, items.length); paint(); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); active = (active - 1 + items.length) % Math.max(1, items.length); paint(); }
      else if (e.key === 'Enter') { e.preventDefault(); go(items[active]); }
      else if (e.key === 'Tab' && items[0]?.ai) { e.preventDefault(); go(items[0]); }
    });
    list.addEventListener('click', (e) => { const b = e.target.closest('[data-idx]'); if (b) go(items[+b.dataset.idx]); });
    list.addEventListener('pointermove', (e) => {
      const b = e.target.closest('[data-idx]');
      if (b && +b.dataset.idx !== active) { active = +b.dataset.idx; moved = true; $$('.cmdk-item', list).forEach((x, i) => x.classList.toggle('is-active', i === active)); }
    });
    document.addEventListener('click', (e) => {
      if (e.target.closest('[data-cmdk-open]')) { open(); document.body.classList.remove('sidebar-open'); }
      if (e.target.closest('[data-cmdk-close]')) close();
    });
    // Hộp chọn ảnh (z-index 1400) nằm trên cả ⌘K (300) lẫn SweetAlert (1060): mở thêm lớp nào lúc này cũng bị che mất
    // mà vẫn giành focus → gõ phím rơi vào ô tìm kiếm vô hình.
    const pickerOpen = () => document.body.classList.contains('picker-open');
    document.addEventListener('keydown', (e) => {
      if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); if (!cmdk.hidden) close(); else if (!pickerOpen()) open(); }
      else if (e.key === 'Escape' && !cmdk.hidden) { e.preventDefault(); close(); }
      // "?" (Shift + /): bảng phím tắt — trừ khi đang gõ chữ hoặc có hộp thoại.
      else if (e.key === '?' && cmdk.hidden && !e.target.closest?.('input, textarea, select, [contenteditable="true"], .tox')
        && !e.target.isContentEditable && !document.querySelector('.swal2-container') && !pickerOpen()) { e.preventDefault(); showShortcuts(); }
    });

    /** Bảng phím tắt của admin (mở bằng "?" hoặc lệnh "Phím tắt bàn phím" trong ⌘K). */
    function showShortcuts() {
      if (!window.Swal) return;
      const m = LQ.isMac ? '⌘' : 'Ctrl';
      const k = (...keys) => keys.map((x) => `<kbd>${escapeHtml(x)}</kbd>`).join('');
      const groups = [
        ['Mọi trang', [[k(m, 'K'), 'Tìm kiếm & lệnh nhanh'], [k(m, 'J'), 'Mở / đóng trợ lý AI'], [k('?'), 'Bảng phím tắt này'], [k('Esc'), 'Đóng hộp thoại, khung đang mở']]],
        ['Thanh tìm kiếm ' + m + 'K', [[k('↑') + k('↓'), 'Di chuyển'], [k('Enter'), 'Mở mục đang chọn'], [k('Tab'), 'Hỏi AI với từ khoá đang gõ']]],
        ['Thư viện Media', [[k(m, 'A'), 'Chọn tất cả ảnh'], [k(m) + ' + bấm', 'Chọn từng ảnh'], [k('Shift') + ' + bấm', 'Chọn cả dải ảnh'], [k(LQ.isMac ? '⌫' : 'Del'), 'Xoá ảnh đang chọn']]],
        ['Viết bài / trang', [[k(m, 'S'), 'Đăng bài (bài đã đăng: Cập nhật · không có quyền đăng: Gửi duyệt)'], [k(m, 'Shift', 'S'), 'Lưu nháp (bài đã đăng thì chỉ lưu, không gỡ bài)']]],
        ['Sửa mã theme', [[k(m, 'S'), 'Lưu tệp'], [k(m, 'P'), 'Tìm tệp']]],
        ['Trợ lý AI', [[k('Enter'), 'Gửi câu hỏi'], [k('Shift', 'Enter'), 'Xuống dòng'], [k(m, 'Enter'), 'Gửi yêu cầu trong hộp AI (viết bài, sửa đoạn)']]],
      ];
      Swal.fire({
        title: 'Phím tắt bàn phím',
        html: `<div class="kb-list">${groups.map(([title, rows]) => `<section><h4>${escapeHtml(title)}</h4>${rows.map(([keys, text]) => `<div><span>${keys}</span><em>${escapeHtml(text)}</em></div>`).join('')}</section>`).join('')}</div>`,
        showConfirmButton: false, showCloseButton: true, buttonsStyling: false,
        customClass: { popup: 'lq-swal lq-swal-wide' },
      });
    }
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
      filterCatalog();
    };

    // Ngăn "Thêm widget": mặc định ẩn widget đang dùng, tick "Đã dùng" để hiện lại; kết hợp với ô tìm kiếm.
    const showUsed = $('[data-drawer-show-used]');
    const filterCatalog = () => {
      const q = drawerSearch ? fold(drawerSearch.value.trim()) : '';
      const items = $$('[data-catalog-id]');
      items.forEach((c) => {
        c.hidden = (!showUsed.checked && c.classList.contains('is-added')) || (q !== '' && !fold(c.dataset.search).includes(q));
      });
      $$('[data-drawer-group]').forEach((g) => { g.hidden = !$$('[data-catalog-id]:not([hidden])', g).length; });
      $('[data-drawer-used-count]').textContent = `(${$$('[data-catalog-id].is-added').length})`;
      const none = $('[data-drawer-none]');
      none.hidden = items.some((c) => !c.hidden);
      const usedMatch = !showUsed.checked && items.some((c) => c.classList.contains('is-added') && (q === '' || fold(c.dataset.search).includes(q)));
      none.textContent = usedMatch ? (q !== '' ? 'Widget khớp đều đang dùng.' : 'Bạn đã dùng hết widget.') + ' Tick “Đã dùng” để xem.' : 'Không có widget nào khớp.';
    };
    showUsed.addEventListener('change', filterCatalog);

    let saveTimer = null;
    const send = () => {
      saveTimer = null;
      // keepalive: request vẫn đi tiếp khi trang đang bị rời (flush lúc pagehide).
      fetch('/admin/dashboard/layout', {
        method: 'POST',
        keepalive: true,
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': csrf() },
        body: JSON.stringify({ layout: layout() }),
      }).then((r) => r.json()).then((d) => {
        if (!d.ok) toast('error', d.message || 'Không lưu được bố cục.');
      }).catch(() => toast('error', 'Mất kết nối, chưa lưu được bố cục.'));
    };
    const save = () => {
      clearTimeout(saveTimer);
      saveTimer = setTimeout(send, 350);
    };
    // Gửi ngay lần lưu đang chờ: bấm "Xong" rồi chuyển trang ngay trong 350ms thì thay đổi cuối từng bị mất.
    const flush = () => { if (saveTimer) { clearTimeout(saveTimer); send(); } };
    window.addEventListener('pagehide', flush);

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
      // Đánh dấu ngay là đang dùng: bấm "Thêm" 2 lần nhanh (trước khi server trả HTML) từng chèn 2 widget giống nhau.
      placeholder.dataset.widget = id;
      placeholder.dataset.size = size;
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

    // Mở từ lệnh "Tuỳ chỉnh Bảng tin" trong ⌘K (/admin#customize). Đang ở Bảng tin thì trình duyệt chỉ đổi hash
    // chứ không tải lại trang → nghe cả hashchange.
    const fromHash = () => { if (location.hash === '#customize') { history.replaceState(null, '', location.pathname); setEditing(true); } };
    fromHash();
    window.addEventListener('hashchange', fromHash);

    document.addEventListener('click', (e) => {
      if (e.target.closest('[data-dash-edit]')) setEditing(true);
      if (e.target.closest('[data-dash-done]')) { flush(); setEditing(false); toast('success', 'Đã lưu bố cục Bảng tin.'); }
      if (e.target.closest('[data-drawer-open]')) { setEditing(true); openDrawer(); }
      if (e.target.closest('[data-drawer-close]')) closeDrawer();

      const add = e.target.closest('[data-catalog-add]');
      if (add) {
        const item = add.closest('[data-catalog-id]');
        if (item.classList.contains('is-added')) return;
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
      if (e.key === 'Escape' && !e.defaultPrevented && document.body.classList.contains('dash-editing') && $('[data-cmdk]').hidden) {
        document.body.classList.contains('drawer-open') ? closeDrawer() : setEditing(false);
      }
    });

    if (drawerSearch) {
      drawerSearch.addEventListener('input', filterCatalog);
    }
    syncCatalog();
  }

  /* ------------------------------------------------------------------
   * Chống mất bài: tự lưu, khôi phục bản tự lưu, khoá khi nhiều người cùng sửa
   * ------------------------------------------------------------------ */
  const guardForm = $('[data-post-form]');

  // Trong form bài viết/trang: ⌘S / Ctrl+S bấm nút đăng ẩn [data-quick-publish];
  // ⌘⇧S / Ctrl+Shift+S bấm nút lưu ẩn [data-quick-save] — lưu nháp, bài đã đăng thì giữ nguyên trạng thái (không gỡ bài).
  const quickBtn = guardForm && $('[data-quick-save]', guardForm);
  if (quickBtn) {
    const publishBtn = $('[data-quick-publish]', guardForm);
    LQ.quickSave = (publish = false) => {
      const btn = publish ? publishBtn : quickBtn;
      if (!btn || document.querySelector('.swal2-container') || guardForm.dataset.submitting) return;
      guardForm.dataset.submitting = '1'; // chặn nhấn giữ phím gửi nhiều lần; tự mở lại nếu lần gửi bị chặn (ô bắt buộc trống…)
      setTimeout(() => delete guardForm.dataset.submitting, 3000);
      guardForm.requestSubmit(btn);
    };
    document.addEventListener('keydown', (e) => {
      if ((e.metaKey || e.ctrlKey) && !e.altKey && e.key.toLowerCase() === 's') { e.preventDefault(); LQ.quickSave(!e.shiftKey); }
    });
    // Gợi ý phím tắt trên nút hiển thị cùng tác dụng: Đăng bài / Gửi duyệt / Cập nhật (⌘S), Lưu nháp (⌘⇧S).
    const [cmd, shift] = LQ.isMac ? ['⌘', '⌘⇧'] : ['Ctrl+', 'Ctrl+Shift+'];
    $$('[name="action"]:not([hidden])', guardForm).forEach((b) => {
      if (['publish', 'pending', 'update'].includes(b.value)) b.title = `${cmd}S`;
      else if (b.value === quickBtn.value) b.title = `${shift}S`;
    });
  }

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
    let saving = null; // promise của lần tự lưu đang chạy
    guardForm.addEventListener('submit', (e) => {
      // Bài mới mà lần tự lưu đầu (tạo bản nháp) chưa trả về: gửi form lúc này sẽ tạo thêm một bài trùng.
      // Chờ tự lưu xong (form đã có id) rồi mới gửi lại bằng đúng nút đã bấm.
      if (inFlight && !postId && saving && !e.defaultPrevented) {
        e.preventDefault();
        const btn = e.submitter;
        saving.finally(() => guardForm.requestSubmit(btn && btn.form === guardForm ? btn : undefined));
        return;
      }
      submitting = true;
    });

    const autosave = () => {
      if (submitting || inFlight || lastSnap === null) return;
      const cur = snap();
      if (cur === lastSnap) return;
      inFlight = true;
      setStatus('is-saving', 'Đang lưu…');
      // Trang mới: gửi kèm trang cha đang chọn để slug được tính đúng cấp (không thì bị coi như trang gốc → "-2" oan).
      const parentEl = $('[name="parent_id"]', guardForm);
      saving = post(base + '/autosave', { id: postId || '', title: titleEl.value, content: getContent(), ...(parentEl && !postId ? { parent_id: parentEl.value } : {}) })
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
