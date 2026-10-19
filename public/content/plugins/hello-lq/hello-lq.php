<?php
/**
 * Plugin Name: Hello LQ
 * Description: Plugin mẫu minh hoạ hệ thống hook: thêm widget cho Bảng tin và một trang riêng trong admin.
 * Version: 1.0.0
 * Author: LQCMS
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
    <div class="page-head"><div><h1>Hello LQ</h1><p>Ví dụ trang cài đặt của plugin, dùng chung giao diện admin.</p></div></div>
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
}, 'smile');

add_action('activate_hello-lq', function (): void {
    update_option('hello_lq_message', 'Xin chào từ plugin!', false);
});
