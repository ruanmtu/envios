<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
define('APP_NAME', 'Disparador WhatsApp');
define('APP_VERSION', '1.1.0');

ini_set('display_errors', '0');
error_reporting(E_ALL);

$__cfg = APP_ROOT . '/includes/config.php';
if (!is_file($__cfg)) {
    if (PHP_SAPI === 'cli' || defined('NO_SESSION')) {
        http_response_code(503);
        exit("Sistema ainda não instalado.\n");
    }
    header('Location: install.php');
    exit;
}
require $__cfg;

date_default_timezone_set(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'America/Sao_Paulo');
mb_internal_encoding('UTF-8');

require APP_ROOT . '/includes/db.php';
require APP_ROOT . '/includes/helpers.php';
require APP_ROOT . '/includes/auth.php';
require APP_ROOT . '/includes/whatsapp.php';
require APP_ROOT . '/includes/layout.php';

set_exception_handler(function (Throwable $e): void {
    error_log('[disparador] ' . $e->getMessage() . ' em ' . $e->getFile() . ':' . $e->getLine());
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'Erro: ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }
    http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><div style="font-family:sans-serif;max-width:600px;margin:60px auto">'
        . '<h2>Ocorreu um erro inesperado</h2><p>Tente novamente. Se persistir, verifique o log de erros do PHP na Hostinger.</p>'
        . '<p style="color:#888;font-size:13px">' . htmlspecialchars($e->getMessage()) . '</p></div>';
});

if (PHP_SAPI !== 'cli' && !defined('NO_SESSION')) {
    session_name('DISPSESS');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
}
