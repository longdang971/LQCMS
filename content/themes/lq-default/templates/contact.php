<?php
/** Mẫu trang "Trang liên hệ" (khai báo trong functions.php). Field đọc bằng get_field('contact_phone'). */
defined('LQ_ROOT') || exit;
get_header();
?>
<?php while (have_posts()): the_post(); ?>
<article<?php post_class('entry'); ?>>
  <header class="entry-head wrap">
    <span class="eyebrow">Liên hệ</span>
    <h1 class="entry-title"><?php the_title(); ?></h1>
    <?php edit_post_link('Sửa trang', '<p class="entry-edit">', '</p>'); ?>
  </header>
  <div class="wrap contact-grid">
    <div class="prose"><?php the_content(); ?></div>
    <div class="contact-cards">
      <?php if ($phone = (string) get_field('contact_phone', '')): ?>
        <a class="contact-card" href="tel:<?= e(preg_replace('/[^\d+]/', '', $phone)) ?>"><span class="eyebrow">Điện thoại</span><strong><?= e($phone) ?></strong></a>
      <?php endif; ?>
      <?php if ($email = (string) get_field('contact_email', '')): ?>
        <a class="contact-card" href="mailto:<?= e($email) ?>"><span class="eyebrow">Email</span><strong><?= e($email) ?></strong></a>
      <?php endif; ?>
      <?php if ($address = (string) get_field('contact_address', '')): ?>
        <div class="contact-card"><span class="eyebrow">Địa chỉ</span><strong><?= nl2br(e($address)) ?></strong></div>
      <?php endif; ?>
      <?php if ($map = (string) get_field('contact_map', '')): ?>
        <a class="contact-card contact-map" href="<?= e($map) ?>" target="_blank" rel="noopener"><span class="eyebrow">Bản đồ</span><strong>Mở Google Maps ↗</strong></a>
      <?php endif; ?>
    </div>
  </div>
</article>
<?php endwhile; ?>
<?php get_footer(); ?>
