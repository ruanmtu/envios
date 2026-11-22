<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

if (current_user()) {
    redirect('index.php');
}

$erro = null;
$usuario = '';
if (is_post()) {
    csrf_check();
    $usuario = post('usuario');
    $r = attempt_login($usuario, (string)($_POST['senha'] ?? ''));
    if ($r === true) {
        redirect('index.php');
    }
    $erro = $r;
}
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Entrar · <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="assets/style.css?v=<?= e(APP_VERSION) ?>">
</head>
<body>
<div class="login-box">
  <h1>💬 <?= e(APP_NAME) ?></h1>
  <?php if (get('expirou')): ?><div class="alert aviso">Sua sessão expirou. Entre novamente.</div><?php endif; ?>
  <?php if (get('saiu')): ?><div class="alert info">Você saiu do sistema.</div><?php endif; ?>
  <?php if ($erro): ?><div class="alert erro"><?= e($erro) ?></div><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <label>Usuário</label>
    <input type="text" name="usuario" value="<?= e($usuario) ?>" autocomplete="username" autofocus required>
    <label>Senha</label>
    <input type="password" name="senha" autocomplete="current-password" required>
    <div class="actions"><button class="btn" type="submit" style="width:100%">Entrar</button></div>
  </form>
</div>
</body>
</html>
