<?php
declare(strict_types=1);

namespace LQ\Core;

use PDO;
use PDOStatement;
use Throwable;

/**
 * PDO bọc mỏng. Trong câu SQL viết tên bảng dạng {posts}, lớp này tự thêm prefix (vd. lq_posts).
 */
final class Database
{
    public readonly PDO $pdo;
    public readonly string $prefix;

    public function __construct(array $c)
    {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], (int) ($c['port'] ?? 3306), $c['name']);
        $this->pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->pdo->exec("SET time_zone = '+00:00'");
        $this->prefix = (string) ($c['prefix'] ?? 'lq_');
    }

    public function table(string $name): string
    {
        return '`' . $this->prefix . $name . '`';
    }

    public function sql(string $sql): string
    {
        return preg_replace_callback('/\{([a-z_]+)\}/', fn ($m) => $this->table($m[1]), $sql);
    }

    public function query(string $sql, array $params = []): PDOStatement
    {
        $st = $this->pdo->prepare($this->sql($sql));
        $st->execute($params);
        return $st;
    }

    public function row(string $sql, array $params = []): ?array
    {
        $r = $this->query($sql, $params)->fetch();
        return $r === false ? null : $r;
    }

    public function all(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    public function value(string $sql, array $params = []): mixed
    {
        $v = $this->query($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public function column(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll(PDO::FETCH_COLUMN);
    }

    public function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = 'INSERT INTO ' . $this->table($table)
            . ' (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
        $this->pdo->prepare($sql)->execute(array_values($data));
        return (int) $this->pdo->lastInsertId();
    }

    public function update(string $table, array $data, array $where): int
    {
        $set = implode(',', array_map(fn ($c) => "`$c`=?", array_keys($data)));
        $cond = implode(' AND ', array_map(fn ($c) => "`$c`=?", array_keys($where)));
        $st = $this->pdo->prepare('UPDATE ' . $this->table($table) . " SET $set WHERE $cond");
        $st->execute([...array_values($data), ...array_values($where)]);
        return $st->rowCount();
    }

    public function delete(string $table, array $where): int
    {
        $cond = implode(' AND ', array_map(fn ($c) => "`$c`=?", array_keys($where)));
        $st = $this->pdo->prepare('DELETE FROM ' . $this->table($table) . " WHERE $cond");
        $st->execute(array_values($where));
        return $st->rowCount();
    }

    /** Chuỗi "?,?,?" cho mệnh đề IN. */
    public static function placeholders(array $values): string
    {
        return implode(',', array_fill(0, max(1, count($values)), '?'));
    }

    public function transaction(callable $fn): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $fn($this);
            $this->pdo->commit();
            return $result;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
