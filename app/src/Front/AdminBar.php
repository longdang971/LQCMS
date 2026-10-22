<?php
declare(strict_types=1);

namespace LQ\Front;

use LQ\Content\Posts;
use LQ\Content\Taxonomies;
use LQ\Core\Csrf;
use LQ\Core\ThemeFiles;

/**
 * Thanh quản trị trên trang web (kiểu admin bar của WordPress) cho người đã đăng nhập vào được admin.
 * Chỉ chạy ở frontend — trang admin không có thanh này.
 *
 * Lõi tự gắn: CSS ở lq_script() (dự phòng ở lq_footer() nếu theme không gọi lq_script), HTML ở lq_footer(),
 * class "admin-bar" cho <body>. Thanh cố định ở mép trên và đẩy trang xuống bằng margin-top của <html>;
 * phần tử sticky/fixed của theme nên dùng top: var(--lq-admin-bar, 0px).
 *
 * Plugin/theme thêm mục bằng lq_add_admin_bar_item($id, $args), bỏ mục bằng lq_remove_admin_bar_item($id),
 * hoặc sửa cả danh sách qua filter admin_bar_items. Mỗi mục:
 *   label, url, parent (id mục cha → nằm trong menu thả xuống), side ('left' | 'right'), priority (nhỏ đứng trước),
 *   icon (tên trong ICONS hoặc chuỗi <svg>), meta (chữ phụ), title (tooltip), class, cap (quyền cần có),
 *   method ('post' → gửi form có CSRF, vd. đăng xuất), html (chuỗi hoặc callable trả HTML — in nguyên, không escape).
 * Tắt: tài khoản tự tắt ở Hồ sơ (option admin_bar_{id}), hoặc add_filter('show_admin_bar', fn () => false).
 */
final class AdminBar
{
    /** Icon Lucide (nét 2px) — frontend không nạp thư viện icon nên vẽ sẵn vài cái cần dùng. */
    private const ICONS = [
        'logo' => '<rect x="4" y="4" width="16" height="16" rx="4"/>',
        'plus' => '<path d="M5 12h14"/><path d="M12 5v14"/>',
        'pencil' => '<path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z"/><path d="m15 5 4 4"/>',
        'code' => '<polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/>',
        'user' => '<circle cx="12" cy="8" r="5"/><path d="M20 21a8 8 0 0 0-16 0"/>',
        'eye' => '<path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/>',
        'chevron' => '<path d="m6 9 6 6 6-6"/>',
    ];

    /** Vị trí mục có sẵn — plugin chọn priority xen giữa, vd. 25 để đứng ngay sau "Mới". */
    public const PRIORITY = ['site' => 10, 'new' => 20, 'edit' => 30, 'preview' => 40, 'template' => 50, 'account' => 10];

    private static ?bool $showing = null;
    private static bool $styled = false;
    /** @var array<string, array> */
    private static array $added = [];
    /** @var array<string, true> */
    private static array $removed = [];

    public static function add(string $id, array $args): void
    {
        if (!preg_match('/^[a-z0-9][a-z0-9_.\/-]{0,63}$/', $id)) {
            throw new \InvalidArgumentException("ID mục thanh quản trị không hợp lệ: \"$id\" (chữ thường, số, _ . / -).");
        }
        unset(self::$removed[$id]);
        self::$added[$id] = ['id' => $id] + $args;
    }

    /** Bỏ một mục (có sẵn hoặc do plugin khác thêm) cùng các mục con của nó. */
    public static function remove(string $id): void
    {
        self::$removed[$id] = true;
    }

    public static function boot(): void
    {
        if (!self::showing()) {
            return;
        }
        add_filter('body_class', fn (array $c) => [...$c, 'admin-bar']);
        add_action('lq_script', [self::class, 'printStyles'], 1);
        add_action('lq_footer', [self::class, 'printBar'], 1);
    }

    public static function showing(): bool
    {
        if (self::$showing === null) {
            $u = current_user();
            self::$showing = $u !== null
                && can('access_admin')
                && option('admin_bar_' . $u['id'], true) !== false
                && (bool) apply_filters('show_admin_bar', true);
        }
        return self::$showing;
    }

    public static function printStyles(): void
    {
        if (!self::$styled) {
            self::$styled = true;
            echo '<style id="lq-admin-bar-css">' . self::css() . "</style>\n";
        }
    }

