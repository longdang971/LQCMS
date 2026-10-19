<script src="https://cdn.jsdelivr.net/npm/lucide@0.460.0/dist/umd/lucide.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/notyf@3/notyf.min.js"></script>
<script>window.LQ_UPLOAD_MAX_MB = <?= is_file(LQ_CONFIG) ? (int) option('upload_max_mb', 8) : 8 ?>;</script>
<script src="<?= e(asset('admin/admin.js')) ?>"></script>
<script src="<?= e(asset('admin/media.js')) ?>"></script>
