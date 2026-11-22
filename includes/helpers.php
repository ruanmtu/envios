<?php
declare(strict_types=1);

function e(mixed $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
}

/** URL base do sistema, ex.: https://seusite.com.br/painel */
function base_url(): string
{
    $host = $_SERVER['HTTP_HOST'] ?? 'seudominio.com.br';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return (is_https() ? 'https' : 'http') . '://' . $host . $dir;
}

function post(string $k, string $default = ''): string
{
    $v = $_POST[$k] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function get(string $k, string $default = ''): string
{
    $v = $_GET[$k] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

// ---------- CSRF ----------
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $t = $_POST['_csrf'] ?? ($_GET['t'] ?? '');
    if (!is_string($t) || !hash_equals(csrf_token(), $t)) {
        http_response_code(400);
        exit('Sessão expirada ou formulário inválido. Volte, recarregue a página e tente novamente.');
    }
}

// ---------- Mensagens de retorno ----------
function flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = [$type, $msg];
}

function flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

// ---------- Telefones ----------
/**
 * Normaliza para o formato internacional só com dígitos (ex.: 5585999998888).
 * Números com 10 ou 11 dígitos são tratados como brasileiros (DDD + número).
 */
function normalize_phone(string $raw): string
{
    $d = preg_replace('/\D+/', '', $raw) ?? '';
    $d = ltrim($d, '0');
    if ($d === '') {
        return '';
    }
    if (strlen($d) === 10 || strlen($d) === 11) {
        $d = '55' . $d;
    }
    if (strlen($d) < 11 || strlen($d) > 15) {
        return '';
    }
    return $d;
}

/** Variações do número brasileiro com e sem o nono dígito (o WhatsApp às vezes devolve sem). */
function phone_variants(string $p): array
{
    $v = [$p];
    if (str_starts_with($p, '55')) {
        if (strlen($p) === 13 && $p[4] === '9') {
            $v[] = substr($p, 0, 4) . substr($p, 5);
        } elseif (strlen($p) === 12 && in_array($p[4], ['6', '7', '8', '9'], true)) {
            $v[] = substr($p, 0, 4) . '9' . substr($p, 4);
        }
    }
    return array_values(array_unique($v));
}

function find_contact_by_phone(string $phone): ?array
{
    $vars = phone_variants($phone);
    $in = implode(',', array_fill(0, count($vars), '?'));
    $r = q("SELECT * FROM contatos WHERE telefone IN ($in) LIMIT 1", $vars)->fetch();
    return $r ?: null;
}

function format_phone(string $p): string
{
    if (str_starts_with($p, '55') && strlen($p) === 13) {
        return '+55 (' . substr($p, 2, 2) . ') ' . substr($p, 4, 5) . '-' . substr($p, 9);
    }
    if (str_starts_with($p, '55') && strlen($p) === 12) {
        return '+55 (' . substr($p, 2, 2) . ') ' . substr($p, 4, 4) . '-' . substr($p, 8);
    }
    return '+' . $p;
}

// ---------- Tags ----------
function normalize_tags(string $s): string
{
    $parts = preg_split('/[,;]+/', $s) ?: [];
    $out = [];
    foreach ($parts as $t) {
        $t = mb_strtolower(trim($t));
        $t = preg_replace('/\s+/', '-', $t) ?? '';
        if ($t !== '') {
            $out[$t] = true;
        }
    }
    return implode(',', array_keys($out));
}

function merge_tags(string $a, string $b): string
{
    return normalize_tags($a . ',' . $b);
}

function all_tags(): array
{
    $tags = [];
    foreach (q("SELECT DISTINCT tags FROM contatos WHERE tags <> ''")->fetchAll(PDO::FETCH_COLUMN) as $row) {
        foreach (explode(',', $row) as $t) {
            if ($t !== '') {
                $tags[$t] = true;
            }
        }
    }
    $tags = array_keys($tags);
    sort($tags);
    return $tags;
}

// ---------- Datas ----------
function fmt_dt(?string $dt): string
{
    if (!$dt) {
        return '—';
    }
    $t = strtotime($dt);
    return $t ? date('d/m/Y H:i', $t) : '—';
}