    public static function printBar(): void
    {
        self::printStyles(); // theme không gọi lq_script() vẫn có CSS
        $children = [];
        $top = ['left' => [], 'right' => []];
        foreach (self::collect() as $it) {
            if (!empty($it['parent'])) {
                $children[$it['parent']][] = $it;
            } else {
                $top[($it['side'] ?? 'left') === 'right' ? 'right' : 'left'][] = $it;
            }
        }
        $html = '<div id="lq-admin-bar" role="navigation" aria-label="Thanh quản trị">';
        foreach ($top as $side => $list) {
            $html .= '<ul class="lqab-' . $side . '">';
            foreach ($list as $it) {
                $html .= self::renderTop($it, $children[$it['id'] ?? ''] ?? []);
            }
            $html .= '</ul>';
        }
        echo $html . '</div><script>' . self::js() . "</script>\n";
    }

    /** Mục có sẵn + mục plugin thêm, bỏ mục đã gỡ và mục thiếu quyền, qua filter, rồi xếp theo priority. */
    public static function collect(): array
    {
        $items = [...self::items(), ...array_values(self::$added)];
        $gone = self::$removed;
        // Gỡ mục cha thì gỡ luôn mục con.
        foreach ($items as $it) {
            if (isset($gone[$it['parent'] ?? ''])) {
                $gone[$it['id'] ?? ''] = true;
            }
        }
        $items = array_filter($items, fn ($it) => !isset($gone[$it['id'] ?? '']) && (empty($it['cap']) || can((string) $it['cap'])));
        $items = (array) apply_filters('admin_bar_items', array_values($items));
        $items = array_values(array_filter($items, fn ($it) => is_array($it)
            && (($it['label'] ?? '') !== '' || !empty($it['html']))));
        // Mục không ghi priority: mục cấp trên của lõi theo PRIORITY, còn lại xếp cuối theo thứ tự thêm.
        foreach ($items as $i => &$it) {
            $it['_order'] = [(int) ($it['priority'] ?? (empty($it['parent']) ? (self::PRIORITY[$it['id'] ?? ''] ?? 100) : 100)), $i];
        }
        unset($it);
        usort($items, fn ($a, $b) => $a['_order'] <=> $b['_order']);
        return $items;
    }

    /** Mục mặc định theo trang đang xem và quyền của người dùng. */
    public static function items(): array
    {
        $u = current_user();
        $items = [
            ['id' => 'site', 'label' => get_bloginfo('name'), 'url' => admin_url(), 'icon' => 'logo', 'class' => 'lqab-site'],
            ['id' => 'site-dashboard', 'parent' => 'site', 'label' => 'Bảng tin', 'url' => admin_url()],
        ];
        foreach ([
            ['site-themes', 'Giao diện', '/themes', 'manage_themes'],
            ['site-plugins', 'Plugin', '/plugins', 'manage_plugins'],
            ['site-settings', 'Cài đặt', '/settings', 'manage_options'],
        ] as [$id, $label, $path, $cap]) {
            if (can($cap)) {
                $items[] = ['id' => $id, 'parent' => 'site', 'label' => $label, 'url' => admin_url($path)];
            }
        }

        $new = array_values(array_filter([
            can('edit_posts') ? ['id' => 'new-post', 'parent' => 'new', 'label' => 'Bài viết', 'url' => admin_url('/posts/new')] : null,
            can('edit_pages') ? ['id' => 'new-page', 'parent' => 'new', 'label' => 'Trang', 'url' => admin_url('/pages/new')] : null,
            ...array_values(array_map(fn ($t) => can($t['cap']) ? ['id' => 'new-' . $t['key'], 'parent' => 'new', 'label' => lq_ucfirst($t['singular']), 'url' => admin_url('/' . $t['route'] . '/new')] : null,
                array_filter(Posts::customTypes(), fn ($t) => $t['show_in_menu']))),
            can('upload_files') ? ['id' => 'new-media', 'parent' => 'new', 'label' => 'Media', 'url' => admin_url('/media')] : null,
        ]));
        if ($new) {
            $items[] = ['id' => 'new', 'label' => 'Mới', 'url' => $new[0]['url'], 'icon' => 'plus'];
            array_push($items, ...$new);
        }

        if ($edit = self::editItem()) {
            $items[] = $edit;
        }
        if (is_preview()) {
            $items[] = ['id' => 'preview', 'label' => 'Đang xem trước', 'icon' => 'eye', 'class' => 'lqab-badge'];
        }
        if (can('edit_themes') && ThemeFiles::editingAllowed() && ($tpl = get_current_template()) !== '') {
            // Template ngoài thư mục theme (plugin đổi qua filter template_include) là đường dẫn tuyệt đối: trình sửa mã không mở được -> chỉ hiện tên.
            $inTheme = !str_starts_with($tpl, '/');
            $items[] = ['id' => 'template', 'label' => $inTheme ? $tpl : basename($tpl), 'title' => $inTheme ? '' : $tpl, 'icon' => 'code', 'url' => $inTheme ? admin_url('/themes/editor?file=' . rawurlencode($tpl)) : '', 'class' => 'lqab-mono'];
        }

        $name = (string) ($u['display_name'] ?: $u['username']);
        array_push(
            $items,
            ['id' => 'account', 'side' => 'right', 'label' => $name, 'url' => admin_url('/profile'), 'icon' => 'user'],
            ['id' => 'account-profile', 'parent' => 'account', 'label' => 'Hồ sơ', 'url' => admin_url('/profile'), 'meta' => '@' . $u['username']],
            ['id' => 'account-hide', 'parent' => 'account', 'label' => 'Ẩn thanh này', 'url' => admin_url('/profile') . '#admin-bar'],
            ['id' => 'account-logout', 'parent' => 'account', 'label' => 'Đăng xuất', 'url' => admin_url('/logout'), 'method' => 'post'],
        );
        return $items;
    }

