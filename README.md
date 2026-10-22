# LQCMS

CMS viết bằng PHP thuần (không framework, không Composer), lấy cảm hứng từ WordPress. Có trang quản trị và frontend theo theme (vòng lặp, thứ bậc template kiểu WordPress).

## Yêu cầu

- PHP 8.2+ với các extension `pdo_mysql`, `mbstring`, `fileinfo`, `zip` (`intl` nên có)
- MariaDB 10.6+ hoặc MySQL 8+

## Chạy thử trên máy

```bash
php -S 127.0.0.1:8088 index.php
```

Mở http://127.0.0.1:8088. Lần đầu chạy sẽ hiện trình cài đặt: nhập thông tin database và tài khoản quản trị là xong. Trình cài đặt tự tạo tệp cấu hình **ở thư mục cha, ngoài web root**, đặt tên theo thư mục project: project ở `/var/www/example.com/` thì tệp là `/var/www/example.com-config.php`. Không ghi được thư mục cha (thiếu quyền, panel hosting giới hạn `open_basedir`) thì tạm ghi `config.php` trong thư mục gốc và admin hiện cảnh báo kèm lệnh chuyển tay. `config.php` cũ trong thư mục gốc tự được chuyển ra ngoài ở request đầu tiên.

Muốn cài lại từ đầu thì xoá tệp cấu hình (`<tên-thư-mục>-config.php` ở thư mục cha) và các bảng `lq_*` trong database.

## Triển khai lên VPS

- Document root trỏ vào **thư mục gốc project** (nơi có `index.php`), giống WordPress — không có thư mục `public/` riêng.
- nginx: xem `nginx.conf.example`. Chỉ cần cấu hình một lần, sau đó đổi permalink trong admin không phải sửa nginx. **Bắt buộc giữ khối chặn `app/`, `storage/`, `database/`** và luật chỉ cho chạy `index.php`: `storage/` chứa khoá mã hoá API key, nhật ký lỗi và gói plugin/theme đang chờ duyệt.
- Apache: `.htaccess` ở gốc (rewrite + chặn) và `app/.htaccess`, `storage/.htaccess`, `database/.htaccess` đã có sẵn.
- Lớp bảo vệ thứ hai khi máy chủ cấu hình thiếu: khoá (`storage/secret.php`) và nhật ký lỗi (`storage/logs/errors.php`) là tệp PHP mở đầu bằng `exit`, view admin tự thoát khi bị gọi thẳng.
- Thư mục `content/uploads`, `content/themes`, `content/plugins` và `storage/` phải ghi được bởi user chạy PHP-FPM.

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
storage/              khoá mã hoá, nhật ký lỗi, thư viện AI, bản sao lưu theme, gói chờ cài (chặn web)
index.php             front controller, mọi request đều qua đây
assets/admin/         admin.css, admin.js
content/themes/       theme
content/plugins/      plugin
content/uploads/      ảnh upload
../<thư-mục>-config.php  cấu hình DB, nằm ngoài web root (tạo bởi trình cài đặt)
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

`LQ\Core\Permalink::compile()` biên dịch cấu trúc thành regex để router khớp request. `Permalink::build()` làm chiều ngược lại, sinh URL từ bài viết. Khi đổi slug của bài đã đăng, slug cũ được lưu vào bảng `old_slugs`; khi đổi cấu trúc, cấu trúc cũ được lưu vào option `permalink_history`. Frontend dùng hai nguồn này để chuyển hướng 301 link cũ về link mới.

## Viết theme

Tra cứu đầy đủ: [docs/theme-functions.md](docs/theme-functions.md) (hàm template, hook, field, AJAX) và [docs/plugin-api.md](docs/plugin-api.md) (plugin, widget Bảng tin, trang admin, hook admin).

```
content/themes/ten-theme/
  theme.json      {"name": "...", "description": "...", "version": "...", "author": "...", "screenshot": "screenshot.png"}
  index.php       template dự phòng (bắt buộc)
  functions.php   (tuỳ chọn) nạp mỗi request khi theme đang kích hoạt
  header.php, footer.php, sidebar.php, searchform.php, single.php, page.php, archive.php… (tuỳ chọn)
```

