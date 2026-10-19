<?php
// Chạy khi xoá plugin: dọn dữ liệu plugin đã tạo.
defined('LQ_ROOT') || exit;

\LQ\Core\Options::delete('hello_lq_message');
