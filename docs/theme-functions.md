# Hàm dùng trong theme LQCMS

Tài liệu tra cứu mọi hàm mà tệp template (`index.php`, `single.php`, `header.php`…) và `functions.php` của theme gọi được. Tên hàm đặt theo WordPress cho quen tay, nhưng chỉ những gì liệt kê ở đây mới có.

## Quy ước chung

- **`get_*()` trả giá trị THÔ** (chưa escape). Tự in thì bọc `e()`: `<?= e(get_the_title()) ?>`.
- **`the_*()` in ra đã escape.** Riêng `the_content()` in nguyên HTML vì nội dung đã được làm sạch lúc lưu.
- **Tham số `$post`**: `null` = bài hiện tại trong vòng lặp, số = ID bài, mảng = bản ghi bài (kết quả của `get_post()`, `get_posts()`…).
- **Ngày giờ** lưu ở DB theo UTC; các hàm ngày tự đổi sang múi giờ trong Cài đặt.
- Mỗi tệp theme nên bắt đầu bằng `<?php defined('LQ_ROOT') || exit; ?>` để không chạy được khi gọi thẳng URL.
- Trong mọi tệp template có sẵn biến `$args` (mảng truyền qua `get_template_part()`) và `$lq` (`path`, `site_title`, `tagline`, `theme_url`).

---

## Cấu trúc theme

```
content/themes/ten-theme/
├── theme.json          # bắt buộc: name, description, version, author, screenshot
├── index.php           # bắt buộc: template cuối cùng của mọi trang
├── functions.php       # tuỳ chọn: hook, field, taxonomy, mẫu trang… nạp trước khi hiển thị
├── header.php / footer.php / sidebar.php / searchform.php
├── front-page.php, home.php, single.php, page.php, archive.php, search.php, 404.php…
└── parts/…             # template part, gọi bằng get_template_part()
```

`theme.json`:

```json
{
  "name": "Tên theme",
  "description": "Mô tả ngắn",
  "version": "1.0.0",
  "author": "Tên bạn",
  "screenshot": "screenshot.svg"
}
```

### Thứ bậc template

Hệ thống thử từng tệp theo thứ tự, gặp tệp nào có trước thì dùng tệp đó; cuối cùng luôn là `index.php`.

| Trang đang xem | Thứ tự tìm tệp |
|---|---|
| 404 | `404.php` |
| Tìm kiếm | `search.php` |
| Trang tĩnh | `front-page.php` (nếu là trang chủ) → tệp của mẫu trang → `page-{slug}.php` → `page-{id}.php` → `page.php` → `singular.php` |
| Bài viết | `single-{slug}.php` → `single.php` → `singular.php` |
| Loại nội dung tuỳ biến | `single-{loại}-{slug}.php` → `single-{loại}.php` → `single.php` → `singular.php` |
| Danh sách loại tuỳ biến (`/{base}/`) | `archive-{loại}.php` → `archive.php` |
| Trang bài mới nhất | `front-page.php` (nếu ở `/`) → `home.php` |
| Chuyên mục | `category-{slug}.php` → `category-{id}.php` → `category.php` → `archive.php` |
| Thẻ | `tag-{slug}.php` → `tag-{id}.php` → `tag.php` → `archive.php` |
| Taxonomy tuỳ biến | `taxonomy-{tax}-{slug}.php` → `taxonomy-{tax}.php` → `taxonomy.php` → `archive.php` |
| Tác giả | `author-{username}.php` → `author-{id}.php` → `author.php` → `archive.php` |
| Theo ngày | `date.php` → `archive.php` |

Đổi danh sách bằng filter `template_hierarchy`, đổi tệp cuối cùng bằng filter `template_include`.

---

## Khung trang: `<head>` và các điểm chèn

LQCMS **không tự in** `<title>`, meta, canonical hay Open Graph — theme tự viết. Lõi chỉ cung cấp dữ liệu và 3 điểm chèn cho plugin.

| Hàm | Đặt ở đâu | Làm gì |
|---|---|---|
| `lq_script()` | Trong `<head>`, thường ngay trước `</head>` | In `window.LQ` (AJAX) rồi chạy hook `lq_script` để plugin/theme chèn script, CSS, thẻ meta… **Bắt buộc gọi** nếu muốn plugin và AJAX hoạt động. |
| `lq_body_open()` | Ngay sau `<body>` | Chạy hook `lq_body_open` (Google Tag Manager…) |
| `lq_footer()` | Ngay trước `</body>` | Chạy hook `lq_footer` (script cuối trang) |

### Thanh quản trị

Người đã đăng nhập vào được admin sẽ thấy thanh quản trị màu đen ở mép trên trang web (không có trong trang admin): Bảng tin, tạo mới, sửa bài/trang/chuyên mục đang xem, tệp template đang dùng, hồ sơ và đăng xuất. Lõi tự gắn qua `lq_script()` và `lq_footer()`, thêm class `admin-bar` cho `<body>` và đẩy trang xuống bằng `margin-top` của `<html>`.

Phần tử `position: sticky` / `fixed` bám mép trên của theme phải chừa chỗ cho thanh:

```css
.site-header { position: sticky; top: var(--lq-admin-bar, 0px); }
```

