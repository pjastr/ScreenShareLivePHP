<?php
defined('SSLIVE') || exit;

/** Zrzut SQL wszystkich tabel aplikacji (z jej prefiksem) – do pobrania przed aktualizacją. */
final class Backup
{
    public static function stream(): void
    {
        $pdo = DB::pdo();
        $tables = DB::ownTables();
        $name = 'sslive-backup-' . gmdate('Ymd-His') . '.sql';
        header('Content-Type: application/sql; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Cache-Control: no-store');
        @set_time_limit(0);
        while (ob_get_level()) {
            ob_end_flush();
        }
        echo "-- screensharelive-php " . APP_VERSION . " backup\n-- " . gmdate('c') . "\n-- prefix: " . DB::prefix() . "\n\n";
        echo "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";
        foreach ($tables as $t) {
            $create = $pdo->query('SHOW CREATE TABLE `' . str_replace('`', '', $t) . '`')->fetch(PDO::FETCH_NUM);
            echo "DROP TABLE IF EXISTS `$t`;\n" . $create[1] . ";\n\n";
            $st = $pdo->query('SELECT * FROM `' . str_replace('`', '', $t) . '`', PDO::FETCH_ASSOC);
            $batch = [];
            $cols = null;
            while ($row = $st->fetch()) {
                if ($cols === null) {
                    $cols = '`' . implode('`,`', array_keys($row)) . '`';
                }
                $vals = [];
                foreach ($row as $v) {
                    if ($v === null) {
                        $vals[] = 'NULL';
                    } elseif (is_int($v) || is_float($v)) {
                        $vals[] = (string)$v;
                    } elseif (!mb_check_encoding((string)$v, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', (string)$v)) {
                        $vals[] = '0x' . bin2hex((string)$v);
                    } else {
                        $vals[] = $pdo->quote((string)$v);
                    }
                }
                $batch[] = '(' . implode(',', $vals) . ')';
                if (count($batch) >= 50) {
                    echo "INSERT INTO `$t` ($cols) VALUES\n" . implode(",\n", $batch) . ";\n";
                    $batch = [];
                    flush();
                }
            }
            if ($batch) {
                echo "INSERT INTO `$t` ($cols) VALUES\n" . implode(",\n", $batch) . ";\n";
            }
            echo "\n";
        }
        echo "SET FOREIGN_KEY_CHECKS=1;\n";
        exit;
    }
}
