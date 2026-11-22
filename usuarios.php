<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
$user = require_admin();

if (is_post()) {
    csrf_check();
    $op = post('op');

    if ($op === 'criar') {
        $nome = mb_substr(post('nome'), 0, 100);
        $login = mb_strtolower(post('usuario'));
        $senha = (string)($_POST['senha'] ?? '');
        $perfil = post('perfil') === 'admin' ? 'admin' : 'operador';
        if ($nome === '' || !preg_match('/^[a-z0-9._-]{3,60}$/', $login)) {
            flash('erro', 'Informe o nome e um usuário válido (letras minúsculas, números, ponto, hífen ou underline; mínimo 3).');
        } elseif ($err = validate_password($senha)) {
            flash('erro', $err);
        } elseif (q('SELECT id FROM usuarios WHERE usuario = ?', [$login])->fetch()) {
            flash('erro', 'Esse usuário já existe.');
        } else {
            q('INSERT INTO usuarios (nome, usuario, senha_hash, perfil, ativo, trocar_senha, criado_em) VALUES (?,?,?,?,1,1,NOW())',
                [$nome, $login, password_hash($senha, PASSWORD_DEFAULT), $perfil]);
            log_event('info', "Usuário $login criado por {$user['usuario']}");
            flash('sucesso', "Usuário $login criado. Ele deverá trocar a senha no primeiro acesso.");
        }
        redirect('usuarios.php');
    }

    $id = (int)post('id');
    $alvo = q('SELECT * FROM usuarios WHERE id = ?', [$id])->fetch();
    if (!$alvo) {
        redirect('usuarios.php');
    }
    $admins = (int)q("SELECT COUNT(*) FROM usuarios WHERE perfil = 'admin' AND ativo = 1")->fetchColumn();
    $ultimoAdmin = $alvo['perfil'] === 'admin' && (int)$alvo['ativo'] === 1 && $admins <= 1;

    if ($op === 'senha') {
        $senha = (string)($_POST['senha'] ?? '');
        if ($err = validate_password($senha)) {
            flash('erro', $err);
        } else {
            q('UPDATE usuarios SET senha_hash = ?, trocar_senha = 1 WHERE id = ?', [password_hash($senha, PASSWORD_DEFAULT), $id]);
            flash('sucesso', "Senha de {$alvo['usuario']} redefinida. Ele deverá trocá-la no próximo acesso.");
        }
    } elseif ($op === 'ativo') {
        if ($id === (int)$user['id'] || $ultimoAdmin) {
            flash('erro', 'Você não pode desativar a si mesmo nem o último administrador.');
        } else {
            q('UPDATE usuarios SET ativo = 1 - ativo WHERE id = ?', [$id]);
            flash('sucesso', 'Situação do usuário alterada.');
        }
    } elseif ($op === 'perfil') {
        $novo = $alvo['perfil'] === 'admin' ? 'operador' : 'admin';
        if ($novo === 'operador' && ($ultimoAdmin || $id === (int)$user['id'])) {
            flash('erro', 'Você não pode rebaixar a si mesmo nem o último administrador.');
        } else {
            q('UPDATE usuarios SET perfil = ? WHERE id = ?', [$novo, $id]);
            flash('sucesso', 'Perfil alterado.');
        }
    } elseif ($op === 'excluir') {
        if ($id === (int)$user['id'] || $ultimoAdmin) {
            flash('erro', 'Você não pode excluir a si mesmo nem o último administrador.');
        } else {
            q('DELETE FROM usuarios WHERE id = ?', [$id]);
            log_event('info', "Usuário {$alvo['usuario']} excluído por {$user['usuario']}");
            flash('sucesso', 'Usuário excluído.');
        }
    }
    redirect('usuarios.php');
}

$rows = q('SELECT * FROM usuarios ORDER BY nome')->fetchAll();
page_header('Usuários', 'usuarios');
?>
<h1>Usuários</h1>
<div class="card">
  <div class="table-wrap"><table>
    <tr><th>Nome</th><th>Usuário</th><th>Perfil</th><th>Situação</th><th>Último acesso</th><th>Ações</th></tr>
    <?php foreach ($rows as $u): ?>
      <tr>
        <td><?= e($u['nome']) ?><?= (int)$u['id'] === (int)$user['id'] ? ' <span class="muted small">(você)</span>' : '' ?></td>
        <td><?= e($u['usuario']) ?></td>
        <td><?= $u['perfil'] === 'admin' ? '<span class="badge azul">Administrador</span>' : '<span class="badge">Operador</span>' ?></td>
        <td><?= $u['ativo'] ? '<span class="badge verde">Ativo</span>' : '<span class="badge vermelho">Inativo</span>' ?></td>
        <td class="small nowrap"><?= e(fmt_dt($u['ultimo_login'])) ?></td>
        <td>
          <form method="post" class="inline toolbar" style="margin:0 0 6px">
            <?= csrf_field() ?><input type="hidden" name="op" value="senha"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
            <input type="text" name="senha" placeholder="Nova senha" style="width:140px" required>
            <button class="btn sec sm" type="submit">Redefinir</button>
          </form>
          <?php if ((int)$u['id'] !== (int)$user['id']): ?>
          <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="op" value="perfil"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
            <button class="btn sec sm" type="submit"><?= $u['perfil'] === 'admin' ? 'Tornar operador' : 'Tornar admin' ?></button></form>
          <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="op" value="ativo"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
            <button class="btn sec sm" type="submit"><?= $u['ativo'] ? 'Desativar' : 'Ativar' ?></button></form>
          <form method="post" class="inline" data-confirm="Excluir o usuário <?= e($u['usuario']) ?>?"><?= csrf_field() ?><input type="hidden" name="op" value="excluir"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
            <button class="btn danger sm" type="submit">Excluir</button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </table></div>
</div>

<div class="card">
  <h2>Novo usuário</h2>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="op" value="criar">
    <div class="row">
      <div><label>Nome</label><input type="text" name="nome" required></div>
      <div><label>Usuário (login)</label><input type="text" name="usuario" required placeholder="ex.: maria"></div>
      <div><label>Senha inicial</label><input type="text" name="senha" required></div>
      <div><label>Perfil</label><select name="perfil"><option value="operador">Operador (contatos, campanhas, conversas)</option><option value="admin">Administrador (tudo)</option></select></div>
    </div>
    <div class="actions"><button class="btn" type="submit">Criar usuário</button></div>
  </form>
</div>
<?php page_footer();