    /** "Sửa bài viết / trang / chuyên mục…" cho đối tượng đang xem, nếu người dùng có quyền. */
    private static function editItem(): ?array
    {
        $o = get_queried_object();
        if (!$o) {
            return null;
        }
        // Trang được chọn làm "trang bài viết" (is_home, đối tượng đang xem là trang đó) cũng sửa được như WordPress.
        if ((is_singular() || (is_home() && ($o['type'] ?? '') === 'page')) && Posts::canEdit($o)) {
            $t = Posts::type((string) ($o['type'] ?? 'post')) ?? Posts::type('post');
            $status = match ($o['status'] ?? '') {
                'draft' => 'Nháp',
                'pending' => 'Chờ duyệt',
                // Bài hẹn giờ lưu status 'scheduled' (Cron đổi sang 'published' khi tới giờ); 'published' giờ tương lai là dữ liệu cũ.
                'scheduled' => 'Hẹn giờ',
                'published' => ($o['published_at'] ?? '') > now_utc() ? 'Hẹn giờ' : '',
                default => '',
            };
            return ['id' => 'edit', 'label' => 'Sửa ' . $t['singular'], 'url' => admin_url("/{$t['route']}/{$o['id']}/edit"), 'icon' => 'pencil', 'meta' => $status];
        }
        if ((is_category() || is_tag() || is_tax()) && ($tax = Taxonomies::get((string) $o['taxonomy'])) && can($tax['cap'])) {
            return ['id' => 'edit', 'label' => 'Sửa ' . $tax['singular'], 'url' => admin_url("/{$tax['route']}/{$o['id']}/edit"), 'icon' => 'pencil'];
        }
        return null;
    }

    private static function icon(string $name): string
    {
        if (str_starts_with(ltrim($name), '<svg')) {
            return '<span class="lqab-ic" aria-hidden="true">' . $name . '</span>';
        }
        $paths = self::ICONS[$name] ?? '';
        return $paths === '' ? '' : '<svg class="lqab-i" viewBox="0 0 24 24" aria-hidden="true">' . $paths . '</svg>';
    }

    /** Nội dung bên trong một mục: html riêng, hoặc icon + nhãn + chữ phụ. */
    private static function inner(array $it, bool $top): string
    {
        if (!empty($it['html'])) {
            return is_callable($it['html']) ? (string) ($it['html'])($it) : (string) $it['html'];
        }
        return self::icon((string) ($it['icon'] ?? ''))
            . '<span' . ($top ? ' class="lqab-label"' : '') . '>' . e($it['label']) . '</span>'
            . (($it['meta'] ?? '') !== '' ? '<span class="lqab-meta">' . e($it['meta']) . '</span>' : '');
    }

