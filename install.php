<?php
/**
 * Instalador do Disparador WhatsApp.
 * Acesse uma única vez pelo navegador e depois EXCLUA este arquivo.
 */
declare(strict_types=1);
ini_set('display_errors', '0');

$root = __DIR__;
$cfgPath = $root . '/includes/config.php';
$lockPath = $root . '/includes/install.lock';

function h(mixed $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

$fusos = [
    'America/Fortaleza' => 'Fortaleza / Nordeste (UTC-3)',
    'America/Sao_Paulo' => 'Brasília / São Paulo (UTC-3)',
    'America/Recife' => 'Recife (UTC-3)',
    'America/Bahia' => 'Salvador (UTC-3)',
    'America/Belem' => 'Belém (UTC-3)',
    'America/Manaus' => 'Manaus (UTC-4)',
    'America/Cuiaba' => 'Cuiabá (UTC-4)',
    'America/Porto_Velho' => 'Porto Velho (UTC-4)',
    'America/Rio_Branco' => 'Rio Branco (UTC-5)',
    'America/Noronha' => 'Fernando de Noronha (UTC-2)',
];

$v = [
    'db_host' => 'localhost',
    'db_name' => '',
    'db_user' => '',
    'db_pass' => '',
    'admin_nome' => 'Administrador',
    'admin_usuario' => 'admin',
    'admin_senha' => '',
    'timezone' => 'America/Fortaleza',
];
$erro = null;
$ok = false;
$jaInstalado = is_file($lockPath);

if (!$jaInstalado && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    foreach ($v as $k => $_) {
        $v[$k] = trim((string)($_POST[$k] ?? ''));
    }
    $v['admin_usuario'] = mb_strtolower($v['admin_usuario']);

    if (version_compare(PHP_VERSION, '8.1.0', '<')) {
        $erro = 'É necessário PHP 8.1 ou superior (atual: ' . PHP_VERSION . '). Altere em hPanel → Avançado → Configuração do PHP.';
    } elseif (!extension_loaded('pdo_mysql') || !extension_loaded('curl')) {
        $erro = 'As extensões pdo_mysql e curl precisam estar ativas no PHP.';
    } elseif ($v['db_name'] === '' || $v['db_user'] === '') {
        $erro = 'Preencha os dados do banco de dados.';
    } elseif (!preg_match('/^[a-z0-9._-]{3,60}$/', $v['admin_usuario'])) {
        $erro = 'Usuário do administrador inválido (use letras minúsculas, números, ponto, hífen ou underline).';
    } elseif (strlen($v['admin_senha']) < 8 || !preg_match('/[A-Za-z]/', $v['admin_senha']) || !preg_match('/\d/', $v['admin_senha'])) {
        $erro = 'A senha do administrador precisa ter pelo menos 8 caracteres, com letras e números.';
    } elseif (!isset($fusos[$v['timezone']])) {
        $erro = 'Fuso horário inválido.';
    } else {
        try {
            date_default_timezone_set($v['timezone']);
            $pdo = new PDO(
                'mysql:host=' . $v['db_host'] . ';dbname=' . $v['db_name'] . ';charset=utf8mb4',
                $v['db_user'],
                $v['db_pass'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $sql = (string)file_get_contents($root . '/includes/schema.sql');
            $sql = preg_replace('/^--.*$/m', '', $sql) ?? $sql;
            foreach (preg_split('/;\s*(\r?\n|$)/', $sql) ?: [] as $stmt) {
                if (trim($stmt) !== '') {
                    $pdo->exec($stmt);
                }
            }

            $st = $pdo->prepare('INSERT INTO usuarios (nome, usuario, senha_hash, perfil, ativo, trocar_senha, criado_em)
                VALUES (?, ?, ?, \'admin\', 1, 1, NOW())
                ON DUPLICATE KEY UPDATE senha_hash = VALUES(senha_hash), perfil = \'admin\', ativo = 1, trocar_senha = 1');
            $st->execute([$v['admin_nome'] ?: 'Administrador', $v['admin_usuario'], password_hash($v['admin_senha'], PASSWORD_DEFAULT)]);

            $defaults = [
                'graph_version' => 'v23.0',
                'limite_diario' => '250',
                'lote_por_minuto' => '30',
                'intervalo_ms' => '300',
                'palavras_optout' => 'PARAR,SAIR,STOP,CANCELAR,DESCADASTRAR',
                'optout_resposta' => 'Pronto! Você não receberá mais nossas mensagens promocionais. Se mudar de ideia, é só nos chamar.',
                'cron_chave' => bin2hex(random_bytes(20)),
                'webhook_verify_token' => bin2hex(random_bytes(16)),
            ];
            $st = $pdo->prepare('INSERT IGNORE INTO configuracoes (chave, valor) VALUES (?, ?)');
            foreach ($defaults as $k => $val) {
                $st->execute([$k, $val]);
            }

            $cfg = "<?php\n// Gerado pelo instalador em " . date('d/m/Y H:i') . ". Não compartilhe este arquivo.\n"
                . 'define(\'DB_HOST\', ' . var_export($v['db_host'], true) . ");\n"
                . 'define(\'DB_NAME\', ' . var_export($v['db_name'], true) . ");\n"
                . 'define(\'DB_USER\', ' . var_export($v['db_user'], true) . ");\n"
                . 'define(\'DB_PASS\', ' . var_export($v['db_pass'], true) . ");\n"
                . 'define(\'APP_TIMEZONE\', ' . var_export($v['timezone'], true) . ");\n"
                . "define('APP_KEY', '" . bin2hex(random_bytes(32)) . "');\n";
            if (@file_put_contents($cfgPath, $cfg) === false) {
                throw new RuntimeException('Não foi possível gravar includes/config.php. Verifique as permissões da pasta "includes" (755).');
            }
            @chmod($cfgPath, 0640);
            file_put_contents($lockPath, date('c'));
            $ok = true;
        } catch (PDOException $ex) {
            $erro = 'Não foi possível conectar/criar as tabelas no banco: ' . $ex->getMessage();
        } catch (Throwable $ex) {
            $erro = $ex->getMessage();
        }
    }
}
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Instalação · Disparador WhatsApp</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="login-box" style="max-width:560px">
  <h1>💬 Instalação</h1>
  <?php if ($jaInstalado): ?>
    <div class="alert aviso">O sistema já está instalado. Por segurança, <strong>exclua o arquivo install.php</strong> do servidor.</div>
    <p><a class="btn" href="login.php">Ir para o login</a></p>
  <?php elseif ($ok): ?>
    <div class="alert sucesso"><strong>Instalação concluída!</strong></div>
    <ol>
      <li><strong>Exclua o arquivo <code>install.php</code></strong> pelo Gerenciador de Arquivos.</li>
      <li>Entre com o usuário <code><?= h($v['admin_usuario']) ?></code> e a senha que você definiu. No primeiro acesso, o sistema pedirá uma nova senha.</li>
      <li>Vá em <strong>Configurações</strong> e informe os dados da API da Meta.</li>
      <li>Configure o <strong>Cron Job</strong> (o caminho aparece em Configurações).</li>
    </ol>
    <p><a class="btn" href="login.php">Ir para o login</a></p>
  <?php else: ?>
    <?php if ($erro): ?><div class="alert erro"><?= h($erro) ?></div><?php endif; ?>
    <p class="muted small">Crie antes um banco MySQL em <strong>hPanel → Bancos de dados → Gerenciamento</strong> e informe os dados abaixo.</p>
    <form method="post" autocomplete="off">
      <h2 style="margin-top:18px">Banco de dados</h2>
      <label>Servidor (host)</label>
      <input type="text" name="db_host" value="<?= h($v['db_host']) ?>" required>
      <div class="help">Na Hostinger normalmente é <code>localhost</code>.</div>
      <label>Nome do banco</label>
      <input type="text" name="db_name" value="<?= h($v['db_name']) ?>" placeholder="u123456789_disparador" required>
      <label>Usuário do banco</label>
      <input type="text" name="db_user" value="<?= h($v['db_user']) ?>" placeholder="u123456789_admin" required>
      <label>Senha do banco</label>
      <input type="password" name="db_pass" value="<?= h($v['db_pass']) ?>">

      <h2 style="margin-top:22px">Administrador</h2>
      <label>Nome</label>
      <input type="text" name="admin_nome" value="<?= h($v['admin_nome']) ?>" required>
      <label>Usuário de acesso</label>
      <input type="text" name="admin_usuario" value="<?= h($v['admin_usuario']) ?>" required>
      <label>Senha inicial</label>
      <input type="password" name="admin_senha" value="" autocomplete="new-password" required>
      <div class="help">Crie uma senha forte (mín. 8 caracteres, com letras e números) e anote-a. O sistema pedirá uma nova senha no primeiro acesso.</div>

      <label>Fuso horário</label>
      <select name="timezone">
        <?php foreach ($fusos as $tz => $label): ?>
          <option value="<?= h($tz) ?>" <?= $v['timezone'] === $tz ? 'selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>

      <div class="actions"><button class="btn" type="submit">Instalar</button></div>
    </form>
  <?php endif; ?>
</div>
</body>
</html>
