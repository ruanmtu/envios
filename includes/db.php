<?php
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        // Mantém NOW() do MySQL no mesmo fuso do PHP
        $pdo->exec("SET time_zone = '" . date('P') . "'");
    }
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute(array_values($params));
    return $st;
}

function setting(string $key, ?string $default = null): ?string
{
    if (!isset($GLOBALS['__settings'])) {
        $GLOBALS['__settings'] = [];
        foreach (q('SELECT chave, valor FROM configuracoes')->fetchAll() as $r) {
            $GLOBALS['__settings'][$r['chave']] = $r['valor'];
        }
    }
    $v = $GLOBALS['__settings'][$key] ?? null;
    return ($v === null || $v === '') ? $default : $v;
}

function setting_set(string $key, ?string $value): void
{
    q('INSERT INTO configuracoes (chave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)', [$key, $value]);
    unset($GLOBALS['__settings']);
}