    private static function link(array $it, string $inner): string
    {
        $cls = trim('lqab-a ' . (!empty($it['html']) && ($it['url'] ?? '') === '' ? 'lqab-html ' : '') . ($it['class'] ?? ''));
        $title = ($it['title'] ?? '') !== '' ? ' title="' . e($it['title']) . '"' : '';
        if (($it['method'] ?? '') === 'post') {
            return '<form method="post" action="' . e($it['url']) . '"><input type="hidden" name="_token" value="' . e(Csrf::token()) . '">'
                . '<button type="submit" class="' . e($cls) . '"' . $title . '>' . $inner . '</button></form>';
        }
        if (($it['url'] ?? '') === '') {
            return '<span class="' . e($cls) . '"' . $title . '>' . $inner . '</span>';
        }
        return '<a class="' . e($cls) . '" href="' . e($it['url']) . '"' . $title . '>' . $inner . '</a>';
    }

    private static function renderTop(array $it, array $kids): string
    {
        $inner = self::inner($it, true);
        if (!$kids) {
            return '<li>' . self::link($it, $inner) . '</li>';
        }
        $menu = '<ul class="lqab-menu" role="menu">';
        foreach ($kids as $k) {
            $menu .= '<li role="none">' . self::link($k, self::inner($k, false)) . '</li>';
        }
        return '<li class="lqab-has-menu">' . self::link($it, $inner)
            . '<button type="button" class="lqab-toggle" aria-expanded="false" aria-label="Mở menu ' . e($it['label'] ?? $it['id'] ?? '') . '">' . self::icon('chevron') . '</button>'
            . $menu . '</ul></li>';
    }

