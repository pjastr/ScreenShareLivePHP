<?php
defined('SSLIVE') || exit;

/**
 * Cienka warstwa nad PDO. Nazwy tabel w zapytaniach zapisuje się jako {nazwa},
 * a klasa zamienia je na `prefiksnazwa` (np. `sslive482_users`).
 */
final class DB
{
    private static ?PDO $pdo = null;
    private static string $prefix = '';

    public static function connect(array $db, string $prefix): void
    {
        self::$pdo = self::makePdo($db);
        self::$prefix = $prefix;
    }

    public static function makePdo(array $db): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $db['host'] ?? 'localhost',
            (int)($db['port'] ?? 3306),
            $db['name'] ?? ''
        );
        $pdo = new PDO($dsn, $db['user'] ?? '', $db['pass'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("SET time_zone = '+00:00'");
        return $pdo;
    }

    public static function pdo(): PDO
    {
        if (!self::$pdo) {
            throw new RuntimeException('Database not connected');
        }
        return self::$pdo;
    }

    public static function prefix(): string
    {
        return self::$prefix;
    }

    public static function table(string $name): string
    {
        return '`' . self::$prefix . $name . '`';
    }

    public static function sql(string $sql): string
    {
        return preg_replace_callback('/\{([a-z_]+)\}/', fn($m) => self::table($m[1]), $sql);
    }

    public static function run(string $sql, array $params = []): PDOStatement
    {
        $st = self::pdo()->prepare(self::sql($sql));
        foreach (array_values(array_keys($params)) as $i => $k) {
            $v = $params[$k];
            $key = is_int($k) ? $k + 1 : $k;
            if (is_int($v)) {
                $st->bindValue($key, $v, PDO::PARAM_INT);
            } elseif (is_bool($v)) {
                $st->bindValue($key, $v ? 1 : 0, PDO::PARAM_INT);
            } elseif ($v === null) {
                $st->bindValue($key, null, PDO::PARAM_NULL);
            } else {
                $st->bindValue($key, (string)$v, PDO::PARAM_STR);
            }
        }
        $st->execute();
        return $st;
    }

    public static function row(string $sql, array $params = []): ?array
    {
        $r = self::run($sql, $params)->fetch();
        return $r === false ? null : $r;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    public static function value(string $sql, array $params = [])
    {
        $v = self::run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = 'INSERT INTO {' . $table . '} (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
        self::run($sql, array_values($data));
        return (int)self::pdo()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $params = []): int
    {
        $set = implode(',', array_map(fn($c) => "`$c` = ?", array_keys($data)));
        return self::run('UPDATE {' . $table . '} SET ' . $set . ' WHERE ' . $where, array_merge(array_values($data), $params))->rowCount();
    }

    public static function tableExists(string $name): bool
    {
        $full = self::$prefix . $name;
        return (bool)self::pdo()->query('SHOW TABLES LIKE ' . self::pdo()->quote(addcslashes($full, '_%\\')))->fetchColumn();
    }

    /** Lista tabel bazy należących do aplikacji (z bieżącym prefiksem). */
    public static function ownTables(): array
    {
        return self::pdo()->query('SHOW TABLES LIKE ' . self::pdo()->quote(addcslashes(self::$prefix, '_%\\') . '%'))->fetchAll(PDO::FETCH_COLUMN);
    }

    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