| Hàm / hook | Mô tả |
|---|---|
| `is_admin_bar_showing(): bool` | Thanh có hiện ở trang này không |
| filter `show_admin_bar` | Trả `false` để tắt thanh |
| `lq_add_admin_bar_item($id, $args)` / `lq_remove_admin_bar_item($id)` | Thêm, bỏ mục — tuỳ chọn xem [plugin-api.md](plugin-api.md#thanh-quản-trị-trên-trang-web) |
| filter `admin_bar_items` | Sửa cả danh sách mục |

Mỗi tài khoản tự tắt được ở **Hồ sơ › Thanh quản trị**.

Dữ liệu để tự viết thẻ head:

| Hàm | Trả về |
|---|---|
| `get_document_title(): string` | Nội dung `<title>`: "Tiêu đề bài – Tên website", "Chuyên mục – Trang 2 – Tên website"… |
| `lq_meta_description(int $length = 300): string` | Mô tả trang: field `seo_description` → tóm tắt bài → mô tả chuyên mục/thẻ → khẩu hiệu website |
| `lq_canonical_url(): string` | URL chuẩn của trang (rỗng với 404 và tìm kiếm) |
| `get_the_post_thumbnail_url($post)` | Ảnh cho `og:image` |

Mẫu `header.php` tối thiểu:

```php
<?php defined('LQ_ROOT') || exit; ?>
<!doctype html>
<html lang="<?php bloginfo('language'); ?>">
<head>
  <meta charset="<?php bloginfo('charset'); ?>">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e(get_document_title()) ?></title>
  <?php if (($desc = lq_meta_description()) !== ''): ?>
    <meta name="description" content="<?= e($desc) ?>">
  <?php endif; ?>
  <?php if (($canonical = lq_canonical_url()) !== ''): ?>
    <link rel="canonical" href="<?= e($canonical) ?>">
  <?php endif; ?>
  <?php if (is_404() || is_search() || is_preview()): ?>
    <meta name="robots" content="noindex">
  <?php endif; ?>
  <link rel="stylesheet" href="<?= e(theme_url('style.css')) ?>">
  <?php lq_script(); ?>
</head>
<body<?php body_class(); ?>>
<?php lq_body_open(); ?>
```

Và `footer.php`:

```php
<?php lq_footer(); ?>
</body>
</html>
```

Bản đầy đủ (có Open Graph) xem `header.php` của theme `lq-default`.

---

## Nạp tệp con

| Hàm | Mô tả |
|---|---|
| `get_header(?string $name = null, array $args = [])` | Nạp `header-{name}.php` rồi `header.php` |
| `get_footer(?string $name = null, array $args = [])` | Nạp `footer-{name}.php` rồi `footer.php` |
| `get_sidebar(?string $name = null, array $args = [])` | Nạp `sidebar-{name}.php` rồi `sidebar.php` |
| `get_template_part(string $slug, ?string $name = null, array $args = []): bool` | `get_template_part('parts/card', 'compact', ['size' => 'lg'])` thử `parts/card-compact.php` rồi `parts/card.php`; trả `false` nếu không có tệp nào. Tệp được nạp đọc tham số qua `$args`. |
| `get_search_form()` | In `searchform.php` của theme, không có thì in form mặc định |
| `get_current_template(): string` | Tệp template đang dùng, vd. `single.php` — tiện gỡ lỗi |

---

## Vòng lặp

```php
<?php if (have_posts()): ?>
  <?php while (have_posts()): the_post(); ?>
    <article<?php post_class(); ?>>
      <h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
      <?php the_excerpt(); ?>
    </article>
  <?php endwhile; ?>
  <?php the_posts_pagination(); ?>
<?php else: ?>
  <p>Chưa có bài viết.</p>
<?php endif; ?>
```

| Hàm | Mô tả |
|---|---|
| `have_posts(): bool` | Còn bài trong truy vấn chính không |
| `the_post(): void` | Chuyển sang bài kế tiếp, đặt làm "bài hiện tại" |
| `rewind_posts(): void` | Quay lại đầu vòng lặp chính |
| `lq_main_query(): Query` | Truy vấn chính của trang. Thuộc tính: `found` (tổng số bài), `page`, `perPage`, `maxPages`, `posts`, `queried` |

### Truy vấn riêng

```php
<?php $q = lq_query(['category' => 'tin-tuc', 'per_page' => 5]); ?>
<?php while ($q->have_posts()): $q->the_post(); ?>
  <a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
<?php endwhile; lq_reset_postdata(); ?>
```

| Hàm | Mô tả |
|---|---|
| `lq_query(array $args = []): Query` | Truy vấn riêng, lặp bằng `$q->have_posts()` / `$q->the_post()` / `$q->rewind_posts()` |
| `get_posts(array $args = []): array` | Như `lq_query()` nhưng trả thẳng mảng bài (không đếm tổng) — dùng với `foreach` + `setup_postdata()` hoặc truyền `$post` vào các hàm |
| `lq_reset_postdata(): void` | **Gọi sau khi lặp truy vấn riêng** để "bài hiện tại" trở về bài của trang đang xem |
| `setup_postdata(array\|int $post): void` | Đặt một bài làm bài hiện tại để dùng `the_title()`, `the_permalink()`… |

Tham số của `lq_query()` / `get_posts()` (đều tuỳ chọn):

| Tham số | Giá trị |
|---|---|
| `type` | `'post'` (mặc định) \| `'page'` \| `'any'` \| `['post', 'page']` |
| `per_page` | Số bài mỗi trang, `-1` = tất cả (mặc định theo Cài đặt) |
| `page` | Trang thứ mấy, từ 1 |
| `offset` | Bỏ qua N bài đầu (khi có thì bỏ qua `page`) |
| `include` / `exclude` | `[id, …]` chỉ lấy / bỏ các bài này |
| `category` | id \| slug \| mảng — gồm cả chuyên mục con (`'include_children' => false` để tắt) |
| `tag` | id \| slug \| mảng |
| `tax` | `['actor' => 'slug' \| id \| […], …]` — taxonomy bất kỳ, nhiều điều kiện thì AND |
| `author` | id \| tên đăng nhập |
| `search` | Từ khoá (tiêu đề + nội dung; gõ không dấu vẫn khớp tiêu đề) |
| `year`, `month`, `day` | Lọc theo ngày đăng |
| `parent` | id trang cha (`0` = trang cấp gốc) |
| `meta` | `['key' => 'ten_field', 'value' => giá trị]` — so khớp chính xác |
| `orderby` | `'date'` \| `'modified'` \| `'title'` \| `'menu_order'` \| `'id'` \| `'rand'` |
| `order` | `'DESC'` (mặc định) \| `'ASC'` |

---

## Bài viết

| Hàm | Mô tả |
|---|---|
| `get_post($post = null): ?array` | Bản ghi bài. Bài chưa đăng chỉ trả về cho người có quyền sửa. Có thêm `author_name`, `author_username`, `terms[taxonomy]` |
| `get_the_ID(): int` / `the_ID()` | ID bài hiện tại |
| `get_the_title($post = null): string` | Tiêu đề (filter `the_title`); rỗng thì là "(Không có tiêu đề)" |
| `the_title(string $before = '', string $after = '')` | In tiêu đề, kèm `$before`/`$after` (HTML, không escape) |
| `get_permalink($post = null): string` / `the_permalink($post = null)` | Đường dẫn bài (filter `post_link`) |
| `get_the_content($post = null): string` | Nội dung thô |
| `the_content()` | In nội dung đã qua filter `the_content` (tự bọc `<p>` cho đoạn văn) |
| `get_the_excerpt($post = null, ?int $words = null): string` | Tóm tắt cắt từ nội dung, mặc định 40 từ (filter `excerpt_length`, `excerpt_more`, `get_the_excerpt`) |
| `the_excerpt(?int $words = null)` | In tóm tắt |
| `get_the_date(string $format = '', $post = null)` / `the_date(string $format = '')` | Ngày đăng; `$format` theo `date()` của PHP, bỏ trống = định dạng trong Cài đặt. Dùng `'c'` cho `<time datetime>` |
| `get_the_time(string $format = '', $post = null)` / `the_time(string $format = '')` | Giờ đăng |
| `get_the_modified_date(string $format = '', $post = null)` / `the_modified_date(string $format = '')` | Ngày sửa gần nhất |
| `get_reading_time($post = null): int` | Số phút đọc ước tính (~220 từ/phút) |
| `edit_post_link(string $text = 'Sửa', string $before = '', string $after = '', $post = null)` | Link tới trang sửa — chỉ hiện với người có quyền |
| `lq_autop(string $text): string` | Bọc đoạn văn bằng `<p>`, xuống dòng đơn thành `<br>` |

Các cột hay dùng trong bản ghi bài: `id`, `type` (`post`/`page`), `title`, `slug`, `content`, `status`, `featured_image`, `author_id`, `parent_id`, `menu_order`, `template`, `published_at`, `created_at`, `updated_at`.

### Field tuỳ biến

| Hàm | Mô tả |
|---|---|
| `get_field(string $key, mixed $default = null, $post = null): mixed` | Giá trị field (đăng ký bằng `lq_add_post_field`) |
| `the_field(string $key, $post = null)` | In field đã escape; mảng thì nối bằng dấu phẩy |
| `post_meta(int $postId, string $key, mixed $default = null): mixed` | Đọc meta bất kỳ theo ID bài |
| `update_post_meta(int $postId, string $key, mixed $value)` | Ghi meta |

Field có sẵn của lõi: `seo_description` (mô tả SEO).

---

## Ảnh đại diện

| Hàm | Mô tả |
|---|---|
| `has_post_thumbnail($post = null): bool` | Bài có ảnh đại diện không |
| `get_the_post_thumbnail_url($post = null): string` | URL tuyệt đối của ảnh |
| `get_the_post_thumbnail($post = null, array $attrs = []): string` | Thẻ `<img>` có sẵn `width`, `height`, `alt` (lấy từ thư viện media), `loading="lazy"` |
| `the_post_thumbnail(array $attrs = [])` | In thẻ `<img>` của bài hiện tại. `$attrs` ghi đè thuộc tính: `['class' => 'cover', 'loading' => 'eager']` |
| `html_attrs(array $attrs): string` | `['class' => 'a', 'hidden' => true, 'x' => null]` → ` class="a" hidden` |

---

## Tác giả

| Hàm | Mô tả |
|---|---|
| `get_the_author($post = null): string` / `the_author()` | Tên hiển thị của tác giả |
| `get_author_posts_url($post = null): string` | Trang bài của tác giả (`/tac-gia/username/`) |
| `the_author_link()` | In tên tác giả có link |
| `author_url(array\|string $user): string` | URL trang tác giả từ bản ghi người dùng hoặc tên đăng nhập |

---

## Chuyên mục, thẻ, taxonomy

Mỗi term là mảng có `id`, `name`, `slug`, `taxonomy`, `description`, `parent_id`, `url`.

| Hàm | Mô tả |
|---|---|
| `get_the_terms(string $taxonomy, $post = null): array` | Term của bài theo taxonomy |
| `the_terms(string $taxonomy, string $sep = ', ', string $before = '', string $after = '')` | In danh sách link: `the_terms('actor', ', ', 'Diễn viên: ')` |
| `get_the_category($post = null): array` / `the_category(string $sep = ', ')` | Chuyên mục của bài |
| `get_the_tags($post = null): array` / `the_tags(string $before = '', string $sep = ', ', string $after = '')` | Thẻ của bài |
| `has_term(string\|int $term, string $taxonomy, $post = null): bool` | Bài có term này (id hoặc slug) không |
| `post_terms(int $postId, string $taxonomy): array` | Term của bài theo ID |
| `get_term(int\|string $term, string $taxonomy = 'category'): ?array` | Một term theo id hoặc slug |
| `get_term_link(array\|int $term): string` | URL trang lưu trữ của term |
| `get_terms(string $taxonomy, array $args = []): array` | Danh sách term, kèm `count` (số bài đã đăng) và `url` |

Tham số `get_terms()`: `hide_empty` (mặc định `true`), `parent` (`null` = mọi cấp, `0` = cấp gốc, id), `orderby` (`'name'` \| `'count'`), `number` (`0` = tất cả), `exclude` (`[id, …]`).

---

## Trang tĩnh

| Hàm | Mô tả |
|---|---|
| `get_pages(array $args = []): array` | Trang đã đăng, xếp theo thứ tự rồi tên, mỗi trang có `url`. `$args`: `parent`, `exclude`, `include` — hay dùng để dựng menu |
| `get_post_ancestors($post = null): array` | Trang cha, ông… từ gốc xuống (dùng cho breadcrumb) |
| `get_page_template_slug($post = null): string` | Key mẫu trang đang dùng (`''` = mặc định) |

---

## Menu điều hướng

Theme khai báo vị trí trong `functions.php`, người quản trị dựng menu ở **Giao diện › Menu** (kéo thả lồng cấp: trang, bài, chuyên mục, thẻ, loại nội dung tuỳ biến, link tuỳ chỉnh) rồi gán vào vị trí. Menu lưu theo tên nên đổi theme không mất.

```php
// functions.php
lq_register_nav_menu('primary', 'Menu chính');
lq_register_nav_menus(['primary' => 'Menu chính', 'footer' => 'Chân trang']); // nhiều vị trí

// header.php
<nav aria-label="Menu chính">
  <?php lq_nav_menu(['location' => 'primary', 'class' => 'menu', 'depth' => 2, 'fallback' => fn () => '<a href="/">Trang chủ</a>']); ?>
</nav>
```

| Hàm | Mô tả |
|---|---|
| `lq_nav_menu(array $args): string` | In `<ul class="menu">`: mỗi mục là `li.menu-item` (+ `is-current`, `is-current-ancestor`, `has-children`, class tuỳ chỉnh), menu con là `ul.sub-menu`, mục đang xem có `aria-current="page"`. `$args`: `location`, `class`, `id`, `depth` (0 = mọi cấp), `fallback` (callable khi vị trí chưa có menu), `echo` (false = chỉ trả chuỗi) |
| `get_nav_menu_items(string $location): array` | Cây mục để tự vẽ: `label`, `url`, `new_tab`, `current`, `current_ancestor`, `type`, `classes`, `children` |
| `has_nav_menu(string $location): bool` | Vị trí đã được gán menu có mục chưa |

Mục trỏ tới bài chưa đăng / đã xoá, chuyên mục đã xoá tự ẩn (mục con của nó lên thay). Filter `nav_menu_items` (`array $items`, `string $location`) để sửa cây trước khi in.

---

## Bài trước / sau & bài liên quan

| Hàm | Mô tả |
|---|---|
| `get_previous_post(bool $sameCategory = false): ?array` | Bài cũ hơn liền kề |
| `get_next_post(bool $sameCategory = false): ?array` | Bài mới hơn liền kề |
| `get_adjacent_post(bool $previous = true, bool $sameCategory = false, $post = null): ?array` | Dạng tổng quát |

### Bài liên quan

`get_related_posts($post = null, int $limit = 3, array $args = []): array` — chấm điểm các bài cùng loại theo term dùng chung với bài đang xem: mỗi term trùng cộng trọng số của taxonomy đó, xếp theo điểm rồi mới nhất trước. Thiếu bài thì bù bằng bài mới nhất cùng chuyên mục (kể cả chuyên mục con). Bài không có term nào trả `[]`.

| Tuỳ chọn | Mặc định | Ý nghĩa |
|---|---|---|
| `weights` | thẻ `3`, taxonomy tuỳ biến `2`, chuyên mục `1` | Ghi đè từng taxonomy: `['actor' => 5, 'category' => 0]` (`0` = bỏ qua) |
| `fallback` | `true` | `false` để không bù bằng bài cùng chuyên mục |
| `exclude` | `[]` | Bỏ thêm các bài này (bài đang xem luôn bị bỏ) |

```php
<?php $related = get_related_posts(null, 3, ['weights' => ['actor' => 4]]); ?>
<?php if ($related): ?>
  <?php foreach ($related as $item): setup_postdata($item); ?>
    <?php get_template_part('parts/card'); ?>
  <?php endforeach; lq_reset_postdata(); ?>
<?php endif; ?>
```

Ví dụ với trọng số mặc định: bài chung 2 thẻ (6 điểm) đứng trước bài chung 1 thẻ + 1 chuyên mục (4 điểm), rồi đến bài chỉ chung chuyên mục (1 điểm). Kết quả sửa được qua filter `related_posts`.

### Ví dụ bài trước / sau

```php
<?php if ($prev = get_previous_post()): ?>
  <a href="<?= e(get_permalink($prev)) ?>">← <?= e(get_the_title($prev)) ?></a>
<?php endif; ?>
```

---

## Câu hỏi điều kiện

| Hàm | Đúng khi |
|---|---|
| `is_front_page()` | Đang ở địa chỉ gốc `/` (dù hiện bài mới nhất hay trang tĩnh) |
| `is_home()` | Trang danh sách bài mới nhất |
| `is_single($post = null)` | Trang một bài viết; nhận id/slug/mảng để hỏi cụ thể |
| `is_page($page = null)` | Trang tĩnh; nhận id/slug/mảng |
| `is_singular(?string $type = null)` | Bài hoặc trang đơn; `'post'`/`'page'` để hỏi cụ thể |
| `is_page_template(?string $template = null)` | Trang dùng mẫu trang (bất kỳ hoặc key cụ thể) |
| `is_archive()` | Mọi trang lưu trữ (chuyên mục, thẻ, taxonomy, tác giả, ngày, danh sách loại tuỳ biến) |
| `is_post_type_archive(?string $type = null)` | Trang danh sách của loại nội dung tuỳ biến: `is_post_type_archive('su_kien')` |
| `is_route(?string $pattern = null)` | Đang ở một đường dẫn riêng (`lq_add_route`); `get_route_param('slug')` lấy tham số |
| `is_category($category = null)` | Trang chuyên mục: `is_category('tin-tuc')` |
| `is_tag($tag = null)` | Trang thẻ |
| `is_tax(?string $taxonomy = null, $term = null)` | Taxonomy tuỳ biến: `is_tax('actor', 'ten-dien-vien')` |
| `is_author($author = null)` | Trang tác giả; nhận id hoặc tên đăng nhập |
| `is_date()` / `is_year()` / `is_month()` / `is_day()` | Lưu trữ theo ngày |
| `is_search()` | Trang kết quả tìm kiếm |
| `is_404()` | Không tìm thấy trang |
| `is_paged()` | Trang 2 trở đi của một danh sách |
| `is_preview()` | Người có quyền đang xem trước bài chưa đăng |
| `is_logged_in()` | Khách đã đăng nhập |

---

## Trang đang xem

| Hàm | Mô tả |
|---|---|
| `get_queried_object(): ?array` | Bài/trang, term (có `taxonomy`), tác giả (có `username`, `display_name`) hoặc `['year','month','day']` — tuỳ trang |
| `get_queried_object_id(): int` | ID của đối tượng đó |
| `get_the_archive_title(): string` / `the_archive_title(string $before = '', string $after = '')` | "Chuyên mục: Tin tức", "Tác giả: An", "Tháng 10/2026"… (filter `archive_title`) |
| `get_the_archive_description(): string` / `the_archive_description(string $before = '', string $after = '')` | Mô tả chuyên mục/thẻ/term đang xem |
| `get_body_class(array\|string $extra = []): array` | Class cho `<body>`: `home`, `single`, `postid-12`, `category-tin-tuc`, `paged-2`, `logged-in`… |
| `body_class(array\|string $extra = [])` | In ` class="…"` — dùng `<body<?php body_class(); ?>>` |
| `post_class(array\|string $extra = [], $post = null)` | In ` class="post-12 type-post has-thumbnail category-tin-tuc…"` |

---

## Tìm kiếm & phân trang

| Hàm | Mô tả |
|---|---|
| `get_search_query(): string` / `the_search_query()` | Từ khoá đang tìm (`?s=`) |
| `the_posts_pagination(array $args = [])` | In `<nav class="pagination">` có sẵn. `$args`: `prev_text`, `next_text`, `mid_size`, `end_size`, `query` |
| `paginate_links(array $args = []): array` | Dữ liệu phân trang để tự vẽ: mỗi mục `['type' => prev\|next\|page\|dots, 'url', 'number', 'current']`. `$args`: `mid_size` (2), `end_size` (1), `query` (mặc định truy vấn chính) |
| `get_pagenum_link(int $n): string` | URL trang số `$n` của danh sách đang xem (giữ `?s=`) |

---

## Website & URL

| Hàm | Mô tả |
|---|---|
| `get_bloginfo(string $show = 'name'): string` / `bloginfo(string $show = 'name')` | `name`, `description` (khẩu hiệu), `url`, `charset`, `language`, `version`, `admin_email` |
| `home_url(string $path = ''): string` / `site_url(string $path = '')` | URL tuyệt đối trên website: `home_url('/lien-he/')` |
| `theme_url(string $path = ''): string` | URL tệp trong theme, tự gắn `?v=<thời điểm sửa>` để trình duyệt nạp bản mới: `theme_url('assets/theme.js')` |
| `admin_url(string $path = ''): string` | URL trang quản trị |
| `request_path(): string` | Đường dẫn đang truy cập, vd. `/tin-tuc/` |
| `is_https(): bool` | Request qua HTTPS không |

---

## AJAX

`lq_script()` in sẵn đối tượng `window.LQ` cho JavaScript:

```js
LQ.get('posts', {category: 'tin-tuc', page: 2}).then(r => { /* r.html, r.has_more */ });
LQ.post('vote', {id: 5}).then(r => r.count).catch(e => alert(e.message));
```

`LQ.get` / `LQ.post` trả Promise, tự gửi token CSRF và cookie; lỗi thì reject với `e.message`, `e.status`. Tắt hẳn bằng `add_filter('lq_ajax_script', fn () => false)`.

| Hàm PHP | Mô tả |
|---|---|
| `lq_ajax_url(string $action = ''): string` | URL của action: `lq_ajax_url('posts')` → `/ajax/posts` |
| `lq_ajax_query_args(?Query $q = null): array` | Tham số của truy vấn chính (chuyên mục, thẻ, từ khoá…) để gửi cho action `posts` — nút "Tải thêm" lấy đúng các bài tiếp theo |
| `lq_add_ajax(string $action, callable $callback, array $opts = [])` | Đăng ký action `/ajax/{action}` (xem phần functions.php) |
| `lq_ajax_error(string $message, int $status = 400, array $extra = []): never` | Dừng callback và trả `{ok: false, message}` |

Action có sẵn của lõi (công khai, GET):

- **`posts`** — danh sách bài. Tham số giống `lq_query()` (`type`, `page`, `per_page` ≤ 50, `category`, `tag`, `tax[key]`, `author`, `search`, `year`, `month`, `day`, `parent`, `exclude`, `orderby`, `order`) cộng thêm `format` (`html` mặc định \| `json`) và `part` (template part để vẽ, mặc định `parts/card`). Trả `page`, `per_page`, `found`, `max_pages`, `has_more` và `html` hoặc `posts`.
- **`search`** — gợi ý tìm kiếm. Tham số `q`, `limit` (≤ 10). Trả `posts`, `terms`, `found`, `more_url`.

Ví dụ nút "Tải thêm":

```php
<button data-load-more data-args="<?= e(json_encode(lq_ajax_query_args())) ?>"
        data-page="<?= lq_main_query()->page ?>">Xem thêm</button>
```

---

## Tiện ích chung

| Hàm | Mô tả |
|---|---|
| `e(mixed $value): string` | Escape HTML — **dùng cho mọi dữ liệu tự in** |
| `format_date(?string $utc, ?string $format = null): string` | Đổi chuỗi ngày UTC sang giờ website |
| `option(string $name, mixed $default = null): mixed` | Đọc cài đặt: `option('site_title')`, `option('posts_per_page')`… |
| `update_option(string $name, mixed $value, bool $autoload = true)` | Ghi cài đặt (dùng trong `functions.php`, không gọi khi hiển thị trang) |
| `current_user(): ?array` | Người đang đăng nhập (`null` nếu là khách) |
| `can(string $cap): bool` | Người đang đăng nhập có quyền này không, vd. `can('edit_posts')` |
| `csrf_token(): string` / `csrf_field(): string` | Token CSRF / ô `<input type="hidden">` cho form POST |
| `selected($a, $b)` / `checked($a, $b = true)` | In ` selected` / ` checked` khi hai giá trị bằng nhau |
| `input(string $key, $default = null)` / `input_str(string $key, string $default = '')` | Đọc tham số request (GET/POST) |
| `db(): Database` | Truy cập CSDL trực tiếp — `db()->all()`, `db()->row()`, `db()->value()`, `db()->query()` với tham số `?`; tên bảng trong `{}` |

---

## Dành cho `functions.php`

`functions.php` được nạp trước khi hiển thị trang — nơi đăng ký hook, field, taxonomy, mẫu trang.

### Hook

| Hàm | Mô tả |
|---|---|
| `add_action(string $hook, callable $callback, int $priority = 10)` | Chạy hàm khi hook xảy ra |
| `do_action(string $hook, ...$args)` | Kích hoạt hook (theme tự tạo hook riêng cũng được) |
| `add_filter(string $hook, callable $callback, int $priority = 10)` | Sửa một giá trị trước khi dùng; callback nhận giá trị (cùng tham số phụ) và **phải trả về** giá trị |
| `apply_filters(string $hook, mixed $value, ...$args): mixed` | Chạy filter |

```php
// Chèn script vào <head> của mọi trang
add_action('lq_script', function () {
    echo '<script defer src="' . e(theme_url('assets/theme.js')) . '"></script>' . "\n";
});

// Tóm tắt ngắn hơn
add_filter('excerpt_length', fn () => 28);
```

**Action có sẵn**

| Hook | Khi nào | Tham số |
|---|---|---|
| `template_redirect` | Đã xác định trang, trước khi chọn template (redirect, chặn truy cập…) | `Query $main` |
| `lq_script` | Trong `<head>` khi theme gọi `lq_script()` | — |
| `lq_body_open` | Ngay sau `<body>` | — |
| `lq_footer` | Trước `</body>` | — |
| `post_saved` | Sau khi lưu bài | `int $id, string $status` |
| `post_trashed` / `post_restored` | Bỏ vào / lấy ra khỏi thùng rác | `int $id` |
| `before_post_deleted` | Trước khi xoá vĩnh viễn | `int $id` |

**Filter có sẵn**

| Hook | Giá trị | Tham số phụ |
|---|---|---|
| `main_query` | `Query` truy vấn chính | — |
| `template_hierarchy` | Mảng tên tệp template sẽ thử | `Query` |
| `template_include` | Đường dẫn tệp template cuối cùng | `Query` |
| `the_title` | Tiêu đề | bài |
| `the_content` | Nội dung khi hiển thị | bài |
| `content_save_pre` | Nội dung trước khi lưu | — |
| `get_the_excerpt` | Tóm tắt | bài |
| `excerpt_length` | Số từ (40) | — |
| `excerpt_more` | Chuỗi nối sau tóm tắt (`…`) | — |
| `post_link` | URL bài | bài |
| `related_posts` | Mảng bài của `get_related_posts()` | bài đang xem, `$limit`, `$args` |
| `archive_title` | Tiêu đề trang lưu trữ | đối tượng đang xem |
| `pre_get_document_title` | Trả chuỗi khác rỗng để thay hẳn `<title>` | — |
| `document_title_parts` | Mảng `title`, `tagline`, `page`, `site` | — |
| `document_title_separator` | Dấu ngăn (`–`) | — |
| `meta_description` | Kết quả `lq_meta_description()` | — |
| `canonical_url` | URL chuẩn | — |
| `body_class` | Mảng class của `<body>` | — |
| `post_class` | Mảng class của khối bài | bài |
| `site_language` | Mã ngôn ngữ (`vi`) | — |
| `lq_ajax_script` | `false` để không in `window.LQ` | — |
| `show_admin_bar` | `false` để tắt thanh quản trị | — |
| `admin_bar_items` | Mảng mục của thanh quản trị | — |
| `ajax_posts_args` | Tham số truy vấn của action `posts` | dữ liệu gửi lên |
| `ajax_post_data` | Dữ liệu mỗi bài khi `format=json` | bài |
| `ajax_template_parts` | Danh sách template part được phép dùng cho `part` (mặc định `['parts/card']`) | — |
| `ajax_search_terms` | Term trong gợi ý tìm kiếm | từ khoá |

### Field tuỳ biến

```php
lq_add_post_field('subtitle', [
    'label' => 'Sapo',
    'type' => 'textarea',
    'box' => 'Thông tin thêm',
    'help' => 'Đoạn dẫn hiện dưới tiêu đề',
    'ai_role' => 'sapo',
]);
```

`lq_add_post_field(string $key, array $args)` — `$key` chữ thường, số, gạch dưới. Tuỳ chọn:

| Tuỳ chọn | Ý nghĩa |
|---|---|
| `type` | `text` (mặc định), `textarea`, `number`, `url`, `email`, `select`, `radio`, `checkbox`, `date`, `color`, `image` |
| `label`, `help`, `placeholder`, `default` | Nhãn, ghi chú, chữ mờ, giá trị mặc định (chỉ điền sẵn cho bài chưa từng lưu field này; người dùng xoá trắng rồi lưu thì giữ rỗng — `get_field()` trả `''`) |
| `options` | `['giá trị' => 'Nhãn']` — bắt buộc với `select`, `radio` |
| `box` | Tên khung chứa field trong form (mặc định "Thông tin thêm") |
| `context` | `main` (cột chính) \| `side` (cột phải) |
| `width` | `full` \| `half` |
| `required`, `min`, `max`, `step` | Ràng buộc nhập liệu |
| `repeat`, `max_items`, `add_label` | Nhập nhiều giá trị (chỉ `text`, `number`, `url`, `email`, `date`, `image`) |
| `post_type` | `'post'` (mặc định), `'page'` hoặc `['post', 'page']` |
| `template` | Chỉ hiện với mẫu trang này |
| `cap` | Quyền cần có để thấy field (mặc định `edit_posts`) |
| `priority` | Thứ tự hiển thị |
| `validate` | `fn ($value, ?array $post): ?string` — trả thông báo lỗi hoặc `null` |
| `sanitize` | `fn ($value): mixed` — làm sạch trước khi lưu |
| `ai_role` | `'sapo'` \| `'seo'` — trợ lý AI điền gợi ý vào field này |

`lq_add_meta_box(string $id, string $title, callable $render, array $opts = [])` — khung tự vẽ khi field có sẵn không đủ: `$render(?array $post)` in HTML; `$opts['save']` là `fn (int $postId, array $input)` để tự lưu.

### Taxonomy tuỳ biến

```php
lq_register_taxonomy('actor', [
    'label' => 'Diễn viên',
    'singular' => 'diễn viên',
    'base' => 'dien-vien',        // URL: /dien-vien/ten-dien-vien/
]);
```

`lq_register_taxonomy(string $key, array $args)` — `$key` 2–32 ký tự chữ thường, số, gạch dưới; không trùng `category`, `tag`, `post`, `page`. Tuỳ chọn: `post_type` (loại nội dung dùng taxonomy này, mặc định `['post']`; vd. `['su_kien']` cho loại tuỳ biến), `label`, `singular`, `hierarchical` (có cấp cha/con, hiện dạng checklist), `base` (tiền tố URL frontend, `''` = không tiền tố), `route` (đường dẫn admin), `icon` (tên icon Lucide), `description`, `placeholder`, `cap` (mặc định `manage_terms`; quyền quản lý danh sách — người không có quyền này vẫn gắn được term đã có cho bài nhưng không tạo được term mới), `show_in_menu`, `list_column` (cột trong danh sách bài), `context` (`side` \| `main`).

Template hiển thị: `taxonomy-actor.php`, `taxonomy-actor-{slug}.php` hoặc `taxonomy.php`.

### Loại nội dung tuỳ biến

Sản phẩm, sự kiện, dự án… dùng chung bảng bài viết, có mục riêng trên menu admin, form soạn như bài viết (tự lưu, lịch sử phiên bản, thùng rác — **không** có chuyên mục/thẻ), URL `/{base}/{slug}/` và trang danh sách `/{base}/`.

```php
lq_register_post_type('su_kien', [
    'label' => 'Sự kiện',
    'singular' => 'sự kiện',
    'base' => 'su-kien',      // URL: /su-kien/hoi-cho-sach/ và /su-kien/
    'icon' => 'calendar',
]);
lq_register_taxonomy('dia_diem', ['label' => 'Địa điểm', 'post_type' => ['su_kien']]);
lq_add_post_field('ngay', ['label' => 'Ngày diễn ra', 'type' => 'date', 'post_type' => ['su_kien']]);
```

`lq_register_post_type(string $key, array $args)` — `$key` 2–20 ký tự chữ thường, số, gạch dưới (không trùng `post`, `page`). Tuỳ chọn: `label`, `singular`, `base` (tiền tố URL, mặc định = `route`), `route` (đường dẫn admin, mặc định `$key` đổi `_` thành `-`), `icon`, `cap` (mặc định `edit_posts`; đăng cần `publish_posts`, sửa bài người khác cần `edit_others_posts` như bài viết), `description` (hiện ở trang danh sách qua `the_archive_description()`), `public` (mặc định `true`; `false` = chỉ quản lý trong admin, không có trang ngoài website, không lộ qua tìm kiếm/AJAX), `has_archive` (mặc định `true`), `show_in_menu`.

Template: `single-su_kien.php`, `archive-su_kien.php` (rồi `single.php` / `archive.php`). Truy vấn: `lq_query(['type' => 'su_kien'])`. Hàm: `get_post_type($post)`, `is_post_type_archive('su_kien')`, `get_post_type_archive_link('su_kien')`.

### Mẫu trang

```php
lq_register_page_template('contact', [
    'label' => 'Trang liên hệ',
    'file' => 'templates/contact.php',
    'fields' => [
        'contact_phone' => ['label' => 'Số điện thoại', 'type' => 'text'],
    ],
]);
```

`lq_register_page_template(string $key, array $args)` — `label`, `description`, `file` (mặc định `page-{key}.php`), `fields` (field chỉ hiện và chỉ được lưu khi trang chọn mẫu này, cùng tuỳ chọn với `lq_add_post_field`).

### AJAX

```php
lq_add_ajax('vote', function (array $in) {
    $id = (int) ($in['id'] ?? 0);
    if ($id <= 0) {
        lq_ajax_error('Thiếu bài viết', 422);
    }
    return ['count' => 12];   // -> {ok: true, count: 12}
}, ['public' => true]);
```

`lq_add_ajax(string $action, callable $callback, array $opts = [])` — tên action chữ thường, số, `_ . -`. Callback nhận dữ liệu gửi lên (query string + form + JSON body) và trả: mảng → JSON `{ok: true, …}`; mảng danh sách → `{ok: true, data: […]}`; chuỗi → HTML; `null` → `{ok: true}`.

| Tuỳ chọn | Ý nghĩa |
|---|---|
| `public` | `true` = khách chưa đăng nhập gọi được (mặc định `false`) |
| `method` | `'POST'` (mặc định) \| `'GET'` \| `'ANY'` |
| `cap` | Quyền cần có, vd. `'edit_posts'` |
| `csrf` | Kiểm tra token với request không phải GET (mặc định `true`) |
| `rate` | Số lượt tối đa mỗi phút cho một IP (mặc định 20 với action công khai không phải GET) |

Đăng ký trùng tên với action của lõi (`posts`, `search`) thì ghi đè action đó.

### Tác vụ định kỳ

`lq_add_cron_task(string $name, int $intervalSeconds, callable $callback, string $label = '')` — chạy theo lượt truy cập website (tối đa 1 lần/phút), không cần cron của hệ điều hành.

```php
lq_add_cron_task('don-cache', 3600, fn () => /* ... */ null, 'Dọn cache theme');
```

### Bảng tin admin

- `lq_add_dashboard_widget(string $id, string $title, callable $render, array $opts = [])` — widget cho Bảng tin. `$render` nhận độ rộng (3/4/6/8/12 cột) và in HTML, nên bọc trong `<section class="tile">…</section>`. `$opts`: `description`, `icon`, `size`, `sizes`, `cap`, `default`.
- `lq_add_admin_page(string $slug, string $title, callable $callback, string $icon = 'puzzle', string $cap = 'manage_options')` — trang riêng trong admin tại `/admin/plugin/{slug}`.
