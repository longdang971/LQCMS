-- LQCMS schema (MariaDB 10.6+ / MySQL 8+). {name} sẽ được thay bằng prefix + name khi cài.
-- Mọi cột DATETIME lưu theo UTC.
-- Tên khoá ngoại cũng viết dạng {fk_...} để có prefix: MySQL 8 / MariaDB < 12 bắt tên constraint duy nhất trong cả database,
-- không có prefix thì cài site thứ hai (prefix khác) chung database sẽ lỗi "Duplicate foreign key constraint name".

CREATE TABLE IF NOT EXISTS {users} (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(60) NOT NULL,
  email VARCHAR(191) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  display_name VARCHAR(191) NOT NULL DEFAULT '',
  role VARCHAR(20) NOT NULL DEFAULT 'subscriber',
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL,
  last_login_at DATETIME NULL,
  UNIQUE KEY uniq_username (username),
  UNIQUE KEY uniq_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {user_tokens} (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  selector CHAR(24) NOT NULL,
  validator_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  UNIQUE KEY uniq_selector (selector),
  KEY idx_user (user_id),
  CONSTRAINT {fk_tokens_user} FOREIGN KEY (user_id) REFERENCES {users} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {login_attempts} (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip VARCHAR(45) NOT NULL,
  attempted_at DATETIME NOT NULL,
  KEY idx_ip_time (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {options} (
  name VARCHAR(191) NOT NULL PRIMARY KEY,
  value LONGTEXT NOT NULL,
  autoload TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {posts} (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  type VARCHAR(20) NOT NULL DEFAULT 'post',               -- post | page
  parent_id BIGINT UNSIGNED NULL,                         -- trang cha (chỉ dùng cho page)
  menu_order INT NOT NULL DEFAULT 0,                      -- thứ tự trang
  template VARCHAR(64) NULL,                              -- mẫu trang do theme khai báo
  title TEXT NOT NULL,
  slug VARCHAR(191) NOT NULL,
  content LONGTEXT NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'draft',  -- draft | pending | published | scheduled | trash
  previous_status VARCHAR(20) NULL,              -- để khôi phục từ thùng rác
  author_id BIGINT UNSIGNED NULL,
  featured_image VARCHAR(255) NULL,
  published_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uniq_type_slug (type, slug),
  KEY idx_status_date (type, status, published_at),
  KEY idx_author (author_id),
  KEY idx_type_parent (type, parent_id),
  CONSTRAINT {fk_posts_author} FOREIGN KEY (author_id) REFERENCES {users} (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {terms} (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  taxonomy VARCHAR(32) NOT NULL,                 -- category | tag
  name VARCHAR(191) NOT NULL,
  slug VARCHAR(191) NOT NULL,
  parent_id BIGINT UNSIGNED NULL,
  description TEXT NOT NULL,
  UNIQUE KEY uniq_tax_slug (taxonomy, slug),
  KEY idx_parent (parent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {post_terms} (
  post_id BIGINT UNSIGNED NOT NULL,
  term_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (post_id, term_id),
  KEY idx_term (term_id),
  CONSTRAINT {fk_pt_post} FOREIGN KEY (post_id) REFERENCES {posts} (id) ON DELETE CASCADE,
  CONSTRAINT {fk_pt_term} FOREIGN KEY (term_id) REFERENCES {terms} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Slug cũ của bài viết, để frontend 301 về link mới khi đổi slug (giữ SEO).
CREATE TABLE IF NOT EXISTS {old_slugs} (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  post_id BIGINT UNSIGNED NOT NULL,
  slug VARCHAR(191) NOT NULL,
  created_at DATETIME NOT NULL,
  KEY idx_slug (slug),
  CONSTRAINT {fk_old_post} FOREIGN KEY (post_id) REFERENCES {posts} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dữ liệu riêng của từng bài do theme/plugin khai báo (lq_add_post_field). Giá trị lưu JSON.
CREATE TABLE IF NOT EXISTS {post_meta} (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  post_id BIGINT UNSIGNED NOT NULL,
  meta_key VARCHAR(191) NOT NULL,
  meta_value LONGTEXT NOT NULL,
  UNIQUE KEY uniq_post_key (post_id, meta_key),
  KEY idx_key (meta_key),
  CONSTRAINT {fk_meta_post} FOREIGN KEY (post_id) REFERENCES {posts} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Thư viện media (chỉ lưu ảnh gốc).
CREATE TABLE IF NOT EXISTS {media} (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  path VARCHAR(255) NOT NULL,
  file_name VARCHAR(191) NOT NULL,
  mime VARCHAR(64) NOT NULL,
  size INT UNSIGNED NOT NULL DEFAULT 0,
  width INT UNSIGNED NULL,
  height INT UNSIGNED NULL,
  alt VARCHAR(255) NOT NULL DEFAULT '',
  caption TEXT NOT NULL,
  author_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uniq_path (path),
  KEY idx_created (created_at),
  CONSTRAINT {fk_media_author} FOREIGN KEY (author_id) REFERENCES {users} (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lịch sử phiên bản (kind = revision, mỗi lần bấm lưu) và bản tự lưu (kind = autosave, mỗi người một bản).
CREATE TABLE IF NOT EXISTS {post_revisions} (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  post_id BIGINT UNSIGNED NOT NULL,
  kind VARCHAR(16) NOT NULL DEFAULT 'revision',
  title TEXT NOT NULL,
  content LONGTEXT NOT NULL,
  author_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  KEY idx_post_kind (post_id, kind, created_at),
  CONSTRAINT {fk_rev_post} FOREIGN KEY (post_id) REFERENCES {posts} (id) ON DELETE CASCADE,
  CONSTRAINT {fk_rev_author} FOREIGN KEY (author_id) REFERENCES {users} (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Đếm lượt gọi action AJAX công khai theo IP, mỗi dòng là một cửa sổ 1 phút.
CREATE TABLE IF NOT EXISTS {rate_limits} (
  bucket VARCHAR(191) NOT NULL,
  window_start INT UNSIGNED NOT NULL,
  hits INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (bucket, window_start),
  KEY idx_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
