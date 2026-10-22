<?php
defined('LQ_ROOT') || exit;
use LQ\Admin\DashboardStats as S;
use LQ\Core\Auth;

$u = S::users();
?>
<section class="tile">
  <div class="tile-title"><div><h3>Thành viên</h3><p><?= $u['count'] ?> tài khoản</p></div></div>
  <ul class="people">
    <?php foreach ($u['list'] as $m): ?>
      <li>
        <?= avatar($m['display_name'] ?: $m['username'], '', (string) $m['id']) ?>
        <span><strong><?= e($m['display_name'] ?: $m['username']) ?></strong><small><?= e(Auth::ROLES[$m['role']] ?? $m['role']) ?></small></span>
        <time><?= e(format_date($m['created_at'], (string) option('date_format', 'd/m/Y'))) ?></time>
      </li>
    <?php endforeach; ?>
  </ul>
</section>
