/* Trợ lý AI trong admin (LQ\Admin\AIController): trang Cài đặt › AI, nút AI trong TinyMCE,
   khung gợi ý tiêu đề/sapo/SEO và nút "Tìm lỗi bằng AI" trong trình sửa mã theme. */
(() => {
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const csrf = () => $('meta[name="csrf-token"]')?.content || '';
  const toast = (type, msg) => (window.LQ?.toast ? LQ.toast(type, msg) : alert(msg));
  const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const icons = () => window.lucide && lucide.createIcons();
  const ai = window.LQ_AI || { enabled: false };

  /** POST JSON (hoặc form) → {ok, ...}. Lỗi mạng/máy chủ cũng trả về {ok:false, message}. */
  async function post(url, data, asForm = false) {
    try {
      const r = await fetch(url, {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrf(), Accept: 'application/json', ...(asForm ? {} : { 'Content-Type': 'application/json' }) },
        body: asForm ? data : JSON.stringify(data),
      });
      const j = await r.json().catch(() => ({ ok: false, message: `Máy chủ trả lỗi ${r.status}.` }));
      return j;
    } catch (_) {
      return { ok: false, message: 'Mất kết nối tới máy chủ.' };
    }
  }

  const busy = (title, text = '') => window.Swal && Swal.fire({
    title, text, allowOutsideClick: false, customClass: { popup: 'lq-swal lq-swal-loading' }, didOpen: () => Swal.showLoading(),
  });
  const done = () => window.Swal && Swal.close();

  /** Hộp thoại theo giao diện admin (nút .btn, ô .input) thay cho kiểu mặc định của SweetAlert2.
      customClass được gộp từng khoá — truyền thẳng vào Swal.fire thì nó thay cả object và mất kiểu nút. */
  const fire = (opts) => Swal.fire({
    buttonsStyling: false,
    reverseButtons: true,
    ...opts,
    customClass: {
      popup: 'lq-swal', confirmButton: 'btn btn-primary', cancelButton: 'btn btn-secondary', input: 'input', validationMessage: 'lq-swal-validation',
      ...(opts.customClass || {}),
    },
  });

  /**
   * Chọn một trong các phương án AI đưa ra (mô tả, tiêu đề…). Không dùng input "radio" của SweetAlert2: lớp .input
   * gắn cho ô nhập (xem fire) làm khung radio cao cố định rồi tràn đè lên tiêu đề và nút.
   * @returns {Promise<string|null>}
   */
  async function choose({ title, sub = '', options, confirmText, max = 0 }) {
    const r = await fire({
      title,
      html: (sub ? `<p class="ai-choose-sub">${esc(sub)}</p>` : '')
        + `<div class="ai-choose" role="radiogroup">${options.map((o, i) => `<button type="button" class="ai-choice" role="radio" aria-checked="false" data-i="${i}">`
          + `<span class="ai-choice-dot"></span><span class="ai-choice-text">${esc(o)}</span>`
          + `<span class="ai-choice-len${max && o.length > max ? ' is-over' : ''}">${o.length} ký tự</span></button>`).join('')}</div>`,
      showCancelButton: true, confirmButtonText: confirmText, cancelButtonText: 'Huỷ', focusConfirm: false,
      customClass: { popup: 'lq-swal lq-swal-wide' },
      didOpen: (pop) => {
        pop.querySelector('.ai-choose').addEventListener('click', (e) => {
          const b = e.target.closest('.ai-choice');
          if (!b) return;
          pop.querySelectorAll('.ai-choice').forEach((x) => x.setAttribute('aria-checked', String(x === b)));
        });
        pop.querySelector('.ai-choice')?.focus();
      },
      preConfirm: () => {
        const b = Swal.getPopup().querySelector('.ai-choice[aria-checked="true"]');
        if (!b) { Swal.showValidationMessage('Chọn một phương án.'); return false; }
        return +b.dataset.i;
      },
    });
    return r.isConfirmed ? options[r.value] : null;
  }

  /* ------------------------------------------------------------------
   * Cài đặt › AI
   * ------------------------------------------------------------------ */
  // Card ở trên chỉ có trạng thái + nút; API key/Model của mỗi nhà cung cấp nằm trong panel [data-ai-panel] bên dưới,
  // mỗi lúc chỉ mở một panel (mặc định nhà cung cấp đang dùng, không thì cái đầu tiên đã cài).
  const aiCards = $$('[data-ai-card]');
  const installed = (c) => !$('[data-ai-configure]', c).hidden;
  const openPanel = (id) => {
    const target = aiCards.find((c) => c.dataset.aiCard === id && installed(c)) || aiCards.find((c) => c.classList.contains('is-active') && installed(c)) || aiCards.find(installed);
    aiCards.forEach((c) => c.classList.toggle('is-open', c === target));
    $$('[data-ai-panel]').forEach((p) => { p.hidden = !target || p.dataset.aiPanel !== target.dataset.aiCard; });
    const empty = $('[data-ai-config-empty]');
    if (empty) empty.hidden = !!target;
  };

  aiCards.forEach((card) => {
    const id = card.dataset.aiCard;
    const panel = $(`[data-ai-panel="${id}"]`);
    const q = (sel) => $(sel, card) || (panel && $(sel, panel));
    const status = $('[data-ai-status]', card);
    const keyInput = q('[data-ai-key]');
    const modelInput = q('[data-ai-model]');
    const fields = q('[data-ai-fields]');
    const useRadio = $('input[name="ai_provider"]', card);
    const btnInstall = $('[data-ai-install]:not([data-update])', card);
    const btnUpdate = q('[data-ai-install][data-update]');
    const btnRemove = q('[data-ai-uninstall]');
    const btnConfigure = $('[data-ai-configure]', card);
    const label = $('h3', card).firstChild.textContent.trim();

    const setInstalled = (version) => {
      status.textContent = version ? `Đã cài thư viện v${version}` : 'Chưa cài thư viện';
      btnInstall.hidden = !!version;
      btnUpdate.hidden = btnRemove.hidden = fields.hidden = btnConfigure.hidden = !version;
      useRadio.disabled = !version;
      if (!version && useRadio.checked) {
        $('input[name="ai_provider"][value=""]').checked = true;
        card.classList.remove('is-active');
      }
      openPanel(version ? id : null); // vừa cài → mở cấu hình của nó; vừa gỡ → chuyển sang cái khác còn cài
    };

    btnConfigure.addEventListener('click', () => {
      openPanel(id);
      keyInput.focus({ preventScroll: true });
      panel.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    });

    [...$$('[data-ai-install]', card), ...(panel ? $$('[data-ai-install]', panel) : [])].forEach((btn) => btn.addEventListener('click', async () => {
      busy(`Đang cài thư viện ${label}…`, 'Tải về và cài trên máy chủ, thường mất 10–60 giây.');
      const fd = new FormData();
      fd.append('provider', id);
      const r = await post('/admin/ai/install', fd, true);
      done();
      if (r.ok) { setInstalled(r.version); toast('success', `Đã cài ${label} v${r.version}.`); return; }
      if (window.Swal) {
        fire({ title: 'Cài đặt thất bại', html: `<pre class="ai-log">${esc(r.log || r.message || '')}</pre>`, customClass: { popup: 'lq-swal lq-swal-wide' }, confirmButtonText: 'Đóng' });
      } else toast('error', r.message);
    }));

    btnRemove.addEventListener('click', async () => {
      const ok = await (window.LQ?.confirm ? LQ.confirm({ title: `Gỡ thư viện ${label}?`, text: 'Key và model đã lưu vẫn được giữ, cài lại là dùng tiếp được.', danger: true, confirmText: 'Gỡ' }) : Promise.resolve(confirm('Gỡ?')));
      if (!ok) return;
      const fd = new FormData();
      fd.append('provider', id);
      const r = await post('/admin/ai/uninstall', fd, true);
      if (r.ok) { setInstalled(null); toast('success', `Đã gỡ ${label}.`); } else toast('error', r.message);
    });

    const manualBtn = q('[data-ai-model-manual]');
    // Một nút đổi qua lại: đang ở danh sách → "Gõ tên model" (bút); đang gõ tay mà đã có danh sách → "Chọn từ danh sách".
    const setManualTip = (typing) => {
      manualBtn.dataset.tip = typing ? 'Chọn từ danh sách' : 'Gõ tên model';
      manualBtn.setAttribute('aria-label', manualBtn.dataset.tip);
      manualBtn.innerHTML = `<i data-lucide="${typing ? 'list' : 'pencil'}"></i>`;
      icons();
    };
    q('[data-ai-load-models]').addEventListener('click', async (e) => {
      const btn = e.currentTarget;
      btn.disabled = true;
      btn.classList.add('is-loading');
      const fd = new FormData();
      fd.append('provider', id);
      fd.append('key', keyInput.value.trim());
      const r = await post('/admin/ai/models', fd, true);
      btn.disabled = false;
      btn.classList.remove('is-loading');
      if (!r.ok) { toast('error', r.message); return; }
      // Danh sách chọn thật, không dùng <datalist>: trình duyệt lọc datalist theo chữ đang có trong ô,
      // nên ô đang ghi "gemini-2.5-…" thì chỉ hiện các bản 2.5.
      const sel = q('[data-ai-model-select]');
      const cur = modelInput.value.trim();
      const ids = r.models.map((m) => m.id);
      sel.innerHTML = (cur && !ids.includes(cur) ? `<option value="${esc(cur)}">${esc(cur)} — không còn trong danh sách</option>` : '')
        + r.models.map((m) => `<option value="${esc(m.id)}">${esc(m.id)}${m.name && m.name !== m.id ? ' — ' + esc(m.name) : ''}</option>`).join('');
      sel.value = cur && ids.includes(cur) ? cur : (cur || ids[0] || '');
      sel.hidden = sel.disabled = false;
      modelInput.hidden = modelInput.disabled = true;
      manualBtn.hidden = false;
      setManualTip(false);
      sel.focus();
      toast('success', `Có ${r.models.length} model, mới nhất ở đầu danh sách.`);
    });

    manualBtn.addEventListener('click', () => {
      const sel = q('[data-ai-model-select]');
      const typing = sel.hidden; // đang gõ tay → quay lại danh sách
      if (typing) {
        const v = modelInput.value.trim();
        if (v && ![...sel.options].some((o) => o.value === v)) sel.insertAdjacentHTML('afterbegin', `<option value="${esc(v)}">${esc(v)}</option>`);
        if (v) sel.value = v;
      } else {
        modelInput.value = sel.value;
      }
      sel.hidden = sel.disabled = !typing;
      modelInput.hidden = modelInput.disabled = typing;
      setManualTip(!typing);
      (typing ? sel : modelInput).focus();
    });

    q('[data-ai-test]').addEventListener('click', async (e) => {
      const btn = e.currentTarget;
      const out = q('[data-ai-test-result]');
      btn.disabled = true;
      out.className = 'small muted';
      out.textContent = 'Đang gửi câu hỏi thử…';
      const fd = new FormData();
      fd.append('provider', id);
      fd.append('key', keyInput.value.trim());
      const sel = q('[data-ai-model-select]');
      fd.append('model', (sel.disabled ? modelInput.value : sel.value).trim());
      const r = await post('/admin/ai/test', fd, true);
      btn.disabled = false;
      out.className = 'small ' + (r.ok ? 'ai-ok' : 'ai-err');
      out.textContent = r.ok ? `Kết nối tốt (${(r.ms / 1000).toFixed(1)} giây): “${r.reply}”` : r.message;
    });

    useRadio.addEventListener('change', () => {
      aiCards.forEach((c) => c.classList.toggle('is-active', $('input[name="ai_provider"]', c).checked));
      openPanel(id);
    });
  });
  $('input[name="ai_provider"][value=""]')?.addEventListener('change', () => aiCards.forEach((c) => c.classList.remove('is-active')));
  if (aiCards.length) openPanel(null);

  // Khung chat (ai-chat.js) dùng lại để vẽ so sánh trong thẻ đề xuất — gán trước lệnh return bên dưới.
  window.LQ = Object.assign(window.LQ || {}, { diffLines });
  if (!ai.enabled) return;

  /* ------------------------------------------------------------------
   * TinyMCE: nút AI sửa đoạn đang bôi đen (đăng ký ở admin.js, danh sách thao tác ở đây)
   * ------------------------------------------------------------------ */
  const ACTIONS = [
    ['rewrite', 'Viết lại hay hơn'],
    ['fix', 'Sửa chính tả, ngữ pháp'],
    ['shorter', 'Ngắn gọn hơn'],
    ['longer', 'Viết dài hơn'],
    ['formal', 'Giọng trang trọng'],
    ['casual', 'Giọng thân mật'],
  ];

  async function rewrite(ed, action) {
    const html = ed.selection.getContent();
    if (!ed.selection.getContent({ format: 'text' }).trim()) { toast('error', 'Hãy bôi đen đoạn văn cần sửa trước.'); return; }
    let instruction = '';
    if (action === 'custom') {
      const text = ed.selection.getContent({ format: 'text' }).trim().replace(/\s+/g, ' ');
      const words = text.split(' ').filter(Boolean).length;
      const QUICK = ['Thêm câu kết gợi người đọc đọc tiếp', 'Đổi sang ngôi thứ nhất', 'Thêm một ví dụ minh hoạ', 'Viết dễ hiểu cho người mới',
        'Chuyển thành danh sách gạch đầu dòng', 'Dịch sang tiếng Anh'];
      const mod = window.LQ?.mod || 'Ctrl ';
      const r = await fire({
        title: 'Yêu cầu cho AI',
        html: `<div class="ai-ask">
            <blockquote class="ai-ask-quote"><span>Đoạn đang chọn · ${words} từ</span>${esc(text.length > 160 ? text.slice(0, 160) + '…' : text)}</blockquote>
            <textarea class="input" id="ai-ask-input" rows="3" placeholder="Bạn muốn ${esc(ai.provider)} sửa đoạn này thế nào?"></textarea>
            <div class="ai-ask-chips">${QUICK.map((q) => `<button type="button" class="chip chip-btn" data-q="${esc(q)}">${esc(q)}</button>`).join('')}</div>
          </div>`,
        footer: `<span class="ai-ask-hint"><kbd>${esc(mod.trim())}</kbd><kbd>Enter</kbd> để gửi</span>`,
        showCancelButton: true, confirmButtonText: `Gửi cho ${esc(ai.provider)}`, cancelButtonText: 'Huỷ', focusConfirm: false,
        customClass: { popup: 'lq-swal lq-swal-ask' },
        didOpen: (pop) => {
          const ta = pop.querySelector('#ai-ask-input');
          ta.focus();
          pop.querySelector('.ai-ask-chips').addEventListener('click', (e) => {
            const b = e.target.closest('[data-q]');
            if (!b) return;
            ta.value = ta.value.trim() ? `${ta.value.trim().replace(/[.,;]$/, '')}, ${b.dataset.q.toLowerCase()}` : b.dataset.q;
            ta.focus();
          });
          ta.addEventListener('keydown', (e) => { if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) { e.preventDefault(); Swal.clickConfirm(); } });
        },
        preConfirm: () => {
          const v = Swal.getPopup().querySelector('#ai-ask-input').value.trim();
          if (!v) { Swal.showValidationMessage('Nhập yêu cầu hoặc chọn một gợi ý.'); return false; }
          return v;
        },
      });
      if (!r.isConfirmed) return;
      instruction = r.value;
    }
    const bookmark = ed.selection.getBookmark(2, true);
    busy(`${ai.provider} đang viết…`);
    // Gửi kèm tiêu đề bài để AI viết đúng chủ đề, đúng giọng của bài.
    const r = await post('/admin/ai/rewrite', { html, action, instruction, title: $('input[name="title"]')?.value.trim() || '' });
    done();
    if (!r.ok) { toast('error', r.message); return; }
    const pick = await fire({
      title: 'Bản AI đề xuất',
      html: `<div class="ai-compare"><div><span>Bản gốc</span><div class="ai-prose">${html}</div></div><div><span>Đề xuất</span><div class="ai-prose">${r.html}</div></div></div>`,
      showCancelButton: true, confirmButtonText: 'Thay thế đoạn đã chọn', cancelButtonText: 'Bỏ qua',
      customClass: { popup: 'lq-swal lq-swal-wide' },
    });
    if (!pick.isConfirmed) return;
    ed.focus();
    ed.selection.moveToBookmark(bookmark);
    ed.undoManager.transact(() => ed.selection.setContent(r.html));
    ed.setDirty(true);
    ed.dispatch('change');
  }

  /**
   * Viết nội dung bài/trang từ tiêu đề (nút trong khung Trợ lý AI và mục đầu của menu AI trên thanh công cụ).
   * Gửi kèm chuyên mục, thẻ, sapo, mẫu trang để AI viết đúng chủ đề; xem trước rồi mới chèn vào trình soạn thảo.
   */
  async function writeFromTitle(ed = window.tinymce?.get('content') || window.tinymce?.activeEditor) {
    const titleInput = $('input[name="title"]');
    const title = titleInput?.value.trim() || '';
    if (title.length < 3) { toast('error', 'Nhập tiêu đề trước để AI biết cần viết về gì.'); titleInput?.focus(); return; }
    const isPage = !!$('[data-page-template]');
    const LENGTHS = [['brief', 'Ngắn', '~100 từ'], ['short', 'Vừa', '~300 từ'], ['medium', 'Dài', '~700 từ']];
    const QUICK = isPage
      ? ['Giọng trang trọng', 'Ngắn gọn, dễ đọc', 'Có phần câu hỏi thường gặp', 'Kêu gọi liên hệ ở cuối']
      : ['Hướng dẫn từng bước', 'Viết cho người mới', 'Có danh sách gạch đầu dòng', 'Giọng thân mật', 'Có phần câu hỏi thường gặp'];
    const mod = window.LQ?.mod || 'Ctrl ';
    const ask = await fire({
      title: isPage ? 'Viết nội dung trang' : 'Viết bài từ tiêu đề',
      html: `<div class="ai-ask">
          <blockquote class="ai-ask-quote"><span>Tiêu đề</span>${esc(title)}</blockquote>
          <div class="ai-len" role="radiogroup" aria-label="Độ dài">${LENGTHS.map(([v, l, n]) => `<label><input type="radio" name="ai-len" value="${v}"${v === 'short' ? ' checked' : ''}><span><b>${l}</b><small>${n}</small></span></label>`).join('')}</div>
          <textarea class="input" id="ai-ask-input" rows="3" placeholder="Yêu cầu thêm (không bắt buộc): ý chính, giọng văn, đối tượng đọc…"></textarea>
          <div class="ai-ask-chips">${QUICK.map((q) => `<button type="button" class="chip chip-btn" data-q="${esc(q)}">${esc(q)}</button>`).join('')}</div>
        </div>`,
      footer: `<span class="ai-ask-hint"><kbd>${esc(mod.trim())}</kbd><kbd>Enter</kbd> để viết</span>`,
      showCancelButton: true, confirmButtonText: `Viết bằng ${esc(ai.provider)}`, cancelButtonText: 'Huỷ', focusConfirm: false,
      customClass: { popup: 'lq-swal lq-swal-ask' },
      didOpen: (pop) => {
        const ta = pop.querySelector('#ai-ask-input');
        ta.focus();
        pop.querySelector('.ai-ask-chips').addEventListener('click', (e) => {
          const b = e.target.closest('[data-q]');
          if (!b) return;
          ta.value = ta.value.trim() ? `${ta.value.trim().replace(/[.,;]$/, '')}, ${b.dataset.q.toLowerCase()}` : b.dataset.q;
          ta.focus();
        });
        ta.addEventListener('keydown', (e) => { if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) { e.preventDefault(); Swal.clickConfirm(); } });
      },
      preConfirm: () => {
        const pop = Swal.getPopup();
        return { length: pop.querySelector('input[name="ai-len"]:checked')?.value || 'short', instruction: pop.querySelector('#ai-ask-input').value.trim() };
      },
    });
    if (!ask.isConfirmed) return;
    const labelOf = (input) => input.closest('label')?.textContent.trim() || '';
    const tpl = $('[data-page-template]');
    busy(`${ai.provider} đang viết…`, 'Bài dài có thể mất 10–30 giây.');
    const r = await post('/admin/ai/write', {
      title, type: isPage ? 'page' : 'post', ...ask.value,
      categories: $$('input[name="categories[]"]:checked').map(labelOf),
      tags: ($('input[name="tags"]')?.value || '').split(',').map((t) => t.trim()).filter(Boolean),
      sapo: $('[data-ai-role="sapo"]')?.value || '',
      template: tpl?.value ? tpl.selectedOptions[0]?.textContent.trim() : '',
    });
    done();
    if (!r.ok) { toast('error', r.message); return; }
    ed = ed || window.tinymce?.get('content') || window.tinymce?.activeEditor; // mở ngay khi tải trang (?ai_title=): TinyMCE có thể chưa sẵn lúc gọi
    const hasContent = ed ? !!ed.getContent({ format: 'text' }).trim() : !!($('#content')?.value.trim());
    const pick = await fire({
      title: 'Nội dung AI viết',
      html: `<p class="ai-choose-sub">${r.words} từ · ${r.html.includes('[cần bổ sung]') ? 'điền thông tin vào các chỗ <b>[cần bổ sung]</b> trước khi đăng.' : 'xem lại trước khi chèn — AI có thể viết sai chi tiết.'}</p><div class="ai-prose">${r.html}</div>`,
      showCancelButton: true, cancelButtonText: 'Bỏ qua',
      confirmButtonText: hasContent ? 'Thay toàn bộ nội dung' : 'Chèn vào bài',
      showDenyButton: hasContent, denyButtonText: 'Thêm vào cuối',
      customClass: { popup: 'lq-swal lq-swal-wide', denyButton: 'btn btn-secondary' },
    });
    if (!pick.isConfirmed && !pick.isDenied) return;
    if (!ed) { // không có TinyMCE (lỗi tải CDN…): ghi thẳng vào textarea
      const ta = $('#content');
      if (ta) ta.value = pick.isDenied ? `${ta.value}\n${r.html}` : r.html;
      return;
    }
    ed.undoManager.transact(() => ed.setContent(pick.isDenied ? ed.getContent() + r.html : r.html));
    ed.setDirty(true);
    ed.dispatch('change');
    ed.focus();
    toast('success', 'Đã chèn nội dung — bấm Hoàn tác (Ctrl/⌘ Z) nếu muốn quay lại.');
  }
  $$('[data-ai-write-run]').forEach((b) => b.addEventListener('click', () => writeFromTitle()));
  // Mở từ ⌘K "Viết bài bằng AI": /admin/posts/new?ai_title=… điền sẵn tiêu đề rồi mở hộp viết; tiêu đề trống thì chỉ đặt con trỏ vào ô tiêu đề.
  const aiTitle = new URLSearchParams(location.search).get('ai_title');
  if (aiTitle !== null && $('[data-ai-write-run]')) {
    history.replaceState(null, '', location.pathname);
    const t = $('input[name="title"]');
    if (aiTitle.trim() && t) {
      t.value = aiTitle.trim();
      t.dispatchEvent(new Event('input', { bubbles: true })); // để ô slug tự sinh theo tiêu đề
      writeFromTitle(null);
    } else t?.focus();
  }

  window.LQ = Object.assign(window.LQ || {}, {
    aiMenu: (ed) => [
      { type: 'menuitem', text: $('[data-page-template]') ? 'Viết nội dung trang từ tiêu đề…' : 'Viết bài từ tiêu đề…', onAction: () => writeFromTitle(ed) },
      { type: 'separator' },
      ...ACTIONS.map(([action, text]) => ({ type: 'menuitem', text, onAction: () => rewrite(ed, action) })),
      { type: 'separator' },
      { type: 'menuitem', text: 'Yêu cầu khác…', onAction: () => rewrite(ed, 'custom') },
    ],
  });

  /* ------------------------------------------------------------------
   * Form bài viết/trang: gợi ý tiêu đề, sapo, mô tả SEO
   * ------------------------------------------------------------------ */
  $$('[data-ai-suggest]').forEach((box) => {
    const out = $('[data-ai-results]', box);
    const titleInput = $('input[name="title"]');
    const content = () => (window.tinymce?.activeEditor ? tinymce.activeEditor.getContent() : ($('#content')?.value || ''));

    const fill = (el, value) => {
      el.value = value;
      el.dispatchEvent(new Event('input', { bubbles: true }));
      el.classList.add('ai-flash');
      setTimeout(() => el.classList.remove('ai-flash'), 1200);
    };
    // Field nhận gợi ý: theme/lõi đánh dấu bằng 'ai_role' khi đăng ký field (data-ai-role trên ô nhập).
    const target = (role) => $(`[data-ai-role="${role}"]`);
    const row = (text, attrs, applyLabel) => `<div class="ai-option"><p>${esc(text)}</p><div>`
      + (applyLabel ? `<button type="button" class="btn btn-secondary btn-xs" ${attrs}>${applyLabel}</button>` : '')
      + `<button type="button" class="btn btn-ghost btn-xs" data-ai-copy="${esc(text)}">Chép</button></div></div>`;

    $('[data-ai-suggest-run]', box).addEventListener('click', async (e) => {
      const btn = e.currentTarget;
      btn.disabled = true;
      btn.classList.add('is-loading');
      const r = await post('/admin/ai/suggest', { title: titleInput?.value || '', content: content() });
      btn.disabled = false;
      btn.classList.remove('is-loading');
      if (!r.ok) { toast('error', r.message); return; }
      out.innerHTML = `<h4>Tiêu đề</h4>${r.titles.map((t) => row(t, `data-ai-apply="title" data-value="${esc(t)}"`, 'Dùng')).join('')}`
        + (r.sapo ? `<h4>Sapo</h4>${row(r.sapo, `data-ai-apply="sapo" data-value="${esc(r.sapo)}"`, target('sapo') ? 'Dùng' : '')}` : '')
        + (r.seo_description ? `<h4>Mô tả SEO <small class="muted">${r.seo_description.length} ký tự</small></h4>${row(r.seo_description, `data-ai-apply="seo" data-value="${esc(r.seo_description)}"`, target('seo') ? 'Dùng' : '')}` : '');
      out.hidden = false;
    });

    out.addEventListener('click', (e) => {
      const apply = e.target.closest('[data-ai-apply]');
      const copy = e.target.closest('[data-ai-copy]');
      if (apply) {
        const el = apply.dataset.aiApply === 'title' ? titleInput : target(apply.dataset.aiApply);
        if (!el) return;
        fill(el, apply.dataset.value);
        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        toast('success', 'Đã điền, nhớ bấm Lưu.');
      } else if (copy) {
        navigator.clipboard?.writeText(copy.dataset.aiCopy).then(() => toast('success', 'Đã chép.'));
      }
    });
  });

  /* ------------------------------------------------------------------
   * Form bài viết: gợi ý chuyên mục/thẻ và liên kết nội bộ (chung khung kết quả với gợi ý tiêu đề)
   * ------------------------------------------------------------------ */
  /** Bọc cụm chữ (nguyên văn, không phân biệt hoa thường) trong thẻ <a>, chỉ ở đoạn chưa có link. */
  function linkText(ed, phrase, url) {
    const walker = ed.getDoc().createTreeWalker(ed.getBody(), NodeFilter.SHOW_TEXT);
    const needle = phrase.toLowerCase();
    for (let n = walker.nextNode(); n; n = walker.nextNode()) {
      if (n.parentElement.closest('a')) continue;
      const i = n.data.toLowerCase().indexOf(needle);
      if (i < 0) continue;
      ed.undoManager.transact(() => {
        const mid = n.splitText(i);
        mid.splitText(phrase.length);
        const a = ed.dom.create('a', { href: url }, ed.dom.encode(mid.data));
        mid.parentNode.replaceChild(a, mid);
        ed.selection.select(a);
        a.scrollIntoView({ block: 'center' });
      });
      ed.setDirty(true);
      ed.dispatch('change');
      return true;
    }
    return false;
  }

  $$('[data-ai-suggest]').forEach((box) => {
    const out = $('[data-ai-results]', box);
    const content = () => (window.tinymce?.activeEditor ? tinymce.activeEditor.getContent() : ($('#content')?.value || ''));
    const title = () => $('input[name="title"]')?.value || '';
    const run = async (btn, url, body) => {
      btn.disabled = true;
      btn.classList.add('is-loading');
      const r = await post(url, body);
      btn.disabled = false;
      btn.classList.remove('is-loading');
      if (!r.ok) toast('error', r.message);
      return r.ok ? r : null;
    };

    $('[data-ai-taxonomy-run]', box)?.addEventListener('click', async (e) => {
      const r = await run(e.currentTarget, '/admin/ai/taxonomy', { title: title(), content: content() });
      if (!r) return;
      const chip = (label, attrs) => `<button type="button" class="chip chip-btn" ${attrs}>${esc(label)}</button>`;
      out.innerHTML = '<h4>Chuyên mục</h4><div class="ai-chips">'
        + (r.categories.map((c) => chip(c.name, `data-ai-cat="${c.id}"`)).join('') || '<span class="muted small">Không có chuyên mục phù hợp.</span>') + '</div>'
        + `<h4>Thẻ</h4><div class="ai-chips">${[...r.tags.map((t) => chip(t, `data-ai-tag="${esc(t)}"`)), ...r.new_tags.map((t) => chip(`${t} · mới`, `data-ai-tag="${esc(t)}"`))].join('') || '<span class="muted small">Không có gợi ý.</span>'}</div>`
        + '<p class="muted small">Bấm vào từng mục để chọn/thêm.</p>';
      out.hidden = false;
    });

    $('[data-ai-links-run]', box)?.addEventListener('click', async (e) => {
      const r = await run(e.currentTarget, '/admin/ai/links', { post_id: +box.dataset.postId || 0, title: title(), content: content() });
      if (!r) return;
      out.innerHTML = '<h4>Liên kết nội bộ</h4>' + (r.links.length
        ? r.links.map((l) => `<div class="ai-option"><p><b>“${esc(l.anchor)}”</b> → <a class="link" href="${esc(l.url)}" target="_blank" rel="noopener">${esc(l.title)}</a></p>`
          + `<p class="muted small">${esc(l.reason)}</p><div><button type="button" class="btn btn-secondary btn-xs" data-ai-link="${esc(l.url)}" data-anchor="${esc(l.anchor)}">Chèn link</button></div></div>`).join('')
        : '<p class="muted small">AI không thấy bài nào đủ liên quan để liên kết.</p>');
      out.hidden = false;
    });

    out.addEventListener('click', (e) => {
      const cat = e.target.closest('[data-ai-cat]');
      const tag = e.target.closest('[data-ai-tag]');
      const link = e.target.closest('[data-ai-link]');
      if (cat) {
        const cb = $(`input[name="categories[]"][value="${cat.dataset.aiCat}"]`);
        if (cb) { cb.checked = true; cb.dispatchEvent(new Event('change', { bubbles: true })); cat.classList.add('is-done'); }
      } else if (tag) {
        $('[data-tag-input]:not([data-tax])')?.dispatchEvent(new CustomEvent('lq:add', { detail: tag.dataset.aiTag }));
        tag.classList.add('is-done');
      } else if (link) {
        const ed = window.tinymce?.activeEditor;
        if (ed && linkText(ed, link.dataset.anchor, link.dataset.aiLink)) {
          link.disabled = true;
          link.textContent = 'Đã chèn';
          toast('success', 'Đã chèn link, nhớ bấm Lưu.');
        } else {
          toast('error', 'Không tìm thấy nguyên văn cụm chữ trong một đoạn (có thể nằm vắt qua chữ đậm/nghiêng). Hãy tự bôi đen và chèn link.');
        }
      }
    });
  });

  /* ------------------------------------------------------------------
   * Chuyên mục / thẻ: AI viết mô tả (dùng làm mô tả SEO của trang lưu trữ)
   * ------------------------------------------------------------------ */
  $$('[data-ai-term-desc]').forEach((btn) => btn.addEventListener('click', async () => {
    const form = btn.closest('form');
    const ta = $('[data-term-desc]', form);
    const name = $('input[name="name"]', form)?.value.trim() || '';
    btn.classList.add('is-loading');
    btn.disabled = true;
    const r = await post('/admin/ai/term-describe', { tax: btn.dataset.tax, id: +btn.dataset.id || 0, name });
    btn.classList.remove('is-loading');
    btn.disabled = false;
    if (!r.ok) { toast('error', r.message); return; }
    // Danh sách rỗng: hộp chọn không có gì để chọn, bấm xác nhận chỉ báo "Chọn một phương án" mãi.
    if (!r.options?.length) { toast('error', 'AI không đưa ra phương án nào, thử lại.'); return; }
    const value = await choose({
      title: 'Chọn mô tả',
      sub: r.posts ? `Dựa trên tên và ${r.posts} bài gần đây.` : 'Chưa có bài nào nên AI chỉ dựa vào tên.',
      options: r.options, confirmText: 'Dùng mô tả này', max: 160,
    });
    if (value === null) return;
    ta.value = value;
    ta.dispatchEvent(new Event('input', { bubbles: true }));
    ta.classList.add('ai-flash');
    setTimeout(() => ta.classList.remove('ai-flash'), 1200);
    toast('success', 'Đã điền, nhớ bấm Lưu.');
  }));

  /* ------------------------------------------------------------------
   * Trang kiểm tra gói plugin/theme: nhờ AI đánh giá các chỗ bị đánh dấu
   * ------------------------------------------------------------------ */
  $$('[data-pkg-ai]').forEach((box) => {
    $('[data-pkg-ai-run]', box)?.addEventListener('click', async (e) => {
      const btn = e.currentTarget;
      btn.classList.add('is-loading');
      btn.disabled = true;
      const r = await post('/admin/ai/package-review', { kind: box.dataset.kind, token: box.dataset.token });
      btn.classList.remove('is-loading');
      btn.disabled = false;
      if (!r.ok) { toast('error', r.message); return; }
      const V = { safe: ['Có vẻ an toàn', 'ok'], suspicious: ['Cần xem kỹ', 'warning'], malicious: ['Có dấu hiệu độc hại', 'danger'] }[r.verdict];
      const R = { low: 'Thấp', medium: 'Vừa', high: 'Cao' };
      const res = $('[data-pkg-ai-result]', box);
      res.innerHTML = `<div class="pkg-ai-verdict is-${V[1]}"><b>${V[0]}</b><p>${esc(r.summary)}</p></div>`
        + r.items.map((i) => `<div class="pkg-finding is-${i.risk === 'high' ? 'danger' : 'warning'}"><span class="pkg-sev">Rủi ro ${R[i.risk] || i.risk}</span>`
          + `<div class="pkg-finding-body"><p><code>${esc(i.file)}</code>${i.line ? ` · dòng ${i.line}` : ''}</p><p>${esc(i.assessment)}</p></div></div>`).join('');
      res.hidden = false;
    });
  });

  /* ------------------------------------------------------------------
   * Trình sửa mã theme: tìm lỗi tệp đang mở, xem khác biệt rồi mới áp dụng
   * ------------------------------------------------------------------ */
  /** So sánh theo dòng (LCS) → [{op:'='|'-'|'+', text, line}] — đủ nhanh cho tệp vài nghìn dòng. */
  function diffLines(a, b) {
    const A = a.split('\n'), B = b.split('\n');
    let s = 0;
    while (s < A.length && s < B.length && A[s] === B[s]) s++;
    let ea = A.length, eb = B.length;
    while (ea > s && eb > s && A[ea - 1] === B[eb - 1]) { ea--; eb--; }
    const x = A.slice(s, ea), y = B.slice(s, eb);
    const dp = Array.from({ length: x.length + 1 }, () => new Uint16Array(y.length + 1));
    for (let i = x.length - 1; i >= 0; i--) for (let j = y.length - 1; j >= 0; j--) dp[i][j] = x[i] === y[j] ? dp[i + 1][j + 1] + 1 : Math.max(dp[i + 1][j], dp[i][j + 1]);
    const out = [];
    let i = 0, j = 0;
    while (i < x.length || j < y.length) {
      if (i < x.length && j < y.length && x[i] === y[j]) { out.push({ op: '=', text: x[i], line: s + i + 1 }); i++; j++; }
      else if (j < y.length && (i >= x.length || dp[i][j + 1] >= dp[i + 1][j])) { out.push({ op: '+', text: y[j], line: s + j + 1 }); j++; }
      else { out.push({ op: '-', text: x[i], line: s + i + 1 }); i++; }
    }
    // Chỉ giữ 2 dòng ngữ cảnh quanh chỗ đổi.
    return out.filter((d, k) => d.op !== '=' || out.slice(Math.max(0, k - 2), k + 3).some((n) => n.op !== '='));
  }

  /** Danh sách chỗ AI đã tự tra trong mã nguồn (r.read) — để biết đề xuất dựa trên đâu. */
  const readNote = (r) => r.read?.length
    ? `<details class="ai-read"><summary>AI đã tra ${r.read.length} chỗ trong mã nguồn</summary><ul>${r.read.map((x) => `<li><code>${esc(x)}</code></li>`).join('')}</ul></details>`
    : '';

  async function themeFix(error = '') {
    const te = window.LQ?.themeEditor;
    const cur = te?.current();
    if (!cur) { toast('error', 'Chưa mở tệp nào.'); return; }
    busy(`${ai.provider} đang đọc ${cur.file}…`, 'AI tự tra thêm hàm, field liên quan trong lõi/theme/plugin nếu cần. Có thể mất một đến hai phút.');
    const r = await post('/admin/ai/theme-fix', { theme: te.theme, file: cur.file, content: cur.content, error });
    done();
    if (!r.ok) { toast('error', r.message); return; }
    const changed = r.fixed_content !== cur.content;
    if (!r.has_problem || !changed) {
      fire({ title: 'Không thấy lỗi cần sửa', html: `<p>${esc(r.summary || 'AI không phát hiện lỗi trong tệp này.')}</p>${readNote(r)}`, confirmButtonText: 'Đóng', customClass: { popup: 'lq-swal' } });
      return;
    }
    const diff = diffLines(cur.content, r.fixed_content);
    const pick = await fire({
      title: 'AI đề xuất sửa',
      html: `<p class="ai-summary">${esc(r.summary)}</p>`
        + (r.changes.length ? `<ul class="ai-changes">${r.changes.map((c) => `<li><b>Dòng ${+c.line}</b> — ${esc(c.explanation)}</li>`).join('')}</ul>` : '')
        + `<div class="ai-diff">${diff.map((d) => `<div class="d-${d.op === '=' ? 'eq' : d.op === '+' ? 'add' : 'del'}"><span>${d.op === '=' ? ' ' : d.op}</span><code>${esc(d.text) || ' '}</code></div>`).join('')}</div>`
        + readNote(r)
        + '<p class="muted small">Áp dụng chỉ thay nội dung trong trình soạn thảo (hoàn tác được bằng ⌘Z). Kiểm tra lại rồi bấm Lưu.</p>',
      showCancelButton: true, confirmButtonText: 'Áp dụng vào trình soạn thảo', cancelButtonText: 'Bỏ qua',
      customClass: { popup: 'lq-swal lq-swal-wide' },
    });
    if (!pick.isConfirmed) return;
    // Chờ AI cả phút, người dùng có thể đã chuyển sang tab tệp khác: mở lại đúng tệp đã gửi đi, kẻo bản sửa đè vào tệp khác.
    if (te.current()?.file !== cur.file) await te.open(cur.file);
    if (te.current()?.file !== cur.file) { toast('error', `Không mở lại được ${cur.file} — chưa áp dụng gì.`); return; }
    te.replace(r.fixed_content);
    if (r.changes[0]?.line) te.gotoLine(+r.changes[0].line);
    toast('success', 'Đã áp dụng. Kiểm tra rồi bấm Lưu.');
    return true;
  }

  /** Đoạn văn AI trả về → HTML an toàn: escape rồi mới đổi `mã` và dòng "- " thành danh sách. */
  const prose = (t) => esc(t).replace(/`([^`\n]+)`/g, '<code>$1</code>').split(/\n{2,}/).map((block) => {
    const lines = block.split('\n');
    return lines.every((l) => /^\s*[-•]\s/.test(l))
      ? `<ul>${lines.map((l) => `<li>${l.replace(/^\s*[-•]\s/, '')}</li>`).join('')}</ul>`
      : `<p>${lines.join('<br>')}</p>`;
  }).join('');

  $('[data-ai-theme-explain]')?.addEventListener('click', async () => {
    const te = window.LQ?.themeEditor;
    const cur = te?.current();
    if (!cur) { toast('error', 'Chưa mở tệp nào.'); return; }
    const q = await fire({
      title: `Giải thích ${esc(cur.file)}`, input: 'textarea', inputPlaceholder: 'Câu hỏi cụ thể (không bắt buộc), vd. "Vòng lặp bài viết nằm ở đâu?"',
      showCancelButton: true, confirmButtonText: 'Hỏi AI', cancelButtonText: 'Huỷ', customClass: { popup: 'lq-swal' },
    });
    if (!q.isConfirmed) return;
    busy(`${ai.provider} đang đọc ${cur.file}…`);
    const r = await post('/admin/ai/theme-explain', { theme: te.theme, file: cur.file, content: cur.content, question: q.value || '' });
    done();
    if (!r.ok) { toast('error', r.message); return; }
    fire({ title: esc(cur.file), html: `<div class="ai-explain">${prose(r.explanation)}</div>${readNote(r)}`, confirmButtonText: 'Đóng', customClass: { popup: 'lq-swal lq-swal-wide' } });
  });

  $('[data-ai-theme-generate]')?.addEventListener('click', async () => {
    const te = window.LQ?.themeEditor;
    if (!te) return;
    const f = await fire({
      title: 'Tạo tệp mới bằng AI',
      html: '<div class="ai-gen"><label>Tên tệp<input class="input mono" id="ai-gen-file" value="templates/" placeholder="templates/lien-he.php"></label>'
        + '<label>Tệp cần làm gì?<textarea class="input" id="ai-gen-desc" rows="5" placeholder="Vd. Mẫu trang Liên hệ: tiêu đề, nội dung trang, bản đồ Google nhúng từ field map_url, khối địa chỉ + số điện thoại lấy từ field."></textarea></label></div>',
      showCancelButton: true, confirmButtonText: 'Viết tệp', cancelButtonText: 'Huỷ', customClass: { popup: 'lq-swal lq-swal-wide' },
      focusConfirm: false,
      preConfirm: () => {
        const file = $('#ai-gen-file').value.trim(), description = $('#ai-gen-desc').value.trim();
        if (!/\.[a-z]+$/i.test(file)) return Swal.showValidationMessage('Nhập tên tệp có đuôi, vd. templates/lien-he.php');
        if (description.length < 10) return Swal.showValidationMessage('Mô tả cụ thể hơn một chút.');
        return { file, description };
      },
    });
    if (!f.isConfirmed) return;
    busy(`${ai.provider} đang viết ${f.value.file}…`, 'Có thể mất tới một phút.');
    const r = await post('/admin/ai/theme-generate', { theme: te.theme, ...f.value });
    done();
    if (!r.ok) { toast('error', r.message); return; }
    const ok = await fire({
      title: esc(f.value.file),
      html: `<div class="ai-explain">${prose(r.notes)}</div>`
        + (r.lint ? `<p class="ai-err small">Bản này có lỗi cú pháp nên không tạo được: ${esc(r.lint)}. Thử lại hoặc mô tả rõ hơn.</p>` : '')
        + `<pre class="ai-log ai-code">${esc(r.content)}</pre>`
        + readNote(r),
      showCancelButton: true, confirmButtonText: 'Tạo tệp và mở', cancelButtonText: 'Bỏ', showConfirmButton: !r.lint,
      customClass: { popup: 'lq-swal lq-swal-wide' },
    });
    if (!ok.isConfirmed) return;
    const fd = new FormData();
    fd.append('theme', te.theme);
    fd.append('file', f.value.file);
    fd.append('content', r.content);
    const c = await post('/admin/themes/editor/create', fd, true);
    if (!c.ok) { toast('error', c.message); return; }
    location.href = c.url;
  });

  const issueLabel = (x) => ({ security: 'Bảo mật', malware: 'Mã độc' }[x.category] || { error: 'Lỗi', warning: 'Cảnh báo' }[x.severity]);
  const issueList = (issues) => `<ul>${issues.map((x) => `<li class="is-${x.severity}"><span>${issueLabel(x)}</span>${x.line ? `<b>dòng ${x.line}</b> ` : ''}${esc(x.message)}</li>`).join('')}</ul>`;

  /**
   * Hộp thoại tiến độ rà từng tệp (theme/plugin). scanOne(file) → kết quả của endpoint. Trả {results, stopped} hoặc null nếu bấm dừng rồi đóng.
   * note: lời lưu ý hiện ở cuối hộp khi quét xong (vd. "AI chỉ là tham khảo") — để không phải mở thêm một hộp thoại chỉ để nói câu đó.
   */
  async function runScan(title, files, scanOne, note = '') {
    let running = true, stopped = false;
    const results = [];
    const tally = { err: 0, warn: 0, ok: 0, fail: 0 };
    const clean = [];
    const split = (f) => { const i = f.lastIndexOf('/'); return [i >= 0 ? f.slice(0, i + 1) : '', f.slice(i + 1)]; };
    const fileHtml = (f) => { const [dir, name] = split(f); return `<code>${dir ? `<em>${esc(dir)}</em>` : ''}${esc(name)}</code>`; };
    const done = await fire({
      title,
      html: '<div class="scan-stage">'
        + '<div class="scan-ring"><i data-lucide="file-code"></i></div>'
        + '<div class="scan-now"></div>'
        + `<p class="scan-sub">Chuẩn bị · ${files.length} tệp</p>`
        + '<div class="scan-bar"><span></span></div>'
        + '<p class="scan-tally"><b class="t-err" hidden><i></i><span></span> lỗi</b><b class="t-warn" hidden><i></i><span></span> cảnh báo</b>'
        + '<b class="t-fail" hidden><i></i><span></span> không kiểm được</b><b class="t-ok"><i></i><span>0</span> sạch</b></p>'
        + '</div><div class="scan-issues"></div><p class="scan-clean" hidden></p><p class="scan-note" hidden></p>',
      confirmButtonText: 'Dừng', showCancelButton: false, allowOutsideClick: false, allowEscapeKey: false,
      customClass: { popup: 'lq-swal lq-swal-scan', confirmButton: 'btn btn-secondary' },
      didOpen: async (pop) => {
        icons();
        const btn = Swal.getConfirmButton();
        const now = pop.querySelector('.scan-now');
        const issues = pop.querySelector('.scan-issues');
        const cleanEl = pop.querySelector('.scan-clean');
        const sub = pop.querySelector('.scan-sub');
        // Tệp đang quét ở giữa: tệp cũ trượt lên biến mất, tệp mới trượt lên thế chỗ.
        const showNow = (f, isText = false) => {
          now.querySelectorAll('.scan-now-item').forEach((el) => { el.classList.add('is-out'); setTimeout(() => el.remove(), 320); });
          const [dir, name] = isText ? ['', f] : split(f);
          const el = document.createElement('div');
          el.className = `scan-now-item${isText ? ' is-text' : ''}`;
          el.innerHTML = `${dir ? `<small>${esc(dir)}</small>` : ''}<strong>${esc(name)}</strong>`;
          now.append(el);
        };
        const setTally = () => {
          for (const k of Object.keys(tally)) {
            const el = pop.querySelector(`.t-${k}`);
            el.hidden = k !== 'ok' && !tally[k];
            el.querySelector('span').textContent = tally[k];
          }
        };
        for (let i = 0; i < files.length && !stopped; i++) {
          const f = files[i];
          showNow(f);
          sub.textContent = `Đang đọc · tệp ${i + 1} / ${files.length}`;
          const r = await scanOne(f);
          const errs = r.ok ? r.issues.filter((x) => x.severity === 'error').length : 0;
          const warns = r.ok ? r.issues.length - errs : 0;
          results.push({ file: f, ...r });
          const kind = !r.ok ? 'fail' : errs ? 'err' : warns ? 'warn' : 'ok';
          tally[kind]++;
          if (kind === 'ok') {
            // Tệp sạch: gom vào một dòng mờ, chỉ giữ vài tên gần nhất.
            clean.push(split(f)[1]);
            cleanEl.hidden = false;
            cleanEl.innerHTML = `<i data-lucide="check"></i><b>${clean.length} tệp sạch</b><span>${clean.slice(-4).reverse().map(esc).join(' · ')}${clean.length > 4 ? ' …' : ''}</span>`;
            icons();
          } else {
            // Tệp có vấn đề: thêm một dòng, trượt lên từ dưới.
            const row = document.createElement('div');
            row.className = `scan-row is-new is-${kind}`;
            if (r.read?.length) row.title = 'AI đã tra: ' + r.read.join(', ');
            row.innerHTML = `<span class="scan-state is-${kind}"></span>${fileHtml(f)}<span class="scan-sum">${esc(!r.ok ? r.message : [errs && `${errs} lỗi`, warns && `${warns} cảnh báo`].filter(Boolean).join(', '))}</span>`;
            issues.append(row);
            issues.scrollTop = issues.scrollHeight;
          }
          setTally();
          pop.querySelector('.scan-bar span').style.width = `${((i + 1) / files.length) * 100}%`;
          // Lỗi xác thực/hạn mức thì dừng luôn, không phí các lượt sau.
          if (!r.ok && /key|hạn mức|tần suất/i.test(r.message || '')) { stopped = true; break; }
        }
        running = false;
        const bad = tally.err + tally.warn + tally.fail;
        pop.querySelector('.scan-ring').classList.add(bad ? 'is-warn' : 'is-done');
        pop.querySelector('.scan-ring').innerHTML = `<i data-lucide="${bad ? 'list-checks' : 'check'}"></i>`;
        icons();
        showNow(stopped ? `Đã dừng sau ${results.length} tệp` : `Đã quét ${results.length} tệp`, true);
        sub.textContent = bad ? `${bad} tệp cần xem lại` : 'Không thấy vấn đề nào';
        // Không dùng Swal.update(): nó vẽ lại cả hộp thoại, xoá sạch tiến độ vừa cập nhật.
        Swal.getTitle().textContent = stopped ? 'Đã dừng' : 'Đã quét xong';
        if (note) {
          const noteEl = pop.querySelector('.scan-note');
          noteEl.innerHTML = `<i data-lucide="info"></i><span>${esc(note)}</span>`;
          noteEl.hidden = false;
          icons();
        }
        btn.textContent = bad ? 'Xem chi tiết' : 'Đóng';
        btn.classList.replace('btn-secondary', 'btn-primary'); // không gán đè className: SweetAlert2 cần class swal2-confirm
      },
      preConfirm: () => {
        if (running) { stopped = true; Swal.getConfirmButton().textContent = 'Đang dừng…'; return false; }
        return true;
      },
    });
    return done.isConfirmed ? { results, stopped } : null;
  }

  /* Tìm bug toàn bộ theme: mỗi tệp một lượt gọi, AI chỉ liệt kê lỗi; tệp có lỗi thì "Mở và sửa" (dùng lại themeFix). */
  $('[data-ai-theme-scan]')?.addEventListener('click', async () => {
    const te = window.LQ?.themeEditor;
    if (!te) return;
    const CODE = /\.(php|css|js|json|html?)$/i;
    const files = te.files().filter((f) => CODE.test(f));
    if (!files.length) { toast('error', 'Theme không có tệp mã nào để rà.'); return; }
    const ok = await fire({
      title: 'Tìm bug toàn bộ theme?',
      text: `${ai.provider} sẽ đọc lần lượt ${files.length} tệp mã (PHP, CSS, JS, JSON, HTML), mỗi tệp một lượt gọi AI. Không sửa gì cho tới khi bạn bấm "Mở và sửa".`,
      showCancelButton: true, confirmButtonText: `Tìm bug ${files.length} tệp`, cancelButtonText: 'Huỷ',
    });
    if (!ok.isConfirmed) return;

    const run = await runScan('Đang tìm bug trong theme…', files, (f) => post('/admin/ai/theme-scan', { theme: te.theme, file: f }));
    if (!run) return;
    const { results, stopped } = run;

    saveScan({ results, total: files.length, stopped });
    showScanReport();
  });

  /* Kết quả tìm bug gần nhất của theme: lưu trong phiên (sessionStorage), mở lại bằng nút "Bug: N tệp" trên thanh công cụ,
     nên sửa xong một tệp không phải tìm lại cả theme. */
  const scanKey = () => `te-scan:${window.LQ?.themeEditor?.theme}`;
  const loadScan = () => { try { return JSON.parse(sessionStorage.getItem(scanKey()) || 'null'); } catch (_) { return null; } };
  const saveScan = (data) => { try { sessionStorage.setItem(scanKey(), JSON.stringify(data)); } catch (_) {} renderScanPill(); };
  const scanLeft = (scan) => (scan ? scan.results.filter((r) => r.ok && r.issues.length && !r.fixed).length : 0);

  function renderScanPill() {
    const scan = loadScan();
    let pill = $('[data-scan-pill]');
    if (!scan || !scan.results.some((r) => r.ok && r.issues.length)) { pill?.remove(); return; }
    if (!pill) {
      pill = document.createElement('button');
      pill.type = 'button';
      pill.className = 'btn btn-ghost btn-sm scan-pill';
      pill.dataset.scanPill = '';
      pill.dataset.tip = 'Xem lại kết quả tìm bug gần nhất';
      pill.addEventListener('click', showScanReport);
      $('.te-bar-tools [data-dropdown]')?.before(pill);
    }
    const left = scanLeft(scan);
    pill.classList.toggle('is-done', !left);
    pill.innerHTML = `<i data-lucide="${left ? 'bug' : 'check-check'}"></i> ${left ? `Bug: ${left} tệp` : 'Đã xử lý hết'}`;
    icons();
  }

  async function showScanReport() {
    const te = window.LQ?.themeEditor;
    const scan = loadScan();
    if (!te || !scan) return;
    const bad = scan.results.filter((r) => r.ok && r.issues.length);
    const failed = scan.results.filter((r) => !r.ok);
    const left = scanLeft(scan);
    const report = await fire({
      title: bad.length ? (left ? `${left} tệp cần xem` : 'Đã xử lý hết các tệp') : 'Không thấy lỗi',
      html: `<p class="ai-choose-sub">Đã kiểm tra ${scan.results.length}/${scan.total} tệp${failed.length ? ` · ${failed.length} tệp không kiểm tra được` : ''}. AI có thể bỏ sót hoặc báo nhầm — hãy xem lại trước khi sửa.</p>`
        + (bad.length ? `<div class="scan-report">${bad.map((r) => `<div class="scan-file${r.fixed ? ' is-fixed' : ''}"><div class="scan-file-head"><code>${esc(r.file)}</code>`
          + (r.fixed ? '<span class="scan-fixed"><i data-lucide="check"></i> Đã áp dụng sửa</span>' : '')
          + `<button type="button" class="btn ${r.fixed ? 'btn-ghost' : 'btn-secondary'} btn-xs" data-scan-fix="${esc(r.file)}">${r.fixed ? 'Mở lại' : 'Mở và sửa'}</button></div>`
          + `${issueList(r.issues)}</div>`).join('')}</div>` : ''),
      showDenyButton: true, denyButtonText: 'Tìm lại', confirmButtonText: 'Đóng',
      customClass: { popup: 'lq-swal lq-swal-wide', denyButton: 'btn btn-ghost' },
      didOpen: (pop) => {
        icons();
        pop.addEventListener('click', (e) => {
          const b = e.target.closest('[data-scan-fix]');
          if (b) Swal.close({ isConfirmed: false, isDenied: false, isDismissed: true, value: b.dataset.scanFix });
        });
      },
    });
    if (report.isDenied) { $('[data-ai-theme-scan]')?.click(); return; }
    const target = report.value && bad.find((r) => r.file === report.value);
    if (!target) return;
    // "Mở và sửa": mở tệp, nhờ AI sửa với danh sách lỗi vừa tìm được làm ngữ cảnh; áp dụng thì đánh dấu đã sửa.
    await te.open(target.file, target.issues[0]?.line || 0);
    if (target.fixed) return;
    const applied = await themeFix(target.issues.map((x) => `${x.line ? `Dòng ${x.line}: ` : ''}${x.message}`).join('\n'));
    if (applied) {
      target.fixed = true;
      saveScan(scan);
      if (scanLeft(scan)) toast('success', `Còn ${scanLeft(scan)} tệp — bấm “Bug: ${scanLeft(scan)} tệp” trên thanh công cụ để sửa tiếp.`);
    }
  }

  if (window.LQ?.themeEditor) renderScanPill();

  /* Kiểm tra sâu plugin bằng AI: nút trên trang Plugin (plugin đã cài, data-slug) và trang kiểm tra gói trước khi cài (data-token).
     Mỗi tệp một lượt gọi, tìm lỗ hổng bảo mật, mã độc, lỗi logic; chỉ đọc, không sửa gì. */
  $$('[data-ai-plugin-scan]').forEach((btn) => btn.addEventListener('click', async () => {
    const src = btn.dataset.token ? { token: btn.dataset.token } : { slug: btn.dataset.slug };
    // Tên lấy từ header "Plugin Name" của gói chưa tin cậy; title của SweetAlert là HTML nên phải escape.
    const name = esc(btn.dataset.name || src.slug);
    btn.disabled = true;
    const list = await post('/admin/ai/plugin-scan', src);
    btn.disabled = false;
    if (!list.ok) { toast('error', list.message); return; }
    if (!list.files.length) { toast('error', 'Plugin không có tệp mã nào để kiểm tra.'); return; }
    const n = list.files.length;
    const ok = await fire({
      title: `Kiểm tra sâu “${name}”?`,
      text: `${ai.provider} sẽ đọc lần lượt ${n} tệp mã (mỗi tệp một lượt gọi AI) để tìm lỗ hổng bảo mật, mã độc và lỗi logic.`
        + (list.skipped ? ` Bỏ qua ${list.skipped} thư mục thư viện (vendor…) hoặc tệp vượt giới hạn.` : '') + ' Không thay đổi gì trong plugin.',
      showCancelButton: true, confirmButtonText: `Kiểm tra ${n} tệp`, cancelButtonText: 'Huỷ',
    });
    if (!ok.isConfirmed) return;
    const run = await runScan(`Đang kiểm tra ${name}…`, list.files, (f) => post('/admin/ai/plugin-scan', { ...src, file: f }),
      'AI có thể bỏ sót hoặc báo nhầm, và mã độc có thể cố tình viết để đánh lừa AI — kết quả chỉ để tham khảo, không phải bảo đảm.');
    if (!run) return;
    const bad = run.results.filter((r) => r.ok && r.issues.length);
    const failed = run.results.filter((r) => !r.ok);
    if (!bad.length && !failed.length) return; // sạch: hộp "Đã quét xong" đã nói đủ, không mở thêm hộp nào
    const all = bad.flatMap((r) => r.issues);
    const count = (c) => all.filter((x) => x.category === c).length;
    const parts = [count('malware') && `${count('malware')} dấu hiệu mã độc`, count('security') && `${count('security')} lỗ hổng bảo mật`, count('bug') && `${count('bug')} lỗi khác`].filter(Boolean);
    fire({
      title: parts.length ? parts.join(', ') : 'Không thấy vấn đề',
      html: `<p class="ai-choose-sub">Đã kiểm tra ${run.results.length}/${n} tệp${run.stopped ? ' (đã dừng giữa chừng)' : ''}${failed.length ? ` · ${failed.length} tệp không kiểm tra được` : ''}.</p>`
        + (bad.length ? `<div class="scan-report">${bad.map((r) => `<div class="scan-file"><div class="scan-file-head"><code>${esc(r.file)}</code></div>${issueList(r.issues)}</div>`).join('')}</div>` : ''),
      confirmButtonText: 'Đóng', customClass: { popup: 'lq-swal lq-swal-wide' },
    });
  }));

  const fixBtn = $('[data-ai-theme-fix]');
  if (fixBtn) {
    fixBtn.addEventListener('click', () => themeFix());
    // Mở từ trang báo lỗi theme (…&error=…): đề nghị nhờ AI sửa ngay lỗi đó.
    const err = $('[data-theme-editor]')?.dataset.error;
    if (err) {
      setTimeout(() => fire({
        title: 'Nhờ AI sửa lỗi này?', html: `<pre class="ai-log">${esc(err)}</pre>`, showCancelButton: true,
        confirmButtonText: `Hỏi ${ai.provider}`, cancelButtonText: 'Để tôi tự sửa', customClass: { popup: 'lq-swal' },
      }).then((r) => r.isConfirmed && themeFix(err)), 800);
    }
  }
  icons();
})();
