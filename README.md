# LQCMS

CMS viết bằng PHP thuần (không framework, không Composer), lấy cảm hứng từ WordPress. Giai đoạn hiện tại mới có **trang quản trị**; frontend sẽ làm sau.

## Yêu cầu

- PHP 8.2+ với các extension `pdo_mysql`, `mbstring`, `fileinfo`, `zip` (`intl` nên có)
- MariaDB 10.6+ hoặc MySQL 8+

## Chạy thử trên máy

```bash
php -S 127.0.0.1:8088 -t public public/index.php
```

Mở http://127.0.0.1:8088. Lần đầu chạy sẽ hiện trình cài đặt: nhập thông tin database và tài khoản quản trị là xong. Trình cài đặt tự tạo `config.php` ở thư mục gốc project.

Muốn cài lại từ đầu thì xoá `config.php` và các bảng `lq_*` trong database.

## Triển khai lên VPS

- Document root trỏ vào thư mục **`public/`**, không phải thư mục gốc project. Nhờ vậy `config.php`, `app/` và `database/` không truy cập được từ web.
- nginx: xem `nginx.conf.example`. Chỉ cần cấu hình một lần, sau đó đổi permalink trong admin không phải sửa nginx.
- Apache: `public/.htaccess` đã có sẵn.
- Thư mục `public/content/uploads`, `public/content/themes` và `public/content/plugins` phải ghi được bởi user chạy PHP-FPM.

## Cấu trúc

```
app/
  bootstrap.php       nạp autoload + helpers
  helpers.php         hàm tiện ích: e(), option(), add_action(), apply_filters()...
  src/Core/           Database, Auth, Router, Permalink, Hooks, Themes, Plugins, Uploads, Installer...
  src/Content/        Posts, Terms (chuyên mục, thẻ)
  src/Admin/          controller trang quản trị
  views/              template PHP của admin + trình cài đặt
database/schema.sql   cấu trúc bảng
public/
  index.php           front controller, mọi request đều qua đây
  assets/admin/       admin.css, admin.js
  content/themes/     theme
  content/plugins/    plugin
  content/uploads/    ảnh upload
```

## Thư viện giao diện (tải qua CDN jsDelivr)

| Mục đích | Thư viện |
|---|---|
| Hộp xác nhận | SweetAlert2 11 |
| Thông báo toast | Notyf 3 (nhẹ ~3 KB, có nút đóng và hiệu ứng ripple, đã chỉnh màu theo admin) |
| Icon | Lucide |
| Trình soạn thảo | TinyMCE 7 (bản GPL, không cần API key) |

Plugin có thể gọi lại hai thư viện trên qua `window.LQ`:

```js
LQ.toast('success', 'Đã lưu!');                 // success | error | warning | info
LQ.confirm({ title: 'Xoá?', danger: true }).then(ok => { ... });
```

## Đường dẫn tĩnh (permalink)

Vào **Cài đặt → Đường dẫn tĩnh** để chọn mẫu có sẵn hoặc tự gõ cấu trúc, ví dụ `/%category%/%postname%.html`. Các thẻ hỗ trợ:

`%year%` `%monthnum%` `%day%` `%hour%` `%minute%` `%second%` `%post_id%` `%postname%` `%category%` `%author%`

`LQ\Core\Permalink::compile()` biên dịch cấu trúc thành regex để router khớp request. `Permalink::build()` làm chiều ngược lại, sinh URL từ bài viết. Khi đổi slug của bài đã đăng, slug cũ được lưu vào bảng `old_slugs`; khi đổi cấu trúc, cấu trúc cũ được lưu vào option `permalink_history`. Frontend sẽ dùng hai nguồn này để chuyển hướng 301 link cũ.

## Viết theme

```
public/content/themes/ten-theme/
  theme.json      {"name": "...", "description": "...", "version": "...", "author": "...", "screenshot": "screenshot.png", "tags": []}
  index.php       template chính
  functions.php   (tuỳ chọn) nạp mỗi request khi theme đang kích hoạt
```

## Viết plugin

```
public/content/plugins/ten-plugin/
  ten-plugin.php  tệp chính, đầu tệp có khối "Plugin Name: ..."
  uninstall.php   (tuỳ chọn) chạy khi xoá plugin
```

```php
<?php
/**
 * Plugin Name: Tên plugin
 * Description: Mô tả
 * Version: 1.0.0
 * Author: Bạn
 */
defined('LQ_ROOT') || exit;

// Widget cho Bảng tin: người dùng kéo vào từ ngăn "Thêm widget" (chế độ Tuỳ chỉnh).
lq_add_dashboard_widget('ten-plugin', 'Tiêu đề widget', function (int $size) {
    echo '<section class="tile"><div class="tile-title"><div><h3>Tiêu đề</h3></div></div>…</section>';
}, ['description' => 'Mô tả ngắn', 'icon' => 'puzzle', 'size' => 4, 'sizes' => [3, 4, 6], 'default' => false]);
add_filter('content_save_pre', fn ($html) => $html);
lq_add_admin_page('ten-plugin', 'Tên plugin', function () { echo '...'; }, 'puzzle');
add_action('activate_ten-plugin', function () { /* chạy khi kích hoạt */ });
```

