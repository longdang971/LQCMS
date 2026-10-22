# Viết plugin & widget Bảng tin cho LQCMS

Tài liệu cho người viết plugin: cấu trúc, vòng đời, widget Bảng tin, trang admin riêng, hook, quyền và các tiện ích giao diện admin. Hàm hiển thị ngoài trang web (template tags, field, taxonomy, AJAX) xem [theme-functions.md](theme-functions.md) — plugin gọi được tất cả các hàm đó.

Plugin mẫu đi kèm: `hello-lq` (widget + trang admin + dọn dữ liệu khi xoá) và `google-drive` (field nhiều giá trị + kiểm tra + hàm cho theme).

---

## Cấu trúc

```
content/plugins/ten-plugin/
├── ten-plugin.php     # bắt buộc: tệp chính, cùng tên thư mục
├── uninstall.php      # tuỳ chọn: chạy khi XOÁ plugin
├── icon.svg           # tuỳ chọn: logo (nhận cả icon.png / icon.webp / icon.jpg)
└── assets/…           # CSS, JS, ảnh — truy cập qua /content/plugins/ten-plugin/assets/…
```

Thư mục (slug) chỉ gồm chữ thường, số, `-`, `_`.

### Khối thông tin

Đầu tệp chính phải có khối chú thích, thiếu `Plugin Name` thì hệ thống không nhận:

```php
<?php
/**
 * Plugin Name: Tên plugin
 * Description: Mô tả ngắn hiện trong danh sách plugin
 * Version: 1.0.0
 * Author: Tên bạn
 * Author URI: https://example.com
 * Requires PHP: 8.2
 * Icon: cloud
 */
defined('LQ_ROOT') || exit;
```