Theme mẫu đầy đủ: `themes/lq-default` dùng gần hết API dưới đây. Đăng nhập rồi thêm `?debug=1` vào URL bất kỳ để xem template đang dùng và kết quả các hàm `is_*()`.

### Sửa mã theme trong admin

**Giao diện › Sửa mã theme** (hoặc nút `</>` trên thẻ theme): sửa mọi tệp `.php .css .js .json .html .txt .md .svg .xml` của theme ngay trên trình duyệt (trình soạn thảo Ace, `⌘S` để lưu), tạo tệp mới (vd. `single-review.php`, `parts/banner.php`).

- Chỉ quản trị viên (quyền `edit_themes`). Tắt hẳn trên máy chủ thật bằng `'disallow_file_edit' => true` trong tệp cấu hình.
- PHP và JSON được kiểm tra cú pháp trước khi lưu; lỗi thì không ghi và nhảy tới dòng lỗi.
- Đã mở tệp mà tệp bị sửa ở nơi khác (FTP, git) thì hỏi trước khi lưu đè.
- Bản ngay trước lần lưu gần nhất của mỗi tệp nằm ở `storage/theme-backups/` (bị chặn truy cập từ web), bấm **Khôi phục bản trước** để quay lại.
- Theme gây lỗi nặng (vd. sửa hỏng `functions.php`): khách thấy trang "đang bảo trì", quản trị viên thấy tệp + dòng lỗi và nút mở thẳng trình sửa mã. Trình sửa mã không nạp `functions.php` nên luôn vào được để sửa.
- Thư mục theme phải ghi được bởi user chạy PHP; sau khi lưu, cache OPcache của tệp được xoá ngay.

### Định tuyến URL

| URL | Ngữ cảnh |
|---|---|
| `/`, `/page/2/` | trang chủ: bài mới nhất, hoặc trang tĩnh nếu chọn ở **Cài đặt › Trang chủ hiển thị** |
| theo cấu trúc permalink, vd. `/ten-bai/` | bài viết |
| `/cha/con/` | trang tĩnh |
| `/chuyen-muc/tin-tuc/cong-nghe/`, `/the/php/`, `/dien-vien/ten/` | lưu trữ chuyên mục, thẻ, taxonomy tuỳ biến (tiền tố lấy từ cài đặt / `base`) |
| `/tac-gia/ten-dang-nhap/` | bài của một tác giả |
| `/2026/`, `/2026/10/`, `/2026/10/19/` | lưu trữ theo ngày |
| `/?s=tu-khoa` | tìm kiếm (bài viết + trang) |
| `/?p=123` | bài theo ID; bài chưa đăng xem trước được nếu có quyền sửa |

URL đúng nội dung nhưng sai dạng (thiếu cha, sai năm/tháng, slug cũ, cấu trúc permalink cũ, `/page/1/`) đều được 301 về URL chuẩn. `page` và `tac-gia` là đường dẫn hệ thống nên không dùng làm slug ở gốc.

### Thứ bậc template

Tệp đầu tiên có trong theme sẽ được dùng, cuối cùng luôn là `index.php`:

```
bài viết     single-{slug}.php → single.php → singular.php
trang        tệp của mẫu trang đã chọn → page-{slug}.php → page-{id}.php → page.php → singular.php
chuyên mục   category-{slug}.php → category-{id}.php → category.php → archive.php
thẻ          tag-{slug}.php → tag-{id}.php → tag.php → archive.php
taxonomy     taxonomy-{tax}-{slug}.php → taxonomy-{tax}.php → taxonomy.php → archive.php
tác giả      author-{username}.php → author-{id}.php → author.php → archive.php
theo ngày    date.php → archive.php
trang chủ    front-page.php → home.php        (trang "Trang danh sách bài viết": home.php)
tìm kiếm     search.php                      404: 404.php
```

