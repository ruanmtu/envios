<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
$user = require_admin();

if (is_post()) {
    csrf_check();
    $n = q('DELETE FROM logs WHERE criado_em < (NOW() - INTERVAL 30 DAY)')->rowCount();
    flash('sucesso', "$n registro(s) antigos removidos.");
    redirect('logs.php');
}

$nivel = get('nivel');
$params = [];
$where = '';
if (in_array($nivel, ['info', 'aviso', 'erro'], true)) {
    $where = 'WHERE nivel = ?';
    $params[] = $nivel;
}
$rows = q("SELECT * FROM logs $where ORDER BY id DESC LIMIT 300", $params)->fetchAll();
$cores = ['info' => 'azul', 'aviso' => 'amarelo', 'erro' => 'vermelho'];

page_header('Logs', 'logs');
?>
<div class="head">
  <h1>Logs do sistema</h1>
  <form method="post" class="inline" data-confirm="Apagar registros com mais de 30 dias?"><?= csrf_field() ?>
    <button class="btn sec sm" type="submit">Limpar antigos</button></form>
</div>
<form method="get" class="toolbar">
  <select name="nivel" onchange="this.form.submit()" style="width:auto">
    <option value="">Todos</option>
    <option value="info" <?= $nivel === 'info' ? 'selected' : '' ?>>Informação</option>
    <option value="aviso" <?= $nivel === 'aviso' ? 'selected' : '' ?>>Aviso</option>
    <option value="erro" <?= $nivel === 'erro' ? 'selected' : '' ?>>Erro</option>
  </select>
</form>
<div class="card">
  <?php if (!$rows): ?><p class="muted">Nenhum registro.</p><?php else: ?>
  <div class="table-wrap"><table>
    <tr><th>Quando</th><th>Nível</th><th>Mensagem</th></tr>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="nowrap small"><?= e(fmt_dt($r['criado_em'])) ?></td>
        <td><span class="badge <?= $cores[$r['nivel']] ?? '' ?>"><?= e($r['nivel']) ?></span></td>
        <td class="small"><?= e($r['mensagem']) ?>
          <?php if ($r['contexto']): ?><div class="muted" style="word-break:break-all"><?= e(mb_strimwidth($r['contexto'], 0, 300, '…')) ?></div><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>
</div>
<?php page_footer();
