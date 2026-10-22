/* Khung chat trợ lý AI (LQ\Admin\AIChatController): bong bóng góc dưới phải mọi trang admin.
   Lịch sử lưu ở localStorage theo người dùng nên chuyển trang vẫn còn; mỗi lượt gửi kèm lịch sử lên máy chủ. */
(() => {
  const ai = window.LQ_AI || {};
  const $ = (s, r = document) => r.querySelector(s);
  const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const icons = () => window.lucide && lucide.createIcons();
  const csrf = () => $('meta[name="csrf-token"]')?.content || '';
  const KEY = `lq-ai-chat:${ai.user || 0}`;
  const MAX_KEEP = 60;

  /** Lịch sử đã lưu; mục sai dạng (sửa tay, bản cũ, tiện ích khác ghi đè) bị bỏ thay vì làm khung chat lỗi hẳn ở mọi trang. */
  const load = () => {
    let s;
    try { s = JSON.parse(localStorage.getItem(KEY) || '{}'); } catch (_) { return {}; }
    if (!s || typeof s !== 'object' || Array.isArray(s)) return {};
    const list = (v, ok) => (Array.isArray(v) ? v.filter(ok) : []);
    return {
      open: s.open === true,
      model: s.model && typeof s.model.provider === 'string' && typeof s.model.model === 'string' ? s.model : null,
      messages: list(s.messages, (m) => m && typeof m === 'object' && typeof m.text === 'string').map((m) => ({
        ...m,
        read: list(m.read, (x) => typeof x === 'string'),
        proposals: list(m.proposals, (p) => p && typeof p === 'object' && typeof p.id === 'string'),
      })),
    };
  };
  /** Bộ nhớ đầy (thẻ đề xuất mang cả nội dung tệp cũ/mới): bỏ dần tin cũ tới khi lưu được, thay vì im lặng không lưu gì nữa. */
  const save = (state) => {
    for (let keep = MAX_KEEP; keep >= 0; keep = keep ? Math.floor(keep / 2) : -1) {
      try { localStorage.setItem(KEY, JSON.stringify({ ...state, messages: keep ? state.messages.slice(-keep) : [] })); return; } catch (_) {}
    }
  };

  /** Markdown tối giản → HTML an toàn: escape trước, rồi mới đổi ```khối```, `mã`, **đậm**, link, danh sách, đoạn. */
  function md(text) {
    const blocks = [];
    // \u0000 là dấu giữ chỗ khối mã bên dưới: bỏ khỏi đầu vào, không thì in ra "undefined".
    let s = esc(String(text ?? '').replace(/\u0000/g, '')).replace(/```[a-z]*\n?([\s\S]*?)```/g, (_, code) => `\u0000${blocks.push(code.replace(/\n$/, '')) - 1}\u0000`);
    s = s.replace(/`([^`\n]+)`/g, '<code>$1</code>')
      .replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>')
      .replace(/(^|[^*\w])\*([^*\s][^*\n]*?)\*(?!\*)/gm, '$1<em>$2</em>')
      // Link trong admin (/admin/…) mở ngay tab này — lịch sử chat vẫn còn; link ra ngoài mở tab mới.
      // "/\evil.com" trình duyệt hiểu như "//evil.com" (ra ngoài site) nên cũng chặn sau "/" như "//".
      .replace(/\[([^\]\n]+)\]\(((?:https?:\/\/|\/(?![\/\\]))[^\s)]+)\)/g, (_, label, url) => url.startsWith('/')
        ? `<a class="ai-link" href="${url}">${label}</a>`
        : `<a href="${url}" target="_blank" rel="noopener">${label}</a>`)
      // AI lỡ ghi đường dẫn admin trong `mã` thì vẫn biến thành link.
      .replace(/<code>(\/admin(?:\/[\w\-./?=&;%]*)?)<\/code>/g, (m, url) => (url.includes('{') ? m : `<a class="ai-link" href="${url}"><code>${url}</code></a>`));
    const html = s.split(/\n{2,}/).map((para) => {
      const lines = para.split('\n');
      if (lines.every((l) => /^\s*(?:[-•*]|\d+[.)])\s/.test(l))) {
        const ordered = /^\s*\d/.test(lines[0]);
        // Dòng thụt vào (≥ 2 dấu cách) là mục con: giữ số thứ tự gốc và thụt lề thay vì dàn phẳng.
        return `<${ordered ? 'ol' : 'ul'}>${lines.map((l) => {
          const sub = /^\s{2,}/.test(l);
          const num = sub && l.match(/^\s*(\d+)[.)]\s/);
          return `<li${sub ? ' class="is-sub"' : ''}${num ? ` data-n="${num[1]}."` : ''}>${l.replace(/^\s*(?:[-•*]|\d+[.)])\s/, '')}</li>`;
        }).join('')}</${ordered ? 'ol' : 'ul'}>`;
      }
      return `<p>${lines.join('<br>')}</p>`;
    }).join('');
    return html.replace(/<p>\u0000(\d+)\u0000<\/p>|\u0000(\d+)\u0000/g, (_, a, b) => `<pre><code>${blocks[a ?? b]}</code></pre>`);
  }

  const strip = (html) => String(html || '').replace(/<\/(p|h\d|li|div)>/gi, '\n').replace(/<br\s*\/?>/gi, '\n').replace(/<[^>]+>/g, '').replace(/\n{3,}/g, '\n\n').trim();

  /** Bản so sánh dòng (dùng LQ.diffLines của ai.js) → HTML; tối đa 300 dòng hiển thị. */
  function diffHtml(a, b) {
    const d = window.LQ?.diffLines ? LQ.diffLines(a || '', b || '') : [];
    const add = d.filter((x) => x.op === '+').length, del = d.filter((x) => x.op === '-').length;
    const rows = d.slice(0, 300).map((x) => `<div class="d-${x.op === '=' ? 'eq' : x.op === '+' ? 'add' : 'del'}"><span>${x.op === '=' ? ' ' : x.op}</span><code>${esc(x.text) || ' '}</code></div>`).join('');
    return { add, del, html: `<div class="ai-diff">${rows}${d.length > 300 ? '<div class="d-eq"><span></span><code>… còn nữa</code></div>' : ''}</div>` };
  }

  const STATUS_LABEL = { draft: 'Bản nháp', publish: 'Đăng ngay', pending: 'Chờ duyệt' };

  /** Thẻ duyệt một đề xuất của AI: xem thay đổi, Áp dụng / Bỏ qua, sau khi áp dụng thì Hoàn tác. */
  function proposalCard(p) {
    let icon, title, sub, body = '';
    const action = p.type === 'action';
    if (action) {
      // Việc của plugin (lq_add_ai_tool, có thay đổi): tham số AI định truyền, kết quả sau khi chạy; không hoàn tác được.
      icon = 'zap';
      title = p.label;
      sub = `${p.source} · ${p.status === 'applied' ? 'đã chạy' : 'chạy khi bạn bấm Chạy'}`;
      const args = Object.entries(p.args || {}).map(([k, v]) => `<li><b>${esc(k)}</b><span>${esc(typeof v === 'object' ? JSON.stringify(v) : String(v))}</span></li>`).join('');
      body = (args ? `<ul class="ai-prop-changes">${args}</ul>` : '')
        + (p.result != null ? `<p class="ai-prop-result">${esc(p.result)}</p>` : '')
        + (p.status === 'pending' ? '<p class="ai-prop-note">Việc này không hoàn tác được.</p>' : '');
    } else if (p.type === 'file') {
      icon = p.is_new ? 'file-plus' : 'file-pen';
      title = p.path.replace(/^public\/content\//, '');
      sub = p.is_new ? 'Tạo tệp mới' : 'Sửa tệp';
      if (p.new !== null) {
        const d = diffHtml(p.old, p.new);
        body = `<details class="ai-prop-diff"${p.status === 'pending' ? ' open' : ''}><summary>Xem thay đổi <b class="d-plus">+${d.add}</b> <b class="d-minus">−${d.del}</b></summary>${d.html}</details>`;
      } else {
        body = '<p class="ai-prop-note">Tệp quá lớn để hiển thị so sánh ở đây.</p>';
      }
    } else {
      icon = p.post_id ? 'file-pen' : 'file-plus';
      title = p.title;
      sub = `${p.post_id ? 'Sửa' : 'Tạo'} ${p.post_type === 'page' ? 'trang' : 'bài viết'} · ${STATUS_LABEL[p.post_status] || p.post_status}`;
      // Phân loại & field sẽ đổi: [nhãn, cũ, mới] (máy chủ đã bỏ dòng không đổi).
      const changes = (p.changes || []).map(([label, before, after]) => `<li><b>${esc(label)}</b>`
        + (before !== '' ? `<s>${esc(before)}</s><span aria-hidden="true">→</span>` : '')
        + `<span>${after !== '' ? esc(after) : '<em>(để trống)</em>'}</span></li>`).join('');
      const oldText = p.old ? `# ${p.old.title}\n\n${strip(p.old.content)}` : '';
      const newText = `# ${p.new.title}\n\n${strip(p.new.content)}`;
      const d = diffHtml(oldText, newText);
      body = (changes ? `<ul class="ai-prop-changes">${changes}</ul>` : '')
        // Chỉ đổi phân loại/field (tiêu đề + nội dung giữ nguyên): không cần khung so sánh rỗng "+0 −0".
        + (p.old && !d.add && !d.del ? '' : `<details class="ai-prop-diff"${p.status === 'pending' ? ' open' : ''}><summary>${p.old ? 'Xem thay đổi' : 'Xem nội dung'} <b class="d-plus">+${d.add}</b>${p.old ? ` <b class="d-minus">−${d.del}</b>` : ''}</summary>${d.html}</details>`);
    }
    const actions = {
      pending: `<button type="button" class="btn btn-accent btn-sm" data-prop-apply="${esc(p.id)}"><i data-lucide="${action ? 'play' : 'check'}"></i> ${action ? 'Chạy' : 'Áp dụng'}</button><button type="button" class="btn btn-ghost btn-sm" data-prop-reject="${esc(p.id)}">Bỏ qua</button>`,
      applied: action ? '<span class="ai-prop-state is-ok"><i data-lucide="check-circle-2"></i> Đã chạy</span>'
        : `<span class="ai-prop-state is-ok"><i data-lucide="check-circle-2"></i> Đã áp dụng</span>${p.open_url ? `<a class="btn btn-ghost btn-sm" href="${esc(p.open_url)}">Mở</a>` : ''}<button type="button" class="btn btn-ghost btn-sm" data-prop-undo="${esc(p.id)}"><i data-lucide="undo-2"></i> Hoàn tác</button>`,
      undone: '<span class="ai-prop-state"><i data-lucide="undo-2"></i> Đã hoàn tác</span>',
      rejected: '<span class="ai-prop-state">Đã bỏ qua</span>',
    }[p.status] || '';
    return `<div class="ai-prop is-${esc(p.status)}" data-prop="${esc(p.id)}"><div class="ai-prop-head"><span class="ai-prop-icon"><i data-lucide="${icon}"></i></span>`
      + `<div><strong>${esc(title)}</strong><small>${esc(sub)}</small></div></div>`
      + (p.summary ? `<p class="ai-prop-sum">${esc(p.summary)}</p>` : '') + body
      + `<div class="ai-prop-actions">${actions}</div>${p.error ? `<p class="ai-prop-err">${esc(p.error)}</p>` : ''}</div>`;
  }

  function mount() {
    if ($('[data-ai-chat]')) return;
    const state = { messages: [], open: false, ...load() };
    const root = document.createElement('div');
    root.className = 'ai-chat';
    root.dataset.aiChat = '';
    const mod = /Mac|iPhone|iPad/.test(navigator.platform) ? '⌘' : 'Ctrl ';
    root.innerHTML = `
      <button type="button" class="ai-chat-fab" data-chat-toggle aria-label="Mở trợ lý AI" title="Trợ lý AI (${mod}J)"><i data-lucide="bot"></i></button>
      <section class="ai-chat-panel" role="dialog" aria-label="Trợ lý" hidden>
        <header class="ai-chat-head">
          <strong>Trợ lý</strong>
          <span class="ai-chat-head-sub" data-chat-sub></span>
          <button type="button" class="ai-chat-clear" data-chat-clear hidden><i data-lucide="trash-2"></i> Xóa lịch sử chat</button>
          <button type="button" class="icon-btn sm" data-chat-toggle title="Đóng (Esc)"><i data-lucide="x"></i></button>
        </header>
        <div class="ai-chat-log" data-chat-log></div>
        <form class="ai-composer" data-chat-form>
          <textarea rows="1" placeholder="Hỏi về website, lỗi, nội dung, theme…" data-chat-input></textarea>
          <div class="ai-composer-bar">
            <div class="ai-model-wrap">
              <button type="button" class="ai-chat-model" data-model-toggle aria-haspopup="listbox" title="Đổi model"><i data-lucide="cpu"></i><span data-model-label></span><i data-lucide="chevron-down"></i></button>
              <div class="ai-model-pop" data-model-pop hidden>
                <div class="ai-model-search"><i data-lucide="search"></i><input type="search" placeholder="Lọc model…" data-model-filter></div>
                <div class="ai-model-list" data-model-list><div class="ai-model-empty">Đang tải danh sách model…</div></div>
              </div>
            </div>
            <button type="button" class="ai-composer-icon" data-model-refresh title="Tải lại danh sách model từ nhà cung cấp"><i data-lucide="refresh-cw"></i></button>
            <button type="submit" class="ai-chat-send" aria-label="Gửi (Enter)" disabled><i data-lucide="arrow-up"></i></button>
          </div>
        </form>
      </section>`;
    document.body.append(root);
    const panel = $('.ai-chat-panel', root);
    const log = $('[data-chat-log]', root);
    const input = $('[data-chat-input]', root);
    const sendBtn = $('.ai-chat-send', root);
    let busy = false;

    /* ---------- Chọn model (riêng cho khung chat, không đổi cài đặt của site) ---------- */
    const pop = $('[data-model-pop]', root);
    const list = $('[data-model-list]', root);
    const filter = $('[data-model-filter]', root);
    let providers = null;
    const modelLabel = () => {
      const m = state.model;
      $('[data-model-label]', root).textContent = m ? m.model : (ai.model || 'Mặc định');
      $('[data-model-toggle]', root).title = m ? `${m.label} · ${m.model} — đổi model` : 'Model mặc định của site — đổi model';
    };
    function renderModels() {
      if (!providers) return;
      const q = filter.value.trim().toLowerCase();
      const cur = state.model;
      const row = (p, m, isDefault) => {
        const on = isDefault ? !cur : cur && cur.provider === p.id && cur.model === m.id;
        return `<button type="button" class="ai-model-opt${on ? ' is-on' : ''}" role="option" aria-selected="${on}" data-model-pick="${isDefault ? '' : esc(p.id)}" data-model-id="${isDefault ? '' : esc(m.id)}" data-model-name="${esc(p?.label || '')}">`
          + `<span>${esc(isDefault ? 'Mặc định của site' : m.name || m.id)}</span><small>${esc(isDefault ? (ai.provider && ai.model ? `${ai.provider} · ${ai.model}` : 'theo Cài đặt › AI') : m.id)}</small>${on ? '<i data-lucide="check"></i>' : ''}</button>`;
      };
      let html = !q || 'mặc định'.includes(q) ? row(null, null, true) : '';
      for (const p of providers) {
        const ms = p.models.filter((m) => !q || `${m.id} ${m.name} ${p.label}`.toLowerCase().includes(q));
        if (!ms.length && !p.error) continue;
        html += `<div class="ai-model-group">${esc(p.label)}${p.active ? ' <em>đang dùng cho site</em>' : ''}</div>`
          + (p.error ? `<div class="ai-model-empty is-error">${esc(p.error)}</div>` : '') + ms.map((m) => row(p, m, false)).join('');
      }
      list.innerHTML = html || '<div class="ai-model-empty">Không có model nào khớp.</div>';
      icons();
    }
    async function loadModels(refresh = false) {
      list.innerHTML = '<div class="ai-model-empty">Đang tải danh sách model…</div>';
      try {
        const res = await fetch('/admin/ai/chat/models', { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': csrf() }, body: JSON.stringify({ refresh }) });
        const r = await res.json();
        providers = r.ok ? r.providers : [];
        if (!r.ok) list.innerHTML = `<div class="ai-model-empty is-error">${esc(r.message || 'Không tải được danh sách.')}</div>`;
      } catch (_) {
        providers = null;
        list.innerHTML = '<div class="ai-model-empty is-error">Mất kết nối tới máy chủ.</div>';
        return;
      }
      renderModels();
    }
    const setPop = (open) => {
      pop.hidden = !open;
      $('[data-model-toggle]', root).setAttribute('aria-expanded', String(open));
      if (open) { if (!providers) loadModels(); filter.value = ''; renderModels(); setTimeout(() => filter.focus(), 30); }
    };
    filter.addEventListener('input', renderModels);
    modelLabel();

    // Không phải quản trị viên (LQ_AI.code === false): máy chủ không cấp công cụ đọc mã/sửa tệp/nhật ký lỗi — đừng gợi ý việc đó.
    const codeAccess = ai.code !== false;
    const SUGGEST = [
      codeAccess && ['triangle-alert', 'Website có đang bị lỗi gì không? Nếu có thì sửa giúp tôi'],
      ['file-plus', 'Viết bản nháp bài giới thiệu website'],
      ['image-off', 'Bài viết nào chưa có ảnh đại diện?'],
      codeAccess && ['arrow-up-to-line', 'Thêm nút “Lên đầu trang” vào theme'],
    ].filter(Boolean);

    /** Nhật ký công cụ của AI → chip ngắn gọn có icon ("Nhật ký lỗi", "card.php", "Bài #4"…). */
    function step(x) {
      const arg = (() => { try { return JSON.parse(x.slice(x.indexOf(' ') + 1)); } catch (_) { return {}; } })();
      const base = (p) => p.split('/').pop();
      if (x.startsWith('site_info')) return ['layout-grid', 'Tổng quan site'];
      if (x.startsWith('read_error_log')) return ['triangle-alert', 'Nhật ký lỗi'];
      if (x.startsWith('search_content')) return ['search', arg.query ? `Tìm bài “${arg.query}”` : 'Bài mới nhất'];
      if (x.startsWith('get_content')) return ['file-text', `Bài #${arg.id ?? '?'}`];
      if (x.startsWith('list_terms')) return ['tags', `Phân loại ${arg.taxonomy || ''}`.trim()];
      if (x.startsWith('propose_')) return ['file-pen', 'Soạn đề xuất'];
      let m = x.match(/^tìm "(.+?)"/);
      if (m) return ['code', `Tìm mã “${m[1]}”`];
      if ((m = x.match(/^xem thư mục (.+)$/))) return ['folder', base(m[1])];
      m = x.match(/^(.+?)(?::(\d+)-(\d+))?$/);
      return ['file-code', base(m[1]) + (m[2] ? `:${m[2]}` : '')];
    }
    const steps = (read) => {
      const chips = read.map((x) => { const [ic, label] = step(x); return `<span class="ai-step" title="${esc(x)}"><i data-lucide="${ic}"></i>${esc(label)}</span>`; });
      const more = chips.length > 5 ? `<button type="button" class="ai-step is-more" data-steps-more>+${chips.length - 5}</button>` : '';
      return `<div class="ai-steps">${chips.slice(0, 5).join('')}${more}<span class="ai-steps-rest">${chips.slice(5).join('')}</span></div>`;
    };

    function render() {
      sub();
      if (!state.messages.length) {
        log.innerHTML = '<div class="ai-chat-empty"><h4>Hỏi gì về website?</h4>'
          + `<p>Trợ lý tự tra ${codeAccess ? 'mã nguồn, nội dung và nhật ký lỗi' : 'bài viết, trang và phân loại'}. Cần sửa hay thêm gì, nó gửi đề xuất — bạn bấm Áp dụng thì mới thay đổi.</p>`
          + `<div class="ai-suggest">${SUGGEST.map(([ic, q]) => `<button type="button" data-chat-suggest="${esc(q)}"><i data-lucide="${ic}"></i><span>${esc(q)}</span><i data-lucide="arrow-right"></i></button>`).join('')}</div></div>`;
      } else {
        log.innerHTML = state.messages.filter((m) => !m.hidden).map((m) => m.role === 'user'
          ? `<div class="ai-msg is-user"><div class="ai-bubble">${esc(m.text).replace(/\n/g, '<br>')}</div></div>`
          : `<div class="ai-msg is-ai${m.error ? ' is-error' : ''}">${m.read?.length ? steps(m.read) : ''}`
            + `<div class="ai-answer">${m.error ? `<i data-lucide="circle-alert"></i>${esc(m.text)}` : md(m.text)}</div>`
            // Thẻ hỏng (thiếu trường) thì bỏ qua, không làm hỏng cả khung chat.
            + (m.proposals?.length ? `<div class="ai-props">${m.proposals.map((p) => { try { return proposalCard(p); } catch (_) { return ''; } }).join('')}</div>` : '') + '</div>').join('');
      }
      if (busy) log.insertAdjacentHTML('beforeend', '<div class="ai-msg is-ai"><div class="ai-thinking"><span class="ai-dots"><i></i><i></i><i></i></span><em data-chat-elapsed>Đang tra cứu</em></div></div>');
      icons();
      log.scrollTop = log.scrollHeight;
    }
    /** Dòng phụ ở đầu khung: số lượt hỏi trong hội thoại. */
    const sub = () => {
      const n = state.messages.filter((m) => m.role === 'user' && !m.hidden).length;
      $('[data-chat-sub]', root).textContent = n ? `${n} câu hỏi` : 'Hội thoại mới';
      $('[data-chat-clear]', root).hidden = !state.messages.length;
    };

    function setOpen(open) {
      state.open = open;
      panel.hidden = !open;
      root.classList.toggle('is-open', open);
      save(state);
      if (open) { render(); setTimeout(() => input.focus(), 50); }
    }

    async function send(text) {
      text = text.trim();
      if (!text || busy) return;
      state.messages.push({ role: 'user', text });
      save(state);
      input.value = '';
      autosize();
      busy = true;
      syncSend();
      render();
      const started = Date.now();
      const tick = setInterval(() => { const el = $('[data-chat-elapsed]', log); if (el) el.textContent = `Đang tra cứu · ${Math.round((Date.now() - started) / 1000)}s`; }, 1000);
      let r;
      try {
        const res = await fetch('/admin/ai/chat', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': csrf() },
          body: JSON.stringify({
            messages: state.messages.filter((m) => !m.error).map(({ role, text: t }) => ({ role, text: t })),
            page: { title: document.querySelector('title')?.dataset.page || document.title, path: location.pathname + location.search },
            model: state.model ? { provider: state.model.provider, model: state.model.model } : null,
          }),
        });
        r = await res.json().catch(() => ({ ok: false, message: `Máy chủ trả lỗi ${res.status}.` }));
      } catch (_) {
        r = { ok: false, message: 'Mất kết nối tới máy chủ.' };
      }
      clearInterval(tick);
      busy = false;
      syncSend();
      if (!r.ok && /Model đã chọn không còn/.test(r.message || '')) { state.model = null; modelLabel(); }
      state.messages.push(r.ok ? { role: 'assistant', text: r.reply || '(AI không trả lời)', read: r.read || [], proposals: r.proposals || [] } : { role: 'assistant', error: true, text: r.message || 'Có lỗi xảy ra.' });
      save(state);
      render();
    }

    /** Xóa toàn bộ lịch sử chat (trong trình duyệt) sau khi hỏi lại. Thay đổi đã áp dụng không bị ảnh hưởng. */
    async function clearHistory() {
      const ask = window.LQ?.confirm
        ? LQ.confirm({ title: 'Xóa lịch sử chat?', text: 'Toàn bộ tin nhắn trong khung chat sẽ bị xóa. Những thay đổi đã áp dụng vẫn giữ nguyên.', danger: true, confirmText: 'Xóa lịch sử' })
        : Promise.resolve(window.confirm('Xóa lịch sử chat?'));
      if (!(await ask)) return;
      state.messages = [];
      save(state);
      render();
      input.focus();
    }

    const findProp = (id) => state.messages.flatMap((m) => m.proposals || []).find((p) => p.id === id);
    /** Ghi chú ẩn cho AI biết người dùng đã làm gì với đề xuất (gửi kèm lượt sau, không hiện trong khung chat). */
    const note = (text) => state.messages.push({ role: 'user', hidden: true, text: `[Hệ thống] ${text}` });
    const label = (p) => (p.type === 'file' ? p.path : p.type === 'action' ? p.label : `"${p.title}"`);

    async function act(id, action) {
      let p = findProp(id);
      if (!p || busy) return;
      if (action === 'reject') {
        p.status = 'rejected';
        note(`Người dùng đã bỏ qua đề xuất #${id} (${label(p)}).`);
        save(state); render();
        return;
      }
      const btn = log.querySelector(`[data-prop="${CSS.escape(id)}"] [data-prop-${action}]`);
      if (btn) { btn.disabled = true; btn.classList.add('is-loading'); }
      let r;
      try {
        const res = await fetch(`/admin/ai/chat/${action}`, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': csrf() }, body: JSON.stringify({ id }) });
        r = await res.json().catch(() => ({ ok: false, message: `Máy chủ trả lỗi ${res.status}.` }));
      } catch (_) {
        r = { ok: false, message: 'Mất kết nối tới máy chủ.' };
      }
      // Lịch sử có thể vừa được nạp lại từ tab khác trong lúc chờ: cập nhật thẻ đang có trong state, không phải bản cũ.
      p = findProp(id) || p;
      if (r.ok) {
        Object.assign(p, r.proposal, { error: null });
        note(p.type === 'action' ? `Người dùng đã bấm Chạy, đề xuất #${id} (${label(p)}) đã chạy. Kết quả: ${String(p.result ?? '').slice(0, 2000)}`
          : action === 'apply' ? `Người dùng đã bấm Áp dụng, đề xuất #${id} (${label(p)}) đã được áp dụng.` : `Người dùng đã hoàn tác đề xuất #${id} (${label(p)}).`);
        window.LQ?.toast?.('success', p.type === 'action' ? 'Đã chạy.' : action === 'apply' ? 'Đã áp dụng thay đổi.' : 'Đã hoàn tác.');
      } else {
        p.error = r.message;
      }
      save(state); render();
    }

    const autosize = () => { input.style.height = 'auto'; input.style.height = `${Math.min(input.scrollHeight, 160)}px`; };
    const syncSend = () => { sendBtn.disabled = busy || !input.value.trim(); };
    input.addEventListener('input', () => { autosize(); syncSend(); });
    input.addEventListener('keydown', (e) => {
      // keyCode 229: Safari báo isComposing = false cho cú Enter chốt chữ của bộ gõ.
      if (e.key === 'Enter' && !e.shiftKey && !e.isComposing && e.keyCode !== 229) { e.preventDefault(); send(input.value); }
    });
    $('[data-chat-form]', root).addEventListener('submit', (e) => { e.preventDefault(); send(input.value); });
    root.addEventListener('click', (e) => {
      if (e.target.closest('[data-chat-toggle]')) setOpen(!state.open);
      const rf = e.target.closest('[data-model-refresh]');
      if (rf && !rf.classList.contains('is-spinning')) {
        rf.classList.add('is-spinning');
        loadModels(true).then(() => {
          rf.classList.remove('is-spinning');
          const n = (providers || []).reduce((t, p) => t + p.models.length, 0);
          window.LQ?.toast?.(providers ? 'success' : 'error', providers ? `Đã cập nhật danh sách: ${n} model.` : 'Không tải được danh sách model.');
        });
      }
      if (e.target.closest('[data-model-toggle]')) setPop(pop.hidden);
      else if (!pop.hidden && !e.target.closest('[data-model-pop]')) setPop(false);
      const pick = e.target.closest('[data-model-pick]');
      if (pick) {
        state.model = pick.dataset.modelPick ? { provider: pick.dataset.modelPick, model: pick.dataset.modelId, label: pick.dataset.modelName } : null;
        save(state); modelLabel(); setPop(false);
        input.focus();
      }
      const sug = e.target.closest('[data-chat-suggest]');
      if (sug) send(sug.dataset.chatSuggest);
      const more = e.target.closest('[data-steps-more]');
      if (more) { more.parentElement.classList.add('is-open'); more.remove(); }
      if (e.target.closest('[data-chat-clear]') && !busy) clearHistory();
      const b = e.target.closest('[data-prop-apply], [data-prop-reject], [data-prop-undo]');
      if (b) act(b.dataset.propApply || b.dataset.propReject || b.dataset.propUndo, b.dataset.propApply ? 'apply' : b.dataset.propReject ? 'reject' : 'undo');
    });
    document.addEventListener('keydown', (e) => {
      // ⌘J / Ctrl+J: mở/đóng trợ lý ở bất kỳ trang admin nào.
      if ((e.metaKey || e.ctrlKey) && !e.altKey && !e.shiftKey && e.key.toLowerCase() === 'j') { e.preventDefault(); setOpen(!state.open); return; }
      // Esc chỉ đóng lớp trên cùng: ⌘K / hộp thoại nằm trên khung chat đã xử lý lần nhấn này (preventDefault) thì bỏ qua.
      if (e.key !== 'Escape' || e.defaultPrevented || !state.open || document.querySelector('.swal2-container, .cmdk:not([hidden])')) return;
      if (!pop.hidden) setPop(false); else setOpen(false);
    });
    // Hai tab cùng mở trợ lý: tab kia vừa lưu thì nạp lại lịch sử, không thì lần lưu sau của tab này ghi đè mất
    // tin nhắn và trạng thái đề xuất (đã áp dụng/hoàn tác) bên đó. Đang chờ câu trả lời thì để yên.
    window.addEventListener('storage', (e) => {
      if (e.key !== KEY || busy) return;
      state.messages = load().messages || [];
      if (state.open) render(); else sub();
    });
    icons();
    if (state.open) setOpen(true);
    // Hỏi từ nơi khác (thanh ⌘K…): mở khung chat và gửi luôn; đang chờ câu trả lời trước thì chỉ điền sẵn vào ô nhập.
    window.LQ.aiChat.open = () => setOpen(true);
    window.LQ.aiChat.ask = (text) => {
      setOpen(true);
      if (busy) { input.value = text; autosize(); syncSend(); return; }
      send(text);
    };
  }

  window.LQ = Object.assign(window.LQ || {}, { aiChat: { mount, md } });
  if (ai.enabled && ai.chat) mount();
})();