Filter `template_hierarchy` (sửa danh sách) và `template_include` (đổi tệp cuối cùng).

### Vòng lặp

```php
<?php get_header(); ?>
<?php while (have_posts()): the_post(); ?>
  <article<?php post_class(); ?>>
    <h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
    <?php the_post_thumbnail(); ?>
    <p><?php the_date(); ?> · <?php the_author_link(); ?> · <?php the_category(', '); ?></p>
    <?php the_excerpt(); ?>
  </article>
<?php endwhile; ?>
<?php the_posts_pagination(); ?>
<?php get_footer(); ?>
```

Truy vấn riêng (bài liên quan, bài mới ở sidebar…), nhớ gọi `lq_reset_postdata()` sau khi lặp:

```php
$q = lq_query(['category' => 'tin-tuc', 'exclude' => [get_the_ID()], 'per_page' => 3]);
while ($q->have_posts()): $q->the_post(); get_template_part('parts/card'); endwhile;
lq_reset_postdata();

get_posts(['tag' => 'php', 'per_page' => 5]);   // mảng bài, không cần vòng lặp
```

Tham số: `type` (post | page | any), `per_page` (-1 = tất cả), `page`, `offset`, `include`, `exclude`, `category`, `tag`, `tax` (`['actor' => 'slug']`), `include_children`, `author`, `search`, `year`/`month`/`day`, `parent`, `meta` (`['key' => 'featured', 'value' => true]`), `orderby` (date | modified | title | menu_order | id | rand), `order`.

### Hàm template

`get_*()` trả về giá trị thô, `the_*()` in ra đã escape (riêng `the_content()` in HTML đã làm sạch lúc lưu). Hàm nhận `$post` thì `null` = bài hiện tại, số = ID, mảng = bản ghi.

| Nhóm | Hàm |
|---|---|
| Vòng lặp | `have_posts` `the_post` `rewind_posts` `lq_query` `get_posts` `lq_reset_postdata` `setup_postdata` `lq_main_query` (found, page, maxPages) |
| Bài | `get_post` `the_ID` `the_title` `the_permalink` `get_permalink` `the_content` `the_excerpt` `the_date` `the_time` `the_modified_date` `get_reading_time` `get_field`/`the_field` (field tuỳ biến) |
| Ảnh | `has_post_thumbnail` `the_post_thumbnail($attrs)` `get_the_post_thumbnail_url` (có sẵn width/height, alt từ thư viện media) |
| Tác giả | `the_author` `the_author_link` `get_author_posts_url` `author_url` |
| Phân loại | `get_the_terms($tax)` `the_terms($tax, $sep, $before, $after)` `the_category` `the_tags` `has_term` `get_terms($tax, $args)` `get_term` `get_term_link` |
| Trang | `get_pages(['parent' => 0])` `get_post_ancestors` `get_page_template_slug` |
| Điều hướng | `get_previous_post` `get_next_post` `get_adjacent_post` `get_related_posts($post, $limit, $args)` (chấm điểm theo thẻ/chuyên mục/taxonomy dùng chung) `the_posts_pagination` `paginate_links` (dữ liệu để tự vẽ) `get_pagenum_link` |
| Điều kiện | `is_home` `is_front_page` `is_single` `is_page` `is_singular` `is_archive` `is_category` `is_tag` `is_tax` `is_author` `is_date` `is_year` `is_month` `is_day` `is_search` `is_404` `is_paged` `is_preview` `is_page_template` `is_logged_in` — nhận id/slug để hỏi cụ thể, vd. `is_category('tin-tuc')` |
| Trang đang xem | `get_queried_object` `get_queried_object_id` `the_archive_title` `the_archive_description` `get_document_title` `lq_canonical_url` |
| Website & theme | `home_url` `bloginfo('name' / 'description' / 'url' / 'charset' / 'language' / 'version')` `theme_url('style.css')` `get_header` `get_footer` `get_sidebar` `get_template_part($slug, $name, $args)` `get_search_form` `the_search_query` `body_class` `post_class` `edit_post_link` `get_current_template` |
| Hook trong theme | `lq_script()` trong `<head>` (chỗ cho lõi/plugin chèn script, CSS…), `lq_body_open()` sau `<body>`, `lq_footer()` trước `</body>` |
| Thẻ `<head>` | Theme tự viết `<title>`, meta, canonical, Open Graph… — dùng `get_document_title()` `lq_meta_description($length)` (field seo_description → tóm tắt → mô tả chuyên mục → khẩu hiệu) `lq_canonical_url()`; xem `header.php` của lq-default |