// ---------- Variáveis nas mensagens ----------
/**
 * Substitui {nome}, {primeiro_nome}, {telefone}, {campo1}, {campo2}, {campo3}.
 * Aceita valor padrão: {primeiro_nome|Cliente}
 */
function render_placeholders(string $tpl, array $contato): string
{
    $nome = trim((string)($contato['nome'] ?? ''));
    $vars = [
        'nome' => $nome,
        'primeiro_nome' => $nome !== '' ? explode(' ', $nome)[0] : '',
        'telefone' => (string)($contato['telefone'] ?? ''),
        'campo1' => (string)($contato['campo1'] ?? ''),
        'campo2' => (string)($contato['campo2'] ?? ''),
        'campo3' => (string)($contato['campo3'] ?? ''),
    ];
    $out = preg_replace_callback('/\{(\w+)(?:\|([^}]*))?\}/u', function (array $m) use ($vars): string {
        $k = mb_strtolower($m[1]);
        if (!array_key_exists($k, $vars)) {
            return $m[0];
        }
        $v = trim($vars[$k]);
        return $v !== '' ? $v : ($m[2] ?? '');
    }, $tpl);
    return trim($out ?? $tpl);
}

/** Texto aceito como parâmetro pela Meta: sem quebras de linha/tabs e sem 4+ espaços seguidos. */
function clean_param(string $s): string
{
    $s = preg_replace('/[\t\r\n]+/u', ' ', $s) ?? $s;
    $s = preg_replace('/ {4,}/', '   ', $s) ?? $s;
    $s = trim($s);
    return $s === '' ? '-' : mb_substr($s, 0, 1000);
}

function strip_accents(string $s): string
{
    return strtr($s, [
        'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'ê' => 'e', 'è' => 'e', 'í' => 'i', 'ì' => 'i',
        'ó' => 'o', 'õ' => 'o', 'ô' => 'o', 'ò' => 'o', 'ú' => 'u', 'ü' => 'u', 'ù' => 'u', 'ç' => 'c',
        'Á' => 'A', 'À' => 'A', 'Ã' => 'A', 'Â' => 'A', 'É' => 'E', 'Ê' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Õ' => 'O', 'Ô' => 'O',
        'Ú' => 'U', 'Ü' => 'U', 'Ç' => 'C',
    ]);
}

function is_optout_text(string $text): bool
{
    $norm = strtoupper(strip_accents(trim($text)));
    $norm = trim(preg_replace('/[^A-Z0-9 ]+/', '', $norm) ?? '');
    if ($norm === '') {
        return false;
    }
    $words = preg_split('/[,;\n]+/', (string)setting('palavras_optout', 'PARAR,SAIR,STOP,CANCELAR,DESCADASTRAR')) ?: [];
    foreach ($words as $w) {
        $w = strtoupper(strip_accents(trim($w)));
        if ($w !== '' && $norm === $w) {
            return true;
        }
    }
    return false;
}

/** Marca o contato como descadastrado e cancela o que estiver na fila para ele. */
function apply_optout(int $contatoId): void
{
    q('UPDATE contatos SET optout = 1, optout_em = NOW() WHERE id = ?', [$contatoId]);
    q("UPDATE campanha_mensagens SET status = 'cancelada', erro = 'Contato descadastrado', atualizado_em = NOW()
       WHERE contato_id = ? AND status = 'pendente'", [$contatoId]);
}

// ---------- Logs ----------
function log_event(string $nivel, string $msg, array $ctx = []): void
{
    try {
        q('INSERT INTO logs (nivel, mensagem, contexto, criado_em) VALUES (?, ?, ?, NOW())', [
            $nivel,
            mb_substr($msg, 0, 500),
            $ctx ? json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        ]);
    } catch (Throwable) {
        error_log('[disparador] ' . $msg);
    }
}

// ---------- CSV ----------
function csv_download(string $filename, array $header, iterable $rows): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM para o Excel abrir com acentos
    fputcsv($out, $header, ';', '"', '');
    foreach ($rows as $r) {
        fputcsv($out, array_values($r), ';', '"', '');
    }
    fclose($out);
    exit;
}
