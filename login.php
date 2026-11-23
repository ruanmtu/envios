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
<meta name="theme-color" content="#063b33">
<title>Entrar · <?= e(APP_NAME) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css?v=<?= e(APP_VERSION) ?>">
</head>
<body>
<div class="auth">
  <section class="auth-hero">
    <div class="brand"><?= brand_logo(40) ?><span><strong>Disparador</strong><small>WhatsApp Cloud API</small></span></div>
    <div>
      <h2>Campanhas no WhatsApp, do jeito certo.</h2>
      <p>Envie mensagens em massa pela API oficial da Meta, com controle de opt-in, fila automática e relatórios de entrega.</p>
      <ul class="feat">
        <li><span><?= icon('zap') ?></span>Fila automática respeitando os limites da Meta</li>
        <li><span><?= icon('check') ?></span>Status de envio, entrega e leitura em tempo real</li>
        <li><span><?= icon('usuarios') ?></span>Descadastro automático e controle de opt-in</li>
      </ul>
    </div>
    <div class="bubble">Olá, Maria! Sua oferta exclusiva já está disponível 🎉<small>14:32 ✓✓</small></div>
  </section>

  <section class="auth-form">
    <div class="inner">
      <div class="auth-mobile-brand"><span class="brand"><?= brand_logo(40) ?><span><strong>Disparador</strong><small>WhatsApp Cloud API</small></span></span></div>
      <h1>Bem-vindo de volta</h1>
      <p class="sub">Entre com seu usuário e senha para acessar o painel.</p>
      <?php if (get('expirou')): ?><div class="alert aviso"><span class="alert-ico"><?= icon('clock') ?></span><div>Sua sessão expirou. Entre novamente.</div></div><?php endif; ?>
      <?php if (get('saiu')): ?><div class="alert info"><span class="alert-ico"><?= icon('check') ?></span><div>Você saiu do sistema.</div></div><?php endif; ?>
      <?php if ($erro): ?><div class="alert erro"><span class="alert-ico"><?= icon('alert') ?></span><div><?= e($erro) ?></div></div><?php endif; ?>
      <form method="post">
        <?= csrf_field() ?>
        <label>Usuário</label>
        <input type="text" name="usuario" value="<?= e($usuario) ?>" autocomplete="username" autocapitalize="none" autofocus required placeholder="seu.usuario">
        <label>Senha</label>
        <input type="password" name="senha" autocomplete="current-password" required placeholder="••••••••">
        <div class="actions"><button class="btn" type="submit">Entrar no painel</button></div>
      </form>
      <p class="muted small" style="text-align:center;margin-top:28px">API oficial WhatsApp Cloud · Meta</p>
    </div>
  </section>
</div>
</body>
</html>