**Hook frontend:** `template_redirect`, `main_query` (filter), `template_hierarchy`, `template_include`, `show_admin_bar`, `admin_bar_items`, `lq_script`, `lq_body_open`, `lq_footer`, và các filter `the_title`, `the_content`, `get_the_excerpt`, `excerpt_length`, `excerpt_more`, `post_link`, `related_posts`, `archive_title`, `document_title_parts`, `document_title_separator`, `pre_get_document_title`, `canonical_url`, `meta_description`, `body_class`, `post_class`, `site_language`.

### AJAX

Mỗi action là một URL `/ajax/{action}`. `lq_script()` in sẵn `window.LQ` có `LQ.get(action, params)` và `LQ.post(action, data)` (trả Promise; tự gửi token CSRF và cookie; lỗi thì reject với `e.message`, `e.status`). Tắt bằng `add_filter('lq_ajax_script', fn () => false)`.

Đăng ký action trong `functions.php` của theme hoặc trong plugin:

```php
lq_add_ajax('like', function (array $in) {
    $id = (int) ($in['id'] ?? 0) ?: lq_ajax_error('Thiếu bài viết.', 422); // -> {ok: false, message}, HTTP 422
    $n = (int) post_meta($id, 'likes', 0) + 1;
    update_post_meta($id, 'likes', $n);
    return ['likes' => $n];                                             // -> {ok: true, likes: 3}
}, ['public' => true]);
```

```js
LQ.post('like', { id: 12 }).then(r => btn.textContent = r.likes).catch(e => alert(e.message));
```

Tuỳ chọn: `public` (khách gọi được, mặc định phải đăng nhập), `method` (`POST` mặc định | `GET` | `ANY`), `cap` (quyền cần có), `csrf` (mặc định kiểm với mọi request không phải GET), `rate` (lượt/phút/IP, mặc định 20 cho action public không phải GET; vượt thì 429). Callback trả mảng → JSON `{ok: true, ...}`, chuỗi → HTML, `null` → `{ok: true}`. Đăng ký lại cùng tên để thay action có sẵn.

Action có sẵn (GET, công khai, chỉ bài đã đăng):

| Action | Dùng cho | Tham số |
|---|---|---|
| `posts` | "Xem thêm bài", lọc danh sách | `type` `page` `per_page` (≤ 50) `category` `tag` `tax[key]` `author` `search` `year` `month` `day` `parent` `exclude` `orderby` (date \| modified \| title \| menu_order) `order`; `format=html` (mặc định, mỗi bài vẽ bằng `parts/card.php` của theme, trả trong `html`) hoặc `format=json` (trả `posts`); `part` chọn template part khác — phải được cho phép qua filter `ajax_template_parts` |
| `search` | gợi ý khi gõ ô tìm kiếm | `q`, `limit` (≤ 10) → `posts`, `terms` (chuyên mục/thẻ/taxonomy khớp tên), `found`, `more_url` |

Cả hai trả kèm `page`, `found`, `max_pages`, `has_more` (riêng `posts`). `lq_ajax_query_args()` cho ra điều kiện của trang đang xem (chuyên mục, tác giả, từ khoá…) để gửi lại cho `posts`, xem `parts/load-more.php` + `assets/theme.js` trong theme lq-default. Filter: `ajax_posts_args`, `ajax_post_data` (thêm field cho `format=json`), `ajax_template_parts`, `ajax_search_terms`.

