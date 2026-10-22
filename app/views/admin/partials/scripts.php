<?php defined('LQ_ROOT') || exit; ?>
<script src="https://cdn.jsdelivr.net/npm/lucide@0.460.0/dist/umd/lucide.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/notyf@3/notyf.min.js"></script>
<script>window.LQ_UPLOAD_MAX_MB = <?= is_file(LQ_CONFIG) ? (int) option('upload_max_mb', 8) : 8 ?>;</script>
<?php if (is_file(LQ_CONFIG) && current_user()): ?>
  <?php // Nút/khung AI chỉ hiện khi đã bật (đã cài thư viện + có key + có model) — xem LQ\AI\AI::enabled(). ?>
  <script>window.LQ_AI = <?= json_encode(\LQ\AI\AI::enabled() ? [
      'enabled' => true,
      'provider' => \LQ\AI\Connectors::LIST[\LQ\AI\AI::active()]['label'],
      'model' => \LQ\AI\AI::model(\LQ\AI\AI::active()),
      'chat' => can('access_admin'),
      'code' => \LQ\AI\AgentTools::codeAccess(), // ẩn gợi ý chat về mã/lỗi/theme với người không phải admin
      'user' => (int) current_user()['id'],
  ] : ['enabled' => false], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;</script>
<?php endif; ?>
<script src="<?= e(asset('admin/admin.js')) ?>"></script>
<script src="<?= e(asset('admin/media.js')) ?>"></script>
<?php if (is_file(LQ_CONFIG) && current_user()): ?>
  <script src="<?= e(asset('admin/ai.js')) ?>"></script>
  <script src="<?= e(asset('admin/ai-chat.js')) ?>"></script>
<?php endif; ?>
