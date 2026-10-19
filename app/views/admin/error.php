<div class="empty big">
  <?= icon($code === 403 ? 'shield-x' : 'map-pin-off') ?>
  <h2><?= (int) $code ?></h2>
  <p><?= e($message) ?></p>
  <a class="btn btn-secondary" href="/admin">Về bảng tin</a>
</div>
