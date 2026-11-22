<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

if (is_post()) {
    csrf_check();
    $atual = (string)($_POST['atual'] ?? '');
    $nova = (string)($_POST['nova'] ?? '');
    $conf = (string)($_POST['confirmar'] ?? '');
    if (!password_verify($atual, $user['senha_hash'])) {
        flash('erro', 'Senha atual incorreta.');
    } elseif ($nova !== $conf) {
        flash('erro', 'A confirmação não confere com a nova senha.');
    } elseif ($nova === $atual) {
        flash('erro', 'A nova senha precisa ser diferente da atual.');
    } elseif ($err = validate_password($nova)) {
        flash('erro', $err);
    } else {
        q('UPDATE usuarios SET senha_hash = ?, trocar_senha = 0 WHERE id = ?', [password_hash($nova, PASSWORD_DEFAULT), $user['id']]);
        session_regenerate_id(true);
        flash('sucesso', 'Senha alterada com sucesso.');
        redirect('index.php');
    }
    redirect('conta.php');
}

page_header('Minha conta', 'conta');
?>
<h1>Minha conta</h1>
<div class="card" style="max-width:480px">
  <dl class="kv small" style="margin-bottom:10px">
    <dt>Nome</dt><dd><?= e($user['nome']) ?></dd>
    <dt>Usuário</dt><dd><?= e($user['usuario']) ?></dd>
    <dt>Perfil</dt><dd><?= $user['perfil'] === 'admin' ? 'Administrador' : 'Operador' ?></dd>
  </dl>
  <h2 style="margin-top:18px">Alterar senha</h2>
  <form method="post">
    <?= csrf_field() ?>
    <label>Senha atual</label><input type="password" name="atual" autocomplete="current-password" required>
    <label>Nova senha</label><input type="password" name="nova" autocomplete="new-password" required>
    <div class="help">Mínimo de 8 caracteres, com letras e números.</div>
    <label>Confirmar nova senha</label><input type="password" name="confirmar" autocomplete="new-password" required>
    <div class="actions"><button class="btn" type="submit">Salvar nova senha</button></div>
  </form>
</div>
<?php page_footer();