## Viết plugin

```
content/plugins/ten-plugin/
  ten-plugin.php  tệp chính, đầu tệp có khối "Plugin Name: ..."
  uninstall.php   (tuỳ chọn) chạy khi xoá plugin
  icon.svg        (tuỳ chọn) logo riêng — cũng nhận icon.png / icon.webp / icon.jpg
```

Icon cạnh tên trong danh sách plugin: có tệp `icon.*` thì dùng ảnh đó, không thì dùng `Icon:` (tên icon [Lucide](https://lucide.dev/icons), vd. `cloud`, `smile`), không khai báo thì là mảnh ghép mặc định.

```php
<?php
/**
 * Plugin Name: Tên plugin
 * Description: Mô tả
 * Version: 1.0.0
 * Author: Bạn
 * Icon: cloud
 */
defined('LQ_ROOT') || exit;

// Widget cho Bảng tin: người dùng kéo vào từ ngăn "Thêm widget" (chế độ Tuỳ chỉnh).
lq_add_dashboard_widget('ten-plugin', 'Tiêu đề widget', function (int $size) {
    echo '<section class="tile"><div class="tile-title"><div><h3>Tiêu đề</h3></div></div>…</section>';
}, ['description' => 'Mô tả ngắn', 'icon' => 'puzzle', 'size' => 4, 'sizes' => [3, 4, 6], 'default' => false]);
add_filter('content_save_pre', fn ($html) => $html);
lq_add_admin_page('ten-plugin', 'Tên plugin', function () { echo '...'; }, 'puzzle', 'manage_options', 'từ khoá nội dung trang cho ⌘K/AI');
// Việc trợ lý AI được làm (AI không tự gọi hàm nào khác của plugin); 'write' => true thì người dùng bấm Chạy mới thực hiện.
lq_add_ai_tool('ten_plugin_stats', ['description' => 'Thống kê của plugin', 'run' => fn () => ['so_luot' => 123]]);
add_action('activate_ten-plugin', function () { /* chạy khi kích hoạt */ });
```

Xem plugin mẫu `hello-lq` để biết thêm.

**Hook có sẵn:** `post_published`, `post_revision_restored`, `cron_cleanup`, `post_field_validate` (filter), `post_meta_saved`, `init`, `admin_head`, `admin_footer`, `admin_menu` (filter), `admin_routes`, `role_caps` (filter), `content_save_pre` (filter), `post_saved`, `post_trashed`, `post_restored`, `before_post_deleted`, `term_saved`, `term_deleted`, `user_login`, `user_logout`, `user_registered`, `image_uploaded`, `theme_switched`, `theme_file_saved`, `settings_saved`, `settings_general_fields`, `activate_{slug}`, `deactivate_{slug}`.

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
the_terms('actor', ', ');          // trong template; hoặc get_the_terms('actor') / post_terms($postId, 'actor')
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
- Token CSRF cho mọi request POST (kể cả AJAX, qua header `X-CSRF-Token`). Action AJAX công khai bị giới hạn số lượt mỗi phút theo IP; action `posts`/`search` chỉ trả bài đã đăng và không nhận tham số `meta`/`status`/`include`. Cookie session `HttpOnly` + `SameSite=Lax` (bật `Secure` khi chạy HTTPS).
- Mật khẩu băm bằng `password_hash`. Khoá đăng nhập 15 phút sau 5 lần sai cho mỗi IP. "Ghi nhớ đăng nhập" dùng cặp selector/validator, DB chỉ lưu hash.
- Upload: kiểm tra loại file theo nội dung thật (finfo + getimagesize), không tin phần mở rộng.
- Cài theme/plugin từ zip: chặn zip slip, bắt buộc đúng một thư mục gốc.
- Chặn chạy trực tiếp file PHP trong `content/` (nginx, `.htaccess` và cả dev server).
- Plugin gây lỗi khi nạp sẽ tự bị tắt để admin không bị sập theo.