Xem plugin mẫu `hello-lq` để biết thêm.

**Hook có sẵn:** `post_published`, `post_revision_restored`, `cron_cleanup`, `post_field_validate` (filter), `post_meta_saved`, `init`, `admin_head`, `admin_footer`, `admin_menu` (filter), `admin_routes`, `role_caps` (filter), `content_save_pre` (filter), `post_saved`, `post_trashed`, `post_restored`, `before_post_deleted`, `term_saved`, `term_deleted`, `user_login`, `user_logout`, `user_registered`, `image_uploaded`, `theme_switched`, `settings_saved`, `settings_general_fields`, `activate_{slug}`, `deactivate_{slug}`.

## Thêm cách phân loại & field cho bài viết

**Taxonomy tuỳ biến** (giống thẻ/chuyên mục, dữ liệu dùng chung giữa các bài) — vd. Diễn viên, Đạo diễn, Hãng phim:

```php
lq_register_taxonomy('actor', [
    'label' => 'Diễn viên', 'singular' => 'diễn viên',
    'route' => 'dien-vien',       // trang quản lý: /admin/dien-vien (tự có trong menu Bài viết)
    'hierarchical' => false,      // false: nhập dạng chip như Thẻ; true: checklist cha/con như Chuyên mục
    'list_column' => true,        // thêm cột trong danh sách bài viết
    'context' => 'side',          // khung trong form: 'side' (cột phải) hoặc 'main'
]);
post_terms($postId, 'actor');     // đọc ra khi hiển thị
```

**Field riêng của từng bài** (lưu ở bảng `post_meta`):

```php
lq_add_post_field('gdrive_links', [
    'label' => 'Link Google Drive',
    'type' => 'url',              // text, textarea, number, url, email, select, radio, checkbox, date, color, image
    'repeat' => true,             // nhập nhiều giá trị (text, number, url, email, date, image)
    'box' => 'Google Drive',      // các field cùng 'box' gom chung một khung
    'context' => 'main',          // 'main' dưới trình soạn thảo, 'side' cột phải
    'required' => false, 'help' => '...', 'placeholder' => '...', 'width' => 'half',
    'options' => [...],           // cho select/radio
    'min' => 0, 'max' => 100,     // number: giá trị; text/textarea: số ký tự
    'cap' => 'edit_posts',        // ai được sửa field
    'validate' => fn ($value, $post) => null,  // trả chuỗi lỗi để chặn lưu
]);
post_meta($postId, 'gdrive_links', []);        // đọc
update_post_meta($postId, 'gdrive_links', [...]);
```

Khung tự vẽ hoàn toàn: `lq_add_meta_box('id', 'Tiêu đề', fn ($post) => ..., ['context' => 'side', 'save' => fn ($postId, $input) => ...])`.

Dữ liệu được kiểm tra trước khi lưu; lỗi hiện ngay dưới ô và giữ nguyên giá trị đã nhập. Tắt plugin hay đổi theme không xoá dữ liệu đã lưu; bật lại là hiện lại. Ví dụ chạy được: taxonomy "Diễn viên" trong `themes/lq-default/functions.php`, plugin `google-drive`.

Database tự nâng cấp theo phiên bản (`LQ\Core\Migrations`, option `db_version`) nên site đã cài không cần cài lại.

## Thư viện media

Menu **Media**: lưới ảnh, tìm kiếm, lọc theo tháng, kéo thả nhiều ảnh vào trang để tải lên (có tiến trình từng ảnh), bảng chi tiết (Alt, chú thích, sao chép URL, ảnh đang được bài/trang nào dùng), chọn nhiều để xoá. Chỉ lưu đúng tệp gốc — không tạo cỡ ảnh phụ, không nén/xoay lại.

Hộp chọn ảnh dùng chung cho ảnh đại diện, field kiểu `image` và nút chèn ảnh của TinyMCE. Plugin/theme gọi được trong JS:

```js
LQ.pickMedia({ title: 'Chọn ảnh' }).then((m) => m && console.log(m.url, m.alt, m.width, m.height));
```

Hook: `media_uploaded` (nhận bản ghi ảnh), `media_deleting`. Tác giả chỉ sửa/xoá được ảnh do mình tải lên.

## Trang tĩnh & mẫu trang cho theme

Menu **Trang**: trang cha/con (URL dạng `/gioi-thieu/doi-ngu/`), thứ tự, mẫu trang, ảnh đại diện. Chỉ Biên tập viên và Quản trị viên sửa được trang (quyền `edit_pages`). Slug trang cấp gốc không được trùng đường dẫn hệ thống hay tiền tố chuyên mục/thẻ.

