<?php
/**
 * Plugin Name: Hello LQ
 * Description: Plugin mẫu minh hoạ hệ thống hook: thêm widget cho Bảng tin và một trang riêng trong admin.
 * Version: 1.0.0
 * Author: LQCMS
 * Icon: smile
 * Requires PHP: 8.2
 */
defined('LQ_ROOT') || exit;

const HELLO_LQ_QUOTES = [
    'Có công mài sắt, có ngày nên kim.',
    'Đi một ngày đàng, học một sàng khôn.',
    'Không thầy đố mày làm nên.',
    'Học ăn, học nói, học gói, học mở.',
    'Muốn đi nhanh hãy đi một mình, muốn đi xa hãy đi cùng nhau.',
];

// Widget cho Bảng tin: người dùng tự kéo vào từ ngăn "Thêm widget".
lq_add_dashboard_widget('hello-lq', 'Câu nói hay', function (int $size): void {
    $quote = HELLO_LQ_QUOTES[array_rand(HELLO_LQ_QUOTES)];
    echo '<section class="tile"><div class="tile-title"><div><h3>Câu nói hay</h3><p>Từ plugin Hello LQ</p></div></div>'
        . '<p style="font-size:' . ($size >= 6 ? '18' : '15') . 'px;line-height:1.5;letter-spacing:-.01em">“' . e($quote) . '”</p></section>';
}, [
    'description' => 'Một câu tục ngữ ngẫu nhiên mỗi lần mở Bảng tin.',
    'icon' => 'quote',
    'size' => 4,
    'sizes' => [3, 4, 6],
]);

// Trang riêng trong admin: /admin/plugin/hello-lq
lq_add_admin_page('hello-lq', 'Hello LQ', function (): void {
    $saved = (string) option('hello_lq_message', 'Xin chào từ plugin!');
    ?>
    <div class="page-head"><div><h1>Hello LQ</h1></div></div>
    <section class="card narrow">
      <div class="stack">
        <div class="field">
          <label for="hello-msg">Lời chào</label>
          <input id="hello-msg" class="input" value="<?= e($saved) ?>">
          <p class="help">Bấm thử các nút để xem thông báo (Notyf) và hộp xác nhận (SweetAlert2) mà plugin có thể dùng qua <code>window.LQ</code>.</p>
        </div>
        <div class="form-actions">
          <button type="button" class="btn btn-primary" onclick="LQ.toast('success', document.getElementById('hello-msg').value)">Hiện toast</button>
          <button type="button" class="btn btn-secondary" onclick="LQ.confirm({title: 'Bạn có chắc không?', text: 'Đây chỉ là ví dụ.'}).then(ok => LQ.toast(ok ? 'info' : 'warning', ok ? 'Bạn đã đồng ý' : 'Bạn đã huỷ'))">Hộp xác nhận</button>
        </div>
      </div>
    </section>
    <?php
}, 'smile', 'manage_options', 'lời chào, thông báo toast, hộp xác nhận mẫu');

// Trợ lý AI: chỉ làm được những việc khai báo ở đây (không tự gọi hàm khác của plugin).
// Chỉ đọc → AI gọi và nhận kết quả ngay.
lq_add_ai_tool('hello_status', [
    'description' => 'Xem lời chào đang đặt của plugin Hello LQ và một câu tục ngữ ngẫu nhiên.',
    'cap' => 'manage_options',
    'run' => fn () => [
        'loi_chao' => (string) option('hello_lq_message', 'Xin chào từ plugin!'),
        'cau_noi' => HELLO_LQ_QUOTES[array_rand(HELLO_LQ_QUOTES)],
    ],
]);
// Có thay đổi (write) → AI chỉ gửi đề xuất, người dùng bấm "Chạy" mới đổi.
lq_add_ai_tool('hello_set_message', [
    'label' => 'Đổi lời chào của Hello LQ',
    'description' => 'Đổi lời chào hiển thị ở trang Hello LQ.',
    'params' => ['message' => ['type' => 'string', 'description' => 'Lời chào mới, tối đa 120 ký tự.']],
    'required' => ['message'],
    'cap' => 'manage_options',
    'write' => true,
    'run' => function (array $a): string {
        $msg = trim(mb_substr((string) $a['message'], 0, 120));
        if ($msg === '') {
            throw new InvalidArgumentException('Lời chào không được để trống.');
        }
        update_option('hello_lq_message', $msg, false);
        return "Đã đổi lời chào thành: $msg";
    },
]);

// Thanh quản trị trên trang web: mục có icon riêng đứng ngay sau "Mới" (priority 25), kèm menu con.
lq_add_admin_bar_item('hello-lq', [
    'label' => 'Hello',
    'url' => admin_url('/plugin/hello-lq'),
    'priority' => 25,
    'cap' => 'manage_options',
    'title' => 'Plugin Hello LQ',
    'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
        . '<circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" x2="9.01" y1="9" y2="9"/><line x1="15" x2="15.01" y1="9" y2="9"/></svg>',
]);
lq_add_admin_bar_item('hello-lq-quote', [
    'parent' => 'hello-lq',
    // html: nội dung tự vẽ (không phải link) — tự escape dữ liệu bên trong.
    'html' => fn () => '<span style="white-space:normal;line-height:1.4;padding:4px 0;max-width:260px">“'
        . e(HELLO_LQ_QUOTES[array_rand(HELLO_LQ_QUOTES)]) . '”</span>',
]);
lq_add_admin_bar_item('hello-lq-settings', [
    'parent' => 'hello-lq',
    'label' => 'Đổi lời chào',
    'url' => admin_url('/plugin/hello-lq'),
]);

add_action('activate_hello-lq', function (): void {
    update_option('hello_lq_message', 'Xin chào từ plugin!', false);
});