    /** Đặt cùng ID #lq-admin-bar để CSS của theme (a, ul, button…) khó đè lên. */
    private static function css(): string
    {
        return <<<'CSS'
:root{--lq-admin-bar:36px}
html{margin-top:var(--lq-admin-bar)!important}
#lq-admin-bar{position:fixed;top:0;left:0;right:0;z-index:99999;height:var(--lq-admin-bar);display:flex;justify-content:space-between;gap:8px;padding:0 8px;background:#0a0a0a;color:#a3a3a3;border-bottom:1px solid #1f1f1f;font:500 13px/1 "Geist",ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif;letter-spacing:0;text-align:left;-webkit-font-smoothing:antialiased}
#lq-admin-bar *{box-sizing:border-box;margin:0;padding:0;font:inherit;color:inherit;letter-spacing:inherit;text-transform:none;text-decoration:none;list-style:none;border:0;background:none;box-shadow:none}
#lq-admin-bar ul{display:flex;align-items:center;gap:2px;min-width:0}
#lq-admin-bar .lqab-left{flex:1;overflow:visible}
#lq-admin-bar li{position:relative;display:flex;align-items:center;min-width:0}
#lq-admin-bar .lqab-a{display:inline-flex;align-items:center;gap:7px;height:28px;padding:0 10px;border-radius:8px;white-space:nowrap;cursor:pointer;transition:background .12s,color .12s;max-width:260px}
#lq-admin-bar .lqab-a:hover,#lq-admin-bar .lqab-a:focus-visible{background:#1c1c1c;color:#fafafa;outline:none}
#lq-admin-bar .lqab-label{overflow:hidden;text-overflow:ellipsis}
#lq-admin-bar .lqab-i{width:15px;height:15px;flex-shrink:0;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
#lq-admin-bar .lqab-ic{display:inline-grid;place-items:center;width:15px;height:15px;flex-shrink:0}
#lq-admin-bar .lqab-ic svg{width:15px;height:15px;display:block}
#lq-admin-bar .lqab-html{cursor:default}
#lq-admin-bar .lqab-menu .lqab-html{height:auto;min-height:32px;color:#a3a3a3}
#lq-admin-bar .lqab-html:hover{background:none;color:inherit}
#lq-admin-bar .lqab-menu .lqab-meta{margin-left:12px}
#lq-admin-bar .lqab-menu .lqab-a>span:not(.lqab-meta):not(.lqab-ic){margin-right:auto}
#lq-admin-bar .lqab-site .lqab-i{fill:#c6f432;stroke:#c6f432;width:14px;height:14px}
#lq-admin-bar .lqab-site{color:#fafafa;font-weight:600}
#lq-admin-bar .lqab-meta{font-size:11.5px;color:#737373;font-weight:400}
#lq-admin-bar .lqab-a .lqab-meta{height:18px;display:inline-flex;align-items:center;padding:0 6px;border-radius:5px;background:#262626;color:#d4d4d4}
#lq-admin-bar .lqab-mono{font-family:"Geist Mono",ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;font-weight:400}
#lq-admin-bar .lqab-badge{background:#c6f432;color:#0a0a0a;height:22px;font-size:12px;cursor:default}
#lq-admin-bar .lqab-badge:hover{background:#c6f432;color:#0a0a0a}
#lq-admin-bar .lqab-has-menu>.lqab-a{padding-right:4px;border-top-right-radius:0;border-bottom-right-radius:0}
#lq-admin-bar .lqab-toggle{display:inline-grid;place-items:center;width:22px;height:28px;border-radius:0 8px 8px 0;cursor:pointer;color:#737373;transition:background .12s,color .12s}
#lq-admin-bar .lqab-toggle .lqab-i{width:13px;height:13px;transition:transform .15s}
#lq-admin-bar .lqab-has-menu:hover>.lqab-a,#lq-admin-bar .lqab-has-menu:hover>.lqab-toggle,#lq-admin-bar .lqab-has-menu.open>.lqab-a,#lq-admin-bar .lqab-has-menu.open>.lqab-toggle{background:#1c1c1c;color:#fafafa}
#lq-admin-bar .lqab-has-menu.open>.lqab-toggle .lqab-i{transform:rotate(180deg)}
#lq-admin-bar .lqab-menu{position:absolute;top:calc(100% + 4px);left:0;display:none;flex-direction:column;align-items:stretch;gap:0;min-width:200px;padding:5px;border-radius:12px;background:#141414;border:1px solid #262626;box-shadow:0 12px 32px rgba(0,0,0,.35)}
#lq-admin-bar .lqab-right .lqab-menu{left:auto;right:0}
#lq-admin-bar .lqab-menu::before{content:"";position:absolute;left:0;right:0;top:-6px;height:6px}
#lq-admin-bar .lqab-has-menu:hover>.lqab-menu,#lq-admin-bar .lqab-has-menu.open>.lqab-menu{display:flex}
#lq-admin-bar .lqab-menu li,#lq-admin-bar .lqab-menu form{display:block;width:100%}
#lq-admin-bar .lqab-menu .lqab-a{display:flex;width:100%;max-width:none;gap:8px;height:32px;padding:0 10px;color:#d4d4d4;border-radius:7px}
#lq-admin-bar .lqab-menu .lqab-a:hover,#lq-admin-bar .lqab-menu .lqab-a:focus-visible{background:#262626;color:#fafafa}
@media (max-width:640px){
  #lq-admin-bar .lqab-left .lqab-label{display:none}
  #lq-admin-bar .lqab-left .lqab-a .lqab-meta{display:none}
  #lq-admin-bar .lqab-mono{display:none}
  #lq-admin-bar .lqab-left li:not(:first-child) .lqab-menu{left:-40px}
}
@media print{#lq-admin-bar{display:none}html{margin-top:0!important}}
CSS;
    }

    /** Menu mở bằng hover (chuột) hoặc nút mũi tên (cảm ứng, bàn phím); Esc / bấm ra ngoài để đóng. */
    private static function js(): string
    {
        return <<<'JS'
(()=>{const bar=document.getElementById('lq-admin-bar');if(!bar)return;
const close=(except)=>bar.querySelectorAll('.lqab-has-menu.open').forEach(li=>{if(li!==except){li.classList.remove('open');li.querySelector('.lqab-toggle').setAttribute('aria-expanded','false')}});
bar.addEventListener('click',e=>{const t=e.target.closest('.lqab-toggle');if(!t)return;const li=t.parentElement;const on=!li.classList.contains('open');close(li);li.classList.toggle('open',on);t.setAttribute('aria-expanded',on?'true':'false');if(on)li.querySelector('.lqab-menu .lqab-a')?.focus()});
document.addEventListener('click',e=>{if(!bar.contains(e.target))close()});
document.addEventListener('keydown',e=>{if(e.key==='Escape'&&bar.querySelector('.open')){const li=bar.querySelector('.open');close();li.querySelector('.lqab-toggle').focus()}});
})();
JS;
    }
}