| Dòng | Ý nghĩa |
|---|---|
| `Plugin Name` | **Bắt buộc** |
| `Description`, `Version`, `Author`, `Author URI` | Hiện trong trang Plugin |
| `Requires PHP` | Máy chủ chạy PHP thấp hơn thì không cho kích hoạt |
| `Icon` | Tên icon [Lucide](https://lucide.dev/icons) (vd. `cloud`, `smile`). Có tệp `icon.*` thì ảnh được ưu tiên |

`defined('LQ_ROOT') || exit;` chặn chạy tệp khi ai đó gọi thẳng URL — **mọi tệp PHP của plugin** nên có dòng này.

---

## Vòng đời

| Sự kiện | Điều gì xảy ra |
|---|---|
| Mỗi request | Tệp chính của mọi plugin đang bật được nạp **trước** theme, trước khi định tuyến (admin, trang web, `/ajax/`). Mã ở cấp tệp chạy ngay — chỉ nên đăng ký hook, field, widget…; việc nặng đặt trong callback |
| Kích hoạt | Nạp tệp chính rồi chạy action `activate_{slug}`. Ném exception thì không kích hoạt, lỗi hiện cho người dùng |
| Tắt | Chạy action `deactivate_{slug}` (lỗi chỉ ghi log) |
| Xoá | Chạy `uninstall.php` (nếu có) rồi xoá thư mục |
| Plugin gây lỗi khi nạp | Tự bị tắt để admin không chết theo, lỗi ghi vào log |

```php
add_action('activate_ten-plugin', function (): void {
    update_option('ten_plugin_settings', ['enabled' => true], false);
});

add_action('deactivate_ten-plugin', function (): void {
    // ngừng việc đang chạy, KHÔNG xoá dữ liệu người dùng
});
```

`uninstall.php` — dọn dữ liệu plugin đã tạo:

```php
<?php
defined('LQ_ROOT') || exit;

\LQ\Core\Options::delete('ten_plugin_settings');
db()->query('DROP TABLE IF EXISTS {ten_plugin_logs}');
```

Plugin cài từ tệp `.zip` (Plugin › Tải lên) được quét trước khi cài. Mã dùng `eval`, `exec`, `shell_exec`, `base64_decode` lồng nhau, tải mã từ xa… sẽ bị cảnh báo — tránh dùng nếu không thật cần.

---

## Widget Bảng tin

Người dùng bấm **Tuỳ chỉnh** trên Bảng tin, mở ngăn **Thêm widget** rồi kéo widget vào. Mỗi tài khoản có bố cục riêng. Widget của plugin tự được xếp vào nhóm mang tên plugin, thanh công cụ của widget hiện tên plugin — không cần khai báo.

```php
lq_add_dashboard_widget(string $id, string $title, callable $render, array $opts = []);
```

| Tham số | Ý nghĩa |
|---|---|
| `$id` | Duy nhất trên toàn hệ thống — nên đặt tiền tố slug plugin: `ten-plugin/thong-ke`. Chữ thường, số, `_ . / -` |
| `$title` | Tên hiện ở ngăn "Thêm widget" và thanh công cụ |
| `$render` | `function (int $size)` — in HTML (hoặc `return` chuỗi HTML). `$size` là độ rộng hiện tại |

`$opts`:

| Tuỳ chọn | Mặc định | Ý nghĩa |
|---|---|---|
| `description` | `''` | Mô tả trong ngăn "Thêm widget" |
| `icon` | `layout-grid` | Icon Lucide trong ngăn "Thêm widget" |
| `sizes` | `[4, 6, 8, 12]` | Các độ rộng người dùng được chọn |
| `size` | độ rộng đầu tiên trong `sizes` | Độ rộng khi mới thêm |
| `cap` | `access_admin` | Quyền cần có để thấy và thêm widget |
| `default` | `false` | `true` = có sẵn trên Bảng tin của tài khoản chưa tuỳ chỉnh |

Độ rộng tính theo lưới 12 cột:

| `$size` | 3 | 4 | 6 | 8 | 12 |
|---|---|---|---|---|---|
| Chiếm | 1/4 | 1/3 | 1/2 | 2/3 | cả hàng |

**Lỗi được cô lập:** widget ném exception thì chỉ ô đó hiện "Widget gặp lỗi khi hiển thị", cả trang vẫn chạy; lỗi ghi vào log. Dù vậy, render chạy mỗi lần mở Bảng tin — truy vấn nặng hoặc gọi API ngoài nên cache (xem [Cache kết quả](#cache-kết-quả)).

### Khung HTML

Bọc nội dung trong `.tile` để cùng giao diện với widget có sẵn. Có 3 dạng hay dùng:

**1. Thẻ số liệu** — số lớn, có thể bấm cả ô:

```php
lq_add_dashboard_widget('ten-plugin/don-hang', 'Đơn hàng mới', function (int $size): void {
    $count = (int) db()->value('SELECT COUNT(*) FROM {ten_plugin_orders} WHERE created_at > ?', [gmdate('Y-m-d H:i:s', time() - 86400)]);
    ?>
    <a class="tile" href="/admin/plugin/ten-plugin">
      <div class="tile-head"><span>Đơn hàng 24 giờ qua</span><?= icon('shopping-cart') ?></div>
      <div class="tile-value" data-count="<?= $count ?>"><?= number_format($count, 0, ',', '.') ?></div>
      <div class="tile-foot"><span class="muted">Cập nhật mỗi lần mở Bảng tin</span></div>
    </a>
    <?php
}, ['icon' => 'shopping-cart', 'sizes' => [3, 4], 'size' => 3]);
```

**2. Khối có tiêu đề** — danh sách, biểu đồ, form:

```php
lq_add_dashboard_widget('ten-plugin/log', 'Nhật ký', function (int $size): void {
    $rows = db()->all('SELECT * FROM {ten_plugin_logs} ORDER BY id DESC LIMIT ' . ($size >= 8 ? 10 : 5));
    ?>
    <section class="tile">
      <div class="tile-title"><div><h3>Nhật ký</h3><p><?= count($rows) ?> sự kiện gần nhất</p></div></div>
      <?php if (!$rows): ?>
        <div class="empty small"><?= icon('inbox') ?><h3>Chưa có sự kiện</h3></div>
      <?php else: ?>
        <ul class="ten-plugin-log">
          <?php foreach ($rows as $r): ?>
            <li><?= e($r['message']) ?> <span class="muted"><?= e(format_date($r['created_at'])) ?></span></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <p class="tile-note"><?= icon('arrow-right') ?> <a href="/admin/plugin/ten-plugin">Xem tất cả</a></p>
    </section>
    <?php
}, ['icon' => 'scroll-text', 'sizes' => [4, 6, 8, 12], 'size' => 6]);
```

**3. Sát mép** — bảng hoặc danh sách tràn viền: `<section class="tile tile-flush">`.

Class dùng được trong widget:

| Class | Dùng cho |
|---|---|
| `tile` | Khung widget (trên `<section>` hoặc `<a>` nếu cả ô là link) |
| `tile-flush` | Bỏ padding, nội dung sát mép |
| `tile-head` | Hàng nhỏ trên cùng: nhãn bên trái, icon bên phải |
| `tile-value` | Con số lớn. Thêm `data-count="123"` để số chạy lên khi hiện |
| `tile-foot` | Hàng cuối: ghi chú, sparkline |
| `tile-split` | Thêm vào `tile-foot` để xếp nhiều mục ngang hàng (`<b>` cho số) |
| `tile-title` | Tiêu đề khối: `<div><h3>…</h3><p>…</p></div>` |
| `tile-note` | Dòng ghi chú/link ở đáy ô |
| `delta`, `delta up`, `delta down` | Viên tăng/giảm (xanh/đỏ) |
| `muted` | Chữ phụ |
| `chip`, `chips` | Nhãn nhỏ / hàng nhãn |
| `badge`, `badge-soft` | Huy hiệu |
| `empty small` | Trạng thái trống: `<div class="empty small"><?= icon('inbox') ?><h3>…</h3><p>…</p></div>` |
| `btn btn-primary`, `btn-secondary`, `btn-sm`… | Nút (xem [Giao diện admin](#giao-diện-admin)) |

### Biểu đồ có sẵn

`LQ\Admin\Charts` vẽ biểu đồ HTML/SVG cùng phong cách Bảng tin, tự hợp chế độ sáng/tối:

| Hàm | Dữ liệu |
|---|---|
| `Charts::sparkline(array $values, string $label)` | Đường nhỏ cho `tile-foot`: `[3, 5, 2, 8]` |
| `Charts::columns(array $data, string $unit, string $caption)` | Cột: `[['label' => 'T10', 'full' => 'Tháng 10/2026', 'value' => 3], …]` |
| `Charts::stack(array $parts)` | Thanh xếp chồng 100% + chú giải: `[['key' => 'a', 'label' => 'Đã đăng', 'value' => 12], …]` |
| `Charts::hbars(array $rows)` | Thanh ngang: `[['label' => 'Tin tức', 'value' => 20, 'url' => '/admin/…', 'id' => 1], …]` |

```php
use LQ\Admin\Charts;

echo Charts::sparkline($perWeek, 'Số đơn mỗi tuần');
```

### Đổi giao diện theo độ rộng

```php
function (int $size): void {
    $limit = match (true) { $size >= 8 => 10, $size >= 6 => 6, default => 3 };
    $showChart = $size >= 6;
    // …
}
```

---

## Trang admin riêng

```php
lq_add_admin_page(string $slug, string $title, callable $callback, string $icon = 'puzzle', string $cap = 'manage_options', string $keywords = '');
```

`$keywords`: nội dung chính của trang, vd. `'thời gian giữ cache, loại trừ URL, xoá cache'`. Thanh ⌘K và trợ lý AI dùng để tìm đúng trang khi người dùng gõ/hỏi theo nội dung ("chỉnh thời gian giữ cache ở đâu") chứ không chỉ theo tên trang.

Tạo trang `/admin/plugin/{slug}` nằm trong layout admin, kèm mục trong nhóm **Plugin** của menu trái. `$callback()` in phần thân trang. Người không có `$cap` bị chặn (403) và không thấy mục menu.

Trang chỉ nhận GET. Để lưu form, đăng ký thêm route POST qua `admin_routes` — admin **tự kiểm tra token CSRF** cho mọi POST, form chỉ cần `csrf_field()`:

```php
lq_add_admin_page('ten-plugin', 'Tên plugin', function (): void {
    $s = (array) option('ten_plugin_settings', []);
    ?>
    <div class="page-head"><div><h1>Tên plugin</h1></div></div>
    <form method="post" action="/admin/plugin/ten-plugin" class="card narrow">
      <?= csrf_field() ?>
      <div class="stack">
        <div class="field">
          <label for="tp-key">API key</label>
          <input id="tp-key" name="api_key" class="input" value="<?= e($s['api_key'] ?? '') ?>">
          <p class="help">Lấy ở trang quản lý tài khoản của dịch vụ.</p>
        </div>
        <label><input type="checkbox" name="enabled" value="1"<?= checked(!empty($s['enabled'])) ?>> Bật đồng bộ</label>
        <div class="form-actions"><button class="btn btn-primary" type="submit">Lưu</button></div>
      </div>
    </form>
    <?php
}, 'cloud');

add_action('admin_routes', function (\LQ\Core\Router $r): void {
    $r->post('/admin/plugin/ten-plugin', function (): void {
        \LQ\Admin\Kernel::require('manage_options');
        update_option('ten_plugin_settings', [
            'api_key' => input_str('api_key'),
            'enabled' => !empty(input('enabled')),
        ], false);
        flash('success', 'Đã lưu cài đặt.');
        redirect('/admin/plugin/ten-plugin');
    });
});
```

Route tự đăng ký **phải tự kiểm tra quyền** (`Kernel::require($cap)`) — admin chỉ đảm bảo người gọi đã đăng nhập và có `access_admin`.

`Router` nhận: `$r->get($pattern, $handler)`, `$r->post($pattern, $handler)`; `{ten}` trong pattern thành tham số truyền vào `$handler(array $params)`: `{id}` chỉ khớp số, tên khác khớp chữ thường/số/`-`/`_` — `'/admin/plugin/ten-plugin/{id}'` → `$params['id']`. Route trả JSON thì dùng `json_response([...], $status)`.

### Menu admin

Ngoài `lq_add_admin_page`, sửa thẳng menu bằng filter `admin_menu`:

```php
add_filter('admin_menu', function (array $menu): array {
    $menu[] = ['label' => 'Báo cáo', 'icon' => 'chart-bar', 'url' => '/admin/plugin/ten-plugin', 'cap' => 'manage_options'];
    return $menu;
});
```

Mỗi mục: `label`, `icon`, `url`, `cap`, tuỳ chọn `children` (mảng mục con cùng dạng), `badge` (số), `exact` (chỉ sáng khi URL khớp đúng). `['section' => 'Tên nhóm']` là tiêu đề nhóm. Mục thiếu quyền tự bị ẩn.

### Thêm ô vào Cài đặt chung

```php
add_action('settings_general_fields', function (): void {
    ?>
    <section class="card">
      <div class="card-head"><h2>Tên plugin</h2></div>
      <div class="field">
        <label for="tp-id">Mã theo dõi</label>
        <input id="tp-id" name="ten_plugin_tracking" class="input" value="<?= e((string) option('ten_plugin_tracking', '')) ?>">
      </div>
    </section>
    <?php
});

add_action('settings_saved', function (string $page): void {
    if ($page === 'general') {
        update_option('ten_plugin_tracking', input_str('ten_plugin_tracking'));
    }
});
```

Trang Cài đặt › Media có hook tương tự: `settings_media_fields` (`settings_saved` nhận `'media'`).

---

## Giao diện admin

Admin dùng sẵn icon Lucide, SweetAlert2 và Notyf — plugin không cần nạp lại.

### Class CSS

| Nhóm | Class |
|---|---|
| Đầu trang | `page-head` → `<div class="page-head"><div><h1>Tiêu đề</h1></div><div>…nút…</div></div>` |
| Khối | `card`, `card narrow` (hẹp, cho form), `card-flush`, `card-head` |
| Bố cục | `stack` (xếp dọc có khoảng cách), `grid-2`, `grid-3`, `fieldset` |
| Form | `field` (bọc nhãn + ô), `input` (cho `input`/`select`/`textarea`), `help` (ghi chú), `field-error`, `form-actions` |
| Nút | `btn` + `btn-primary` \| `btn-secondary` \| `btn-ghost` \| `btn-danger` \| `btn-danger-soft` \| `btn-accent`; cỡ `btn-sm`, `btn-xs`, `btn-lg`; `btn-block` |
| Bảng | `table` |
| Khác | `muted`, `chip`, `badge`, `empty` |

### Hàm PHP

| Hàm | Mô tả |
|---|---|
| `icon(string $name, string $class = '')` | Icon Lucide: `<?= icon('cloud') ?>` |
| `csrf_field()` / `csrf_token()` | Ô ẩn / chuỗi token CSRF |
| `flash(string $type, string $message)` | Thông báo hiện ở trang kế tiếp (`success`, `error`, `warning`, `info`) |
| `redirect(string $to, int $status = 302)` / `redirect_back(string $fallback)` | Chuyển trang |
| `json_response(array $data, int $status = 200)` | Trả JSON rồi dừng |
| `input($key, $default)` / `input_str($key, $default)` | Đọc dữ liệu gửi lên |
| `old($key, $default)` | Giá trị form cũ sau khi kiểm tra lỗi |
| `selected($a, $b)` / `checked($a, $b = true)` | ` selected` / ` checked` |
| `avatar(string $name, string $size = '', ?string $key = null)` | Ô avatar chữ cái có màu nhận diện |
| `identity_color(int\|string $key)` | Màu cố định theo khoá |
| `kbd(string $key)` | Nhãn phím tắt: `⌘S` trên Mac, `Ctrl S` nơi khác |

### JavaScript (`window.LQ` trong admin)

```js
LQ.toast('success', 'Đã lưu');                       // success | error | warning | info
LQ.confirm({ title: 'Xoá mục này?', text: 'Không hoàn tác được.', danger: true })
  .then(ok => { if (ok) { /* … */ } });
LQ.pickMedia({ title: 'Chọn ảnh' }).then(m => { if (m) img.src = m.url; });   // m: url, alt…
fetch('/admin/plugin/ten-plugin/sync', {
  method: 'POST',
  headers: { 'X-CSRF-Token': LQ.csrf(), Accept: 'application/json' },
});
```

| Hàm | Mô tả |
|---|---|
| `LQ.toast(type, message)` | Thông báo nổi góc màn hình |
| `LQ.confirm({title, text, danger, confirmText, cancelText})` | Hộp xác nhận, trả `Promise<boolean>` |
| `LQ.pickMedia({title, name})` | Mở thư viện media, trả `Promise` ảnh đã chọn hoặc `null`. `name` (chuỗi hoặc hàm trả chuỗi): tên đặt cho ảnh tải lên trong lần mở này, vd. slug bài — bỏ trống thì theo tên tệp gốc |
| `LQ.uploadMedia(files)` | Tải tệp lên thư viện media |
| `LQ.csrf()` | Token CSRF cho `fetch` POST |
| `LQ.slugify(text)` | Chuỗi → slug không dấu |
| `LQ.mod` / `LQ.isMac` | `'⌘'` hoặc `'Ctrl '` / đang dùng Mac |

Form có thuộc tính `data-confirm="Nội dung câu hỏi"` tự hỏi xác nhận trước khi gửi; thêm `data-confirm-danger` cho nút đỏ, `data-confirm-label="Xoá"` để đổi chữ trên nút.

Nạp CSS/JS riêng của plugin vào admin:

```php
add_action('admin_head', function (): void {
    if (str_starts_with(request_path(), '/admin/plugin/ten-plugin')) {
        echo '<link rel="stylesheet" href="/content/plugins/ten-plugin/assets/admin.css">';
    }
});
add_action('admin_footer', function (): void {
    echo '<script src="/content/plugins/ten-plugin/assets/admin.js" defer></script>';
});
```

---

## Thanh quản trị trên trang web

Thanh màu đen ở mép trên trang web cho người đã đăng nhập (trang admin không có). Plugin và theme (trong `functions.php`) thêm, bớt mục bằng hai hàm:

```php
lq_add_admin_bar_item(string $id, array $args);
lq_remove_admin_bar_item(string $id);   // bỏ mục và các mục con của nó
```

```php
// Mục có icon riêng, đứng ngay sau "Mới", chỉ admin thấy, kèm menu con.
lq_add_admin_bar_item('ten-plugin', [
    'label' => 'Cache',
    'url' => admin_url('/plugin/ten-plugin'),
    'priority' => 25,
    'cap' => 'manage_options',
    'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">…</svg>',
]);
lq_add_admin_bar_item('ten-plugin-clear', [
    'parent' => 'ten-plugin',
    'label' => 'Xoá cache',
    'url' => admin_url('/plugin/ten-plugin/clear'),
    'method' => 'post',
]);

// Thêm vào menu có sẵn "Mới", đứng đầu.
lq_add_admin_bar_item('new-product', ['parent' => 'new', 'label' => 'Sản phẩm', 'url' => admin_url('/plugin/shop/new'), 'priority' => 5]);

// Nội dung tự vẽ bên phải: số việc đang chờ.
lq_add_admin_bar_item('ten-plugin-queue', [
    'side' => 'right',
    'priority' => 5,
    'html' => fn () => e(ten_plugin_queue_count() . ' việc'),
]);

// Bỏ tên tệp template khỏi thanh.
lq_remove_admin_bar_item('template');
```

| Khoá | Ý nghĩa |
|---|---|
| `label` | Chữ hiển thị (bắt buộc, trừ khi có `html`) |
| `url` | Đường dẫn; rỗng thì là nhãn/nội dung không bấm được |
| `parent` | `id` mục cha → nằm trong menu thả xuống của mục đó (menu một cấp) |
| `side` | `left` (mặc định) \| `right` |
| `priority` | Số nhỏ đứng trước. Mục có sẵn: `site` 10, `new` 20, `edit` 30, `preview` 40, `template` 50; bên phải `account` 10. Không ghi = đứng cuối theo thứ tự thêm. Trong menu con, mục có sẵn không có priority (tính là 100) |
| `icon` | Tên icon có sẵn (`logo`, `plus`, `pencil`, `code`, `user`, `eye`) hoặc chuỗi `<svg>` riêng (hiện cỡ 15px, nên dùng `stroke="currentColor"` để đổi màu theo trạng thái hover) |
| `html` | Chuỗi hoặc `callable` trả HTML, **in nguyên không escape** — tự `e()` dữ liệu bên trong. Có `url` thì HTML nằm trong link; không có thì là ô nội dung |
| `meta` | Chữ phụ nhỏ cạnh nhãn, vd. trạng thái |
| `title` | Chú thích khi rê chuột |
| `cap` | Quyền cần có để thấy mục |
| `method` | `post` → gửi form kèm token CSRF (route POST trong admin tự kiểm tra token) |
| `class` | Class CSS thêm |

`id`: chữ thường, số, `_ . / -`; nên đặt tiền tố plugin. Gọi `lq_add_admin_bar_item` lần nữa với cùng `id` thì thay mục cũ.

`id` có sẵn: `site` (menu tên website: `site-dashboard`, `site-themes`, `site-plugins`, `site-settings`), `new` (`new-post`, `new-page`, `new-media`), `edit`, `preview`, `template`, `account` (`account-profile`, `account-hide`, `account-logout`). Mục chỉ có khi phù hợp: `edit` khi có quyền sửa trang đang xem, `preview` khi xem bản nháp, `template` khi có quyền sửa theme.

Ví dụ đầy đủ: plugin `hello-lq`.

**Sửa cả danh sách:** filter `admin_bar_items` nhận mảng mục (mỗi mục có khoá `id` cùng các khoá ở bảng trên) sau khi đã thêm/bỏ và lọc quyền, trước khi xếp theo `priority` — dùng khi cần đổi nhãn hay URL của mục có sẵn:

```php
add_filter('admin_bar_items', function (array $items): array {
    foreach ($items as &$it) {
        if ($it['id'] === 'new') {
            $it['label'] = 'Tạo';
        }
    }
    return $items;
});
```

Tắt hẳn thanh: `add_filter('show_admin_bar', fn () => false)`. Kiểm tra trong theme: `is_admin_bar_showing()`.

## Cho trợ lý AI làm việc của plugin

Trợ lý AI (khung chat ⌘J) đọc được mã plugin, dùng được field/taxonomy plugin đăng ký, nhưng **không tự gọi hàm nào của plugin**. Muốn AI làm được việc gì (xem số liệu, xoá cache, đổi cài đặt…) thì plugin khai báo việc đó:

```php
lq_add_ai_tool(string $name, array $args);
```

```php
// Chỉ đọc: AI gọi và nhận kết quả ngay.
lq_add_ai_tool('views_top', [
    'description' => 'Bài viết nhiều lượt xem nhất',
    'params' => ['limit' => ['type' => 'integer', 'description' => 'Số bài, mặc định 10']],
    'cap' => 'edit_posts',
    'run' => fn (array $a) => ten_plugin_views_top((int) ($a['limit'] ?? 10)),
]);

// Có thay đổi: AI chỉ gửi đề xuất, người dùng bấm "Chạy" trên thẻ đề xuất mới thực hiện.
lq_add_ai_tool('cache_clear', [
    'label' => 'Xoá toàn bộ cache trang',
    'description' => 'Xoá cache trang đã lưu, trang sẽ được tạo lại ở lượt xem kế tiếp.',
    'write' => true,
    'run' => fn () => ten_plugin_cache_clear(),
]);
```

| Khoá | Ý nghĩa |
|---|---|
| `$name` | Tên công cụ: chữ thường, số, `_`; không trùng công cụ của lõi. Nên đặt tiền tố plugin (`cache_clear`, `views_top`) |
| `description` | **Bắt buộc.** Mô tả cho AI biết khi nào dùng — viết rõ như hướng dẫn cho người |
| `run` | **Bắt buộc.** `fn (array $args): mixed` — trả chuỗi, số, mảng (gửi AI dạng JSON) hoặc `null`/`true` (= "Xong"), `false` (= thất bại). Ném exception với thông báo dễ hiểu khi lỗi |
| `params` | `['tên' => 'integer']` hoặc `['tên' => ['type' => 'string', 'description' => '…', 'enum' => […]]]`. Kiểu: `string`, `integer`, `number`, `boolean`, `array`, `object`. Chỉ tham số khai báo được chuyển vào `run` |
| `required` | Danh sách tham số bắt buộc |
| `cap` | Quyền cần có — mặc định `manage_options`. Người đang chat thiếu quyền thì AI không thấy công cụ này |
| `write` | `true` = có thay đổi dữ liệu → luôn hỏi người dùng trước. Mặc định `false` (chỉ đọc) |
| `label` | Tên việc hiện trên thẻ đề xuất (mặc định = `description`) |

Nguyên tắc:

- Việc nào thay đổi dữ liệu, gửi email, gọi dịch vụ ngoài… **phải** đặt `'write' => true`. Việc có thay đổi không hoàn tác được — thẻ đề xuất ghi rõ điều này.
- Kiểm tra tham số trong `run` như với dữ liệu người dùng nhập: AI có thể gửi giá trị sai.
- Đừng trả về khoá bí mật, mật khẩu, dữ liệu cá nhân — kết quả được gửi cho nhà cung cấp AI.
- AI thấy công cụ kèm tên plugin (`[Plugin Tên plugin] …`); plugin tắt thì công cụ biến mất.

Ví dụ đầy đủ: plugin `hello-lq` (`hello_status` chỉ đọc, `hello_set_message` có thay đổi).

## Hook

Đăng ký bằng `add_action($hook, $callback, $priority = 10)` / `add_filter(...)`. Số `priority` nhỏ chạy trước. Filter **phải trả về** giá trị.

### Hệ thống & người dùng

| Hook | Loại | Khi nào | Tham số |
|---|---|---|---|
| `init` | action | Mọi request, sau khi nạp plugin và theme | — |
| `activate_{slug}` / `deactivate_{slug}` | action | Bật / tắt plugin | — |
| `user_login` | action | Đăng nhập thành công | `array $user` |
| `user_logout` | action | Đăng xuất | `array $user` |
| `user_registered` | action | Tài khoản mới đăng ký | `int $userId` |
| `role_caps` | filter | Tính quyền của vai trò | `array $caps`, `string $role` |
| `cron_cleanup` | action | Đợt dọn dẹp định kỳ của lõi | — |

### Admin

| Hook | Loại | Khi nào | Tham số |
|---|---|---|---|
| `admin_head` | action | Trong `<head>` của admin | — |
| `admin_footer` | action | Cuối `<body>` của admin | — |
| `admin_routes` | action | Trước khi khớp route admin | `Router $r` |
| `admin_menu` | filter | Menu trái | `array $menu` |
| `admin_bar_items` | filter | Mục của thanh quản trị trên trang web | `array $items` |
| `show_admin_bar` | filter | Có hiện thanh quản trị không | `bool` |
| `settings_general_fields` | action | Cuối form Cài đặt chung | — |
| `settings_media_fields` | action | Cuối form Cài đặt › Media | — |
| `settings_saved` | action | Sau khi lưu cài đặt | `'general'` \| `'permalinks'` \| `'media'` |
| `theme_switched` | action | Đổi theme | `string $new`, `string $old` |
| `theme_file_saved` | action | Lưu tệp trong trình sửa mã theme | `string $theme`, `string $file` |

### Nội dung

| Hook | Loại | Khi nào | Tham số |
|---|---|---|---|
| `content_save_pre` | filter | Nội dung bài trước khi lưu | `string $html` |
| `post_saved` | action | Sau khi lưu bài/trang | `int $id`, `string $status` |
| `post_published` | action | Bài hẹn giờ tự đăng | `int $id`, `'scheduled'` |
| `post_trashed` / `post_restored` | action | Vào / ra thùng rác | `int $id` |
| `before_post_deleted` | action | Trước khi xoá vĩnh viễn | `int $id` |
| `post_revision_restored` | action | Khôi phục một bản sửa | `int $postId`, `int $revisionId` |
| `post_field_validate` | filter | Kiểm tra field tuỳ biến; trả chuỗi lỗi hoặc `null` | `?string $error`, `string $key`, `$value`, `?array $post` |
| `post_meta_saved` | action | Sau khi lưu field tuỳ biến | `int $postId`, `array $values` |
| `term_saved` | action | Lưu chuyên mục/thẻ/term | `int $id`, `string $taxonomy` |
| `term_deleted` | action | Xoá term | `int $id`, `string $taxonomy` |
| `media_uploaded` | action | Tải tệp lên thư viện | `array $media` |
| `image_uploaded` | action | Như trên, chỉ truyền URL | `string $url` |
| `media_deleting` | action | Trước khi xoá tệp media | `array $media` |

### Trang web

Các hook hiển thị (`template_redirect`, `lq_script`, `lq_body_open`, `lq_footer`, `the_content`, `document_title_parts`, `body_class`, `ajax_*`…) xem [theme-functions.md › Hook](theme-functions.md#hook).

Ví dụ: chèn mã Google Analytics vào mọi trang:

```php
add_action('lq_script', function (): void {
    $id = (string) option('ten_plugin_tracking', '');
    if ($id === '' || is_logged_in()) {
        return;
    }
    echo '<script async src="https://www.googletagmanager.com/gtag/js?id=' . e($id) . '"></script>' . "\n";
});
```

---

## Vai trò & quyền

| Vai trò | Quyền |
|---|---|
| `admin` (Quản trị viên) | Tất cả |
| `editor` (Biên tập viên) | `access_admin`, `edit_posts`, `edit_others_posts`, `publish_posts`, `edit_pages`, `manage_terms`, `upload_files`, `unfiltered_html` |
| `author` (Tác giả) | `access_admin`, `edit_posts`, `publish_posts`, `upload_files` |
| `subscriber` (Thành viên) | `access_admin` |

Quyền chỉ admin có: `manage_options`, `manage_themes`, `edit_themes`, `manage_plugins`.

Kiểm tra: `can('edit_posts')` (PHP), `\LQ\Admin\Kernel::require('manage_options')` (dừng với 403). Thêm quyền riêng cho plugin:

```php
add_filter('role_caps', function (array $caps, string $role): array {
    if ($role === 'editor') {
        $caps[] = 'ten_plugin_view_reports';
    }
    return $caps;
});

lq_add_admin_page('ten-plugin-reports', 'Báo cáo', $render, 'chart-bar', 'ten_plugin_view_reports');
```

(Admin có `*` nên tự có mọi quyền mới.)

---

## Lưu dữ liệu

### Option

| Hàm | Mô tả |
|---|---|
| `option(string $name, $default = null)` | Đọc |
| `update_option(string $name, $value, bool $autoload = true)` | Ghi (lưu dạng JSON nên mảng/số/bool giữ nguyên kiểu) |
| `\LQ\Core\Options::delete(string $name)` | Xoá |

Đặt tên có tiền tố plugin (`ten_plugin_…`). `autoload = false` cho dữ liệu lớn hoặc ít dùng — không nạp sẵn ở mọi request.

### Meta của bài

`post_meta($postId, $key, $default)` / `update_post_meta($postId, $key, $value)`. Field nhập liệu trong form bài viết: `lq_add_post_field()` và `lq_add_meta_box()` — xem [theme-functions.md › Field tuỳ biến](theme-functions.md#field-tuỳ-biến).

### Bảng riêng

`db()` là PDO bọc sẵn; `{ten_bang}` tự thêm tiền tố bảng của site.

| Phương thức | Trả về |
|---|---|
| `db()->all($sql, $params)` | Mọi dòng |
| `db()->row($sql, $params)` | Một dòng hoặc `null` |
| `db()->value($sql, $params)` | Một giá trị |
| `db()->column($sql, $params)` | Một cột |
| `db()->insert($table, $data)` | ID vừa thêm |
| `db()->update($table, $data, $where)` | Số dòng đổi |
| `db()->delete($table, $where)` | Số dòng xoá |
| `db()->query($sql, $params)` | `PDOStatement` |
| `db()->transaction(fn () => …)` | Chạy trong transaction |

Luôn truyền giá trị qua `?` — **không** nối chuỗi người dùng nhập vào SQL.

```php
add_action('activate_ten-plugin', function (): void {
    db()->query('CREATE TABLE IF NOT EXISTS {ten_plugin_logs} (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        message VARCHAR(255) NOT NULL,
        created_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
});

db()->insert('ten_plugin_logs', ['message' => 'Đồng bộ xong', 'created_at' => now_utc()]);
```

Ngày giờ lưu theo UTC (`now_utc()`), hiển thị bằng `format_date()`.

### Cache kết quả

Chưa có API cache riêng; dùng option `autoload = false` kèm thời điểm:

```php
function ten_plugin_stats(): array
{
    $c = option('ten_plugin_stats_cache');
    if (is_array($c) && $c['at'] > time() - 600) {
        return $c['data'];
    }
    $data = /* truy vấn nặng / gọi API */ [];
    update_option('ten_plugin_stats_cache', ['at' => time(), 'data' => $data], false);
    return $data;
}
```

---

## Tác vụ định kỳ

```php
lq_add_cron_task('ten-plugin-sync', 3600, function (): void {
    // đồng bộ…
}, 'Đồng bộ dữ liệu Tên plugin');
```

Chạy theo lượt truy cập website (cuối request, tối đa 1 lần/phút), không dùng cron của hệ điều hành — website ít người vào thì tác vụ có thể trễ hơn khoảng đã đặt. Tác vụ nên ngắn; việc dài chia thành nhiều lượt.

---

## Lưu ý bảo mật

- Escape mọi dữ liệu khi in: `e($value)`.
- Route admin tự đăng ký: kiểm tra quyền bằng `Kernel::require()`; form POST có `csrf_field()`.
- AJAX ngoài trang web (`lq_add_ajax`): mặc định chỉ người đã đăng nhập gọi được và có kiểm tra CSRF; `public => true` thì đặt `rate` hợp lý và kiểm tra kỹ dữ liệu đầu vào.
- SQL luôn dùng tham số `?`.
- Không ghi tệp ra ngoài thư mục plugin hoặc `content/uploads`.
- Khoá API của người dùng: lưu option `autoload = false`, không in lại nguyên văn ra HTML công khai.
