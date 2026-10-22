<?php defined('LQ_ROOT') || exit; ?>
</main>

<footer class="site-footer">
  <div class="wrap footer-grid">
    <div class="footer-brand">
      <a class="brand" href="<?= e(home_url('/')) ?>"><span class="brand-mark" aria-hidden="true"></span><span class="brand-name"><?php bloginfo('name'); ?></span></a>
      <p><?php bloginfo('description'); ?></p>
    </div>
    <div>
      <h2 class="footer-title">Chuyên mục</h2>
      <ul class="footer-links">
        <?php foreach (get_terms('category', ['parent' => 0, 'number' => 6]) as $cat): ?>
          <li><a href="<?= e($cat['url']) ?>"><?= e($cat['name']) ?></a></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <div>
      <h2 class="footer-title">Trang</h2>
      <ul class="footer-links">
        <?php foreach (get_pages(['parent' => 0]) as $p): ?>
          <li><a href="<?= e($p['url']) ?>"><?= e($p['title']) ?></a></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
  <div class="wrap footer-bottom">
    <span>© <?= e(format_date(now_utc(), 'Y')) ?> <?php bloginfo('name'); ?></span>
    <span>Chạy bằng LQCMS <?php bloginfo('version'); ?><?php if (is_logged_in()): ?> · <a href="<?= e(admin_url()) ?>">Quản trị</a><?php endif; ?></span>
  </div>
</footer>

<script src="<?= e(theme_url('assets/theme.js')) ?>" defer></script>
<?php lq_footer(); ?>
</body>
</html>