Theme khai báo mẫu trang riêng, kèm field chỉ hiện khi chọn đúng mẫu (ví dụ thật trong `themes/lq-default/functions.php`):

```php
lq_register_page_template('contact', [
    'label' => 'Trang liên hệ',
    'description' => 'Có thông tin liên hệ và bản đồ.',
    'file' => 'templates/contact.php',     // tệp trong theme, frontend dùng để hiển thị
    'fields' => [
        'contact_phone' => ['label' => 'Số điện thoại', 'type' => 'text', 'width' => 'half'],
        'contact_map'   => ['label' => 'Link Google Maps', 'type' => 'url'],
    ],
]);
```

Field thường cũng gắn được vào trang: `lq_add_post_field('seo_title', [..., 'post_type' => ['post', 'page']])`. Admin báo đỏ nếu theme khai báo mẫu mà thiếu tệp. Đổi sang mẫu khác thì field của mẫu cũ không bị kiểm tra và dữ liệu cũ được giữ nguyên.

## Chống mất bài & tác vụ định kỳ

- **Tự lưu** mỗi 30 giây và khi chuyển tab. Bài mới thành bản nháp ngay lần tự lưu đầu; bài đã có thì bản tự lưu cất riêng (mỗi người một bản), không đụng bản đang hiển thị. Mở lại trang soạn sẽ được hỏi khôi phục nếu bản tự lưu mới hơn.
- **Lịch sử phiên bản**: mỗi lần lưu tạo một phiên bản (giữ N bản, chỉnh ở Cài đặt chung; 0 = tắt). Trang so sánh tô sáng từng từ thêm/bớt, có nút khôi phục.
- **Khoá chỉnh sửa**: người mở sau được cảnh báo ai đang sửa (chọn quay lại hoặc giành quyền); người bị giành quyền cũng được báo.
- **Chặn ghi đè âm thầm**: nếu bài đã được lưu bởi người khác sau khi mình mở, lần lưu đầu bị chặn kèm thông báo; lưu lần nữa mới ghi đè.
- **Tác vụ định kỳ** chạy theo lượt truy cập (tối đa 1 lần/phút, sau khi đã trả trang; không cần cài cron trên máy chủ): đăng bài/trang lên lịch khi tới giờ, dọn dữ liệu cũ. Trạng thái và nút "Chạy ngay" ở Cài đặt chung. Plugin thêm tác vụ: `lq_add_cron_task('ten', 3600, fn () => ..., 'Mô tả')`.

## Bảng tin dạng widget

Bấm **Tuỳ chỉnh** trên Bảng tin để kéo thả sắp xếp, đổi độ rộng (1/4, 1/3, 1/2, 2/3, cả hàng), gỡ widget, hoặc mở ngăn **Thêm widget** rồi kéo widget vào. Bố cục lưu riêng cho từng tài khoản (option `dashboard_layout_{id}`) và tự lưu sau mỗi thao tác; **Mặc định** đưa về bố cục ban đầu. Widget có sẵn nằm ở `app/views/admin/widgets/`, khai báo trong `app/src/Admin/CoreWidgets.php`; số liệu dùng chung ở `DashboardStats` (mỗi truy vấn chỉ chạy một lần mỗi request). Widget gặp lỗi chỉ hiện thông báo trong ô của nó, không làm hỏng cả trang.

## Vai trò

| Vai trò | Quyền |
|---|---|
| Quản trị viên | Toàn quyền |
| Biên tập viên | Viết/sửa mọi bài, quản lý chuyên mục & thẻ, upload, được lưu HTML nguyên bản |
| Tác giả | Viết/sửa bài của mình, đăng bài, upload; HTML bị lọc theo danh sách trắng |
| Thành viên | Chỉ vào Bảng tin và Hồ sơ |

## Bảo mật đã có

- Mọi truy vấn dùng prepared statement. Output được escape bằng `e()`.
- Token CSRF cho mọi request POST. Cookie session `HttpOnly` + `SameSite=Lax` (bật `Secure` khi chạy HTTPS).
- Mật khẩu băm bằng `password_hash`. Khoá đăng nhập 15 phút sau 5 lần sai cho mỗi IP. "Ghi nhớ đăng nhập" dùng cặp selector/validator, DB chỉ lưu hash.
- Upload: kiểm tra loại file theo nội dung thật (finfo + getimagesize), không tin phần mở rộng.
- Cài theme/plugin từ zip: chặn zip slip, bắt buộc đúng một thư mục gốc.
- Chặn chạy trực tiếp file PHP trong `content/` (nginx, `.htaccess` và cả dev server).
- Plugin gây lỗi khi nạp sẽ tự bị tắt để admin không bị sập theo.
