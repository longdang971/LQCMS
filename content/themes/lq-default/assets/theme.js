// LQ Default: đổi sáng/tối, menu di động, phím "/" để tìm, hiện dần khi cuộn, "Xem thêm bài" và gợi ý tìm kiếm (AJAX).
(() => {
  const root = document.documentElement;

  const toggle = document.querySelector('[data-theme-toggle]');
  toggle?.addEventListener('click', () => {
    const dark = root.dataset.theme
      ? root.dataset.theme === 'dark'
      : matchMedia('(prefers-color-scheme: dark)').matches;
    root.dataset.theme = dark ? 'light' : 'dark';
    try { localStorage.setItem('lqd-theme', root.dataset.theme); } catch (e) {}
  });

  const navBtn = document.querySelector('[data-nav-toggle]');
  const nav = document.getElementById('main-nav');
  navBtn?.addEventListener('click', () => {
    const open = nav.classList.toggle('is-open');
    navBtn.setAttribute('aria-expanded', String(open));
  });

  document.addEventListener('keydown', (e) => {
    if (e.key !== '/' || e.metaKey || e.ctrlKey || /^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement?.tagName || '')) return;
    const input = document.querySelector('.header-search input, .search-form input');
    if (input && input.offsetParent !== null) {
      e.preventDefault();
      input.focus();
    }
  });

  // Hiện dần khi cuộn tới; gọi lại cho thẻ mới chèn bằng AJAX.
  const canReveal = 'IntersectionObserver' in window && !matchMedia('(prefers-reduced-motion: reduce)').matches;
  const io = canReveal && new IntersectionObserver((entries) => {
    for (const en of entries) {
      if (en.isIntersecting) {
        const el = en.target;
        el.classList.add('is-in');
        io.unobserve(el);
        // Hiện xong thì trả lại transition gốc (hover nổi lên) của phần tử.
        setTimeout(() => { el.classList.remove('reveal', 'is-in'); el.style.transitionDelay = ''; }, 900);
      }
    }
  }, { rootMargin: '0px 0px -40px 0px' });
  const reveal = (els) => {
    if (!io) return;
    els.forEach((el, i) => {
      el.classList.add('reveal');
      el.style.transitionDelay = `${Math.min(i % 6, 5) * 40}ms`;
      io.observe(el);
    });
  };
  reveal(document.querySelectorAll('.card, .tile, .result, .side-box, .contact-card'));

  // AJAX (window.LQ do lq_script() của lõi in ra). Thiếu thì giữ nguyên phân trang / ô tìm kiếm thường.
  const LQ = window.LQ;
  if (!LQ?.get) return;

  // "Xem thêm bài": ẩn phân trang, mỗi lần bấm lấy trang kế tiếp với cùng điều kiện của trang đang xem.
  document.querySelectorAll('[data-load-more]').forEach((box) => {
    const grid = box.parentElement.querySelector('[data-posts-grid]');
    if (!grid) return;
    const btn = box.querySelector('button');
    const status = box.querySelector('.load-more-status');
    const query = JSON.parse(box.dataset.query || '{}');
    let page = +box.dataset.page;
    const max = +box.dataset.max;
    box.parentElement.querySelector('.pagination')?.setAttribute('hidden', '');
    box.hidden = false;
    btn.addEventListener('click', () => {
      btn.disabled = true;
      btn.textContent = 'Đang tải…';
      status.textContent = '';
      LQ.get('posts', { ...query, page: page + 1 }).then((r) => {
        page = r.page;
        const tpl = document.createElement('template');
        tpl.innerHTML = r.html;
        const cards = [...tpl.content.children];
        grid.append(tpl.content);
        reveal(cards);
        cards[0]?.querySelector('a:not([tabindex="-1"])')?.focus({ preventScroll: true });
        if (!r.has_more || page >= max) {
          box.remove();
          return;
        }
        btn.textContent = 'Xem thêm bài';
        status.textContent = `Trang ${page}/${r.max_pages}`;
      }).catch((e) => {
        btn.textContent = 'Thử lại';
        status.textContent = e.message;
      }).finally(() => { btn.disabled = false; });
    });
  });

  // Gợi ý khi gõ ô tìm kiếm ở đầu trang: bài/trang + chuyên mục, thẻ khớp tên. Enter không chọn mục nào = trang kết quả đầy đủ.
  const form = document.querySelector('.header-search');
  const input = form?.querySelector('input[name="s"]');
  if (!input) return;
  const pop = document.createElement('div');
  pop.className = 'search-pop';
  pop.id = 'search-pop';
  pop.setAttribute('role', 'listbox');
  pop.hidden = true;
  form.append(pop);
  input.setAttribute('role', 'combobox');
  input.setAttribute('aria-autocomplete', 'list');
  input.setAttribute('aria-controls', pop.id);
  input.setAttribute('aria-expanded', 'false');
  input.autocomplete = 'off';

  const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
  let timer, ctrl, active = -1;
  const items = () => [...pop.querySelectorAll('[role="option"]')];
  const open = (on) => { pop.hidden = !on; input.setAttribute('aria-expanded', String(on)); if (!on) setActive(-1); };
  const setActive = (i) => {
    const list = items();
    active = i >= 0 && i < list.length ? i : -1;
    list.forEach((el, n) => el.classList.toggle('is-active', n === active));
    if (active >= 0) { input.setAttribute('aria-activedescendant', list[active].id); list[active].scrollIntoView({ block: 'nearest' }); }
    else input.removeAttribute('aria-activedescendant');
  };
  const render = (r) => {
    let n = 0;
    const opt = (url, inner) => `<a role="option" id="sp-${n++}" href="${esc(url)}">${inner}</a>`;
    let html = '';
    if (r.posts.length) {
      html += '<div class="sp-group">Bài viết</div>' + r.posts.map((p) => opt(p.url,
        `<span class="sp-thumb">${p.thumbnail ? `<img src="${esc(p.thumbnail)}" alt="" loading="lazy">` : esc(p.title.charAt(0).toUpperCase())}</span>`
        + `<span class="sp-text"><span class="sp-title">${esc(p.title)}</span><span class="sp-meta">${p.type === 'page' ? 'Trang' : esc(p.date)}</span></span>`)).join('');
    }
    if (r.terms.length) {
      html += '<div class="sp-group">Phân loại</div>' + r.terms.map((t) => opt(t.url,
        `<span class="sp-thumb sp-term">#</span><span class="sp-text"><span class="sp-title">${esc(t.name)}</span><span class="sp-meta">${esc(t.taxonomy_label)}</span></span>`)).join('');
    }
    html += r.posts.length || r.terms.length
      ? opt(r.more_url, `<span class="sp-more">Xem tất cả ${r.found} kết quả cho “${esc(r.query)}” →</span>`)
      : `<div class="sp-empty">Không tìm thấy gì cho “${esc(r.query)}”.</div>`;
    pop.innerHTML = html;
    active = -1;
    open(true);
  };
  input.addEventListener('input', () => {
    clearTimeout(timer);
    ctrl?.abort();
    const q = input.value.trim();
    if (q.length < 2) { open(false); return; }
    timer = setTimeout(() => {
      ctrl = new AbortController();
      LQ.get('search', { q, limit: 6 }, { signal: ctrl.signal }).then(render).catch((e) => { if (e.name !== 'AbortError') open(false); });
    }, 180);
  });
  input.addEventListener('keydown', (e) => {
    if (pop.hidden) return;
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); const last = items().length - 1; setActive(e.key === 'ArrowDown' ? (active >= last ? 0 : active + 1) : (active <= 0 ? last : active - 1)); }
    else if (e.key === 'Enter' && active >= 0) { e.preventDefault(); location.href = items()[active].href; }
    else if (e.key === 'Escape') { e.preventDefault(); open(false); }
  });
  input.addEventListener('focus', () => { if (pop.innerHTML && input.value.trim().length >= 2) open(true); });
  document.addEventListener('pointerdown', (e) => { if (!form.contains(e.target)) open(false); });
})();
