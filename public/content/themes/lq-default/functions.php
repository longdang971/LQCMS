<?php
/**
 * functions.php của theme được nạp ở mỗi request khi theme đang kích hoạt (giống WordPress).
 * Dùng để đăng ký hook, thêm tuỳ chọn riêng của theme...
 */
defined('LQ_ROOT') || exit;

// Ví dụ: thêm một dòng vào bảng "Hệ thống" ở Bảng tin không cần sửa core.
// add_action('admin_dashboard', function () { echo '...'; });

/*
 * Ví dụ: taxonomy "Diễn viên" — chọn như Thẻ (gõ tên, có gợi ý, tự tạo mới), dùng chung giữa các bài.
 * Có trang quản lý riêng ở Bài viết › Diễn viên và cột riêng trong danh sách bài viết.
 * Khi làm frontend: post_terms($postId, 'actor') trả về danh sách diễn viên của bài.
 * Xoá đoạn này nếu website của bạn không cần.
 */
lq_register_taxonomy('actor', [
    'label' => 'Diễn viên',
    'singular' => 'diễn viên',
    'route' => 'dien-vien',          // /admin/dien-vien
    'base' => 'dien-vien',           // frontend sau này: /dien-vien/ten-dien-vien
    'hierarchical' => false,         // false = nhập dạng chip như Thẻ; true = checklist có cha/con như Chuyên mục
    'icon' => 'clapperboard',
    'placeholder' => 'Gõ tên diễn viên…',
    'description' => 'Diễn viên tham gia. Gõ tên rồi nhấn Enter, tên mới sẽ tự được tạo.',
    'list_column' => true,
]);

/*
 * Ví dụ: mẫu trang riêng của theme. Chọn ở khung "Thuộc tính trang" khi soạn trang.
 * Field khai báo trong 'fields' chỉ hiện (và chỉ được lưu) khi trang chọn đúng mẫu đó.
 * Khi làm frontend, trang dùng mẫu "contact" sẽ hiển thị bằng tệp templates/contact.php của theme.
 */
lq_register_page_template('contact', [
    'label' => 'Trang liên hệ',
    'description' => 'Có thông tin liên hệ và bản đồ.',
    'file' => 'templates/contact.php',
    'fields' => [
        'contact_phone' => ['label' => 'Số điện thoại', 'type' => 'text', 'width' => 'half', 'placeholder' => '0901 234 567'],
        'contact_email' => ['label' => 'Email nhận liên hệ', 'type' => 'email', 'width' => 'half'],
        'contact_address' => ['label' => 'Địa chỉ', 'type' => 'textarea', 'max' => 500],
        'contact_map' => ['label' => 'Link Google Maps', 'type' => 'url', 'help' => 'Dán link chia sẻ từ Google Maps.'],
    ],
]);

lq_register_page_template('full-width', [
    'label' => 'Toàn chiều rộng',
    'description' => 'Không có cột bên, nội dung trải hết chiều ngang (hợp cho landing page).',
    'file' => 'templates/full-width.php',
]);
