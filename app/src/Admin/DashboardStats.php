<?php
declare(strict_types=1);

namespace LQ\Admin;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use LQ\Content\Posts;
use LQ\Core\Plugins;
use LQ\Core\Themes;

/**
 * Số liệu cho các widget Bảng tin. Mỗi nhóm chỉ truy vấn một lần mỗi request
 * dù nhiều widget cùng dùng. Tác giả chỉ thấy số liệu bài của mình.
 */
final class DashboardStats
{
    private static array $memo = [];

    private static function memo(string $key, callable $fn): mixed
    {
        return self::$memo[$key] ??= $fn();
    }

    private static function own(): string
    {
        return can('edit_others_posts') ? '' : ' AND p.author_id = ' . (int) current_user()['id'];
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', site_timezone());
    }

    private static function utc(DateTimeImmutable $d): string
    {
        return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** @return array<string,int> số bài theo trạng thái */
    public static function counts(): array
    {
        return self::memo('counts', function () {
            $counts = array_fill_keys(array_keys(Posts::STATUSES), 0);
            foreach (db()->all("SELECT p.status, COUNT(*) c FROM {posts} p WHERE p.type = 'post'" . self::own() . ' GROUP BY p.status') as $r) {
                $counts[$r['status']] = (int) $r['c'];
            }
            return $counts;
        });
    }

    /** Bài đăng: theo tháng (12 tháng), theo tuần (12 tuần), 30 ngày gần nhất và 30 ngày trước đó. */
    public static function published(): array
    {
        return self::memo('published', function () {
            $tz = site_timezone();
            $now = self::now();
            $from = $now->modify('first day of this month')->setTime(0, 0)->sub(new DateInterval('P11M'));
            $weekStart = $now->modify('monday this week')->setTime(0, 0)->sub(new DateInterval('P11W'));

            $months = [];
            for ($i = 0; $i < 12; $i++) {
                $m = $from->add(new DateInterval("P{$i}M"));
                $months[$m->format('Y-m')] = ['label' => 'T' . $m->format('n'), 'full' => 'Tháng ' . $m->format('n/Y'), 'value' => 0];
            }
            $weeks = array_fill(0, 12, 0);
            $last30 = $prev30 = 0;

            $dates = db()->column(
                "SELECT p.published_at FROM {posts} p WHERE p.type = 'post' AND p.status = 'published' AND p.published_at >= ?" . self::own(),
                [self::utc(min($from, $now->sub(new DateInterval('P60D'))))]
            );
            foreach ($dates as $raw) {
                $d = (new DateTimeImmutable($raw, new DateTimeZone('UTC')))->setTimezone($tz);
                if (isset($months[$d->format('Y-m')])) {
                    $months[$d->format('Y-m')]['value']++;
                }
                $w = (int) floor(($d->getTimestamp() - $weekStart->getTimestamp()) / 604800);
                if ($w >= 0 && $w < 12) {
                    $weeks[$w]++;
                }
                $age = $now->getTimestamp() - $d->getTimestamp();
                if ($age >= 0 && $age < 30 * 86400) {
                    $last30++;
                } elseif ($age >= 30 * 86400 && $age < 60 * 86400) {
                    $prev30++;
                }
            }
            return ['months' => array_values($months), 'weeks' => $weeks, 'last30' => $last30, 'prev30' => $prev30];
        });
    }

    /** Số bài được tạo/sửa mỗi tuần trong 12 tuần. */
    public static function edits(): array
    {
        return self::memo('edits', function () {
            $weekStart = self::now()->modify('monday this week')->setTime(0, 0)->sub(new DateInterval('P11W'));
            $weeks = array_fill(0, 12, 0);
            foreach (db()->column("SELECT p.updated_at FROM {posts} p WHERE p.type = 'post' AND p.updated_at >= ?" . self::own(), [self::utc($weekStart)]) as $u) {
                $w = (int) floor(((new DateTimeImmutable($u, new DateTimeZone('UTC')))->getTimestamp() - $weekStart->getTimestamp()) / 604800);
                if ($w >= 0 && $w < 12) {
                    $weeks[$w]++;
                }
            }
            return $weeks;
        });
    }

    public static function termCounts(): array
    {
        return self::memo('terms', fn () => [
            'category' => (int) db()->value("SELECT COUNT(*) FROM {terms} WHERE taxonomy = 'category'"),
            'tag' => (int) db()->value("SELECT COUNT(*) FROM {terms} WHERE taxonomy = 'tag'"),
        ]);
    }

    public static function topCategories(int $limit = 5): array
    {
        return self::memo("top$limit", fn () => db()->all(
            "SELECT t.id, t.name, COUNT(p.id) AS value
             FROM {terms} t JOIN {post_terms} pt ON pt.term_id = t.id
             JOIN {posts} p ON p.id = pt.post_id AND p.status <> 'trash'" . self::own() . "
             WHERE t.taxonomy = 'category' GROUP BY t.id ORDER BY value DESC, t.name LIMIT " . (int) $limit
        ));
    }

    public static function recent(int $limit = 6): array
    {
        return self::memo("recent$limit", function () use ($limit) {
            $rows = db()->all(
                "SELECT p.id, p.title, p.status, p.featured_image, p.published_at, p.updated_at, p.author_id, u.display_name author_name
                 FROM {posts} p LEFT JOIN {users} u ON u.id = p.author_id
                 WHERE p.type = 'post' AND p.status <> 'trash'" . self::own() . '
                 ORDER BY p.updated_at DESC LIMIT ' . (int) $limit
            );
            Posts::attachTerms($rows);
            return $rows;
        });
    }

    public static function upcoming(): array
    {
        return self::memo('upcoming', fn () => db()->all(
            "SELECT p.id, p.title, p.published_at FROM {posts} p
             WHERE p.type = 'post' AND p.status = 'scheduled'" . self::own() . ' ORDER BY p.published_at LIMIT 4'
        ));
    }

    public static function users(): array
    {
        return self::memo('users', fn () => [
            'list' => db()->all('SELECT id, display_name, username, role, created_at FROM {users} ORDER BY created_at DESC LIMIT 5'),
            'count' => (int) db()->value('SELECT COUNT(*) FROM {users}'),
        ]);
    }

    public static function system(): array
    {
        return self::memo('system', function () {
            $theme = Themes::read(Themes::active());
            return [
                'LQCMS' => LQ_VERSION,
                'PHP' => PHP_VERSION,
                'Database' => preg_replace('/-MariaDB.*/', ' MariaDB', (string) db()->value('SELECT VERSION()')),
                'Theme' => $theme['name'] ?? Themes::active(),
                'Plugin bật' => count(Plugins::activeSlugs()),
                'Upload tối đa' => ini_get('upload_max_filesize'),
            ];
        });
    }
}
