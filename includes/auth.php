<?php
declare(strict_types=1);

const SESSION_IDLE_SECONDS = 8 * 3600;
const LOGIN_MAX_TENTATIVAS = 5;

function client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

function current_user(): ?array
{
    if (empty($_SESSION['uid'])) {
        return null;
    }
    if (!isset($GLOBALS['__user']) || $GLOBALS['__user']['id'] != $_SESSION['uid']) {
        $u = q('SELECT * FROM usuarios WHERE id = ? AND ativo = 1', [(int)$_SESSION['uid']])->fetch();
        $GLOBALS['__user'] = $u ?: null;
    }
    return $GLOBALS['__user'];
}

/** @return true|string true se logou, ou a mensagem de erro */
function attempt_login(string $usuario, string $senha): bool|string
{
    $ip = client_ip();
    $usuario = mb_strtolower(trim($usuario));
    $n = (int)q(
        'SELECT COUNT(*) FROM login_tentativas WHERE (ip = ? OR usuario = ?) AND criado_em > (NOW() - INTERVAL 15 MINUTE)',
        [$ip, $usuario]
    )->fetchColumn();
    if ($n >= LOGIN_MAX_TENTATIVAS) {
        return 'Muitas tentativas de acesso. Aguarde 15 minutos e tente novamente.';
    }

    $u = q('SELECT * FROM usuarios WHERE usuario = ? AND ativo = 1', [$usuario])->fetch();
    if (!$u || !password_verify($senha, $u['senha_hash'])) {
        q('INSERT INTO login_tentativas (ip, usuario, criado_em) VALUES (?, ?, NOW())', [$ip, $usuario]);
        return 'Usuário ou senha inválidos.';
    }

    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$u['id'];
    $_SESSION['last'] = time();
    q('DELETE FROM login_tentativas WHERE usuario = ? OR criado_em < (NOW() - INTERVAL 1 DAY)', [$usuario]);
    q('UPDATE usuarios SET ultimo_login = NOW() WHERE id = ?', [$u['id']]);
    if (password_needs_rehash($u['senha_hash'], PASSWORD_DEFAULT)) {
        q('UPDATE usuarios SET senha_hash = ? WHERE id = ?', [password_hash($senha, PASSWORD_DEFAULT), $u['id']]);
    }
    return true;
}

function do_logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function require_login(): array
{
    $u = current_user();
    if (!$u) {
        redirect('login.php');
    }
    if (time() - (int)($_SESSION['last'] ?? 0) > SESSION_IDLE_SECONDS) {
        do_logout();
        redirect('login.php?expirou=1');
    }
    $_SESSION['last'] = time();
    if ((int)$u['trocar_senha'] === 1 && basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'conta.php') {
        flash('aviso', 'Por segurança, defina uma nova senha para continuar.');
        redirect('conta.php');
    }
    return $u;
}

function require_admin(): array
{
    $u = require_login();
    if ($u['perfil'] !== 'admin') {
        http_response_code(403);
        exit('Acesso restrito ao administrador.');
    }
    return $u;
}

function validate_password(string $senha): ?string
{
    if (mb_strlen($senha) < 8) {
        return 'A senha precisa ter pelo menos 8 caracteres.';
    }
    if (!preg_match('/[A-Za-z]/', $senha) || !preg_match('/\d/', $senha)) {
        return 'A senha precisa ter letras e números.';
    }
    return null;
}
