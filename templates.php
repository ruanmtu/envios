<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

if (is_post()) {
    csrf_check();
    if (post('op') === 'sincronizar') {
        $wa = WhatsApp::fromSettings();
        if (!$wa->configured()) {
            flash('erro', 'Configure a API da Meta antes de sincronizar.');
            redirect('templates.php');
        }
        $r = $wa->listTemplates();
        if (!$r['ok']) {
            flash('erro', 'Erro ao buscar templates: ' . $r['error']['message']);
            log_event('erro', 'Falha ao sincronizar templates', $r['error']);
            redirect('templates.php');
        }
        $ids = [];
        $st = db()->prepare('INSERT INTO templates (meta_id, nome, idioma, categoria, status, parametro_formato, componentes, atualizado_em)
            VALUES (?,?,?,?,?,?,?,NOW())
            ON DUPLICATE KEY UPDATE meta_id=VALUES(meta_id), categoria=VALUES(categoria), status=VALUES(status),
              parametro_formato=VALUES(parametro_formato), componentes=VALUES(componentes), atualizado_em=NOW()');
        foreach ($r['data'] as $t) {
            $st->execute([
                (string)($t['id'] ?? ''),
                (string)$t['name'],
                (string)$t['language'],
                (string)($t['category'] ?? ''),
                (string)($t['status'] ?? ''),
                (string)($t['parameter_format'] ?? ''),
                json_encode($t['components'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            $ids[] = (string)($t['id'] ?? '');
        }
        // remove os que não existem mais na Meta
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            q("DELETE FROM templates WHERE meta_id NOT IN ($in)", $ids);
        } else {
            q('DELETE FROM templates');
        }
        flash('sucesso', count($r['data']) . ' template(s) sincronizado(s).');
        redirect('templates.php');
    }
}

$rows = q("SELECT * FROM templates ORDER BY status = 'APPROVED' DESC, nome")->fetchAll();
$cat = ['MARKETING' => 'Marketing', 'UTILITY' => 'Utilidade', 'AUTHENTICATION' => 'Autenticação'];

page_header('Templates', 'templates');
?>
<div class="head">
  <h1>Templates de mensagem</h1>
  <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="op" value="sincronizar">
    <button class="btn" type="submit">⟳ Sincronizar com a Meta</button></form>
</div>
<div class="alert info">Para iniciar conversas em massa, o WhatsApp exige <strong>templates aprovados</strong> pela Meta.
  Crie-os no <a href="https://business.facebook.com/wa/manage/message-templates/" target="_blank" rel="noopener">WhatsApp Manager</a>
  e depois clique em <strong>Sincronizar</strong>. A aprovação costuma levar de minutos a algumas horas.</div>

<div class="card">
  <?php if (!$rows): ?>
    <p class="muted">Nenhum template sincronizado ainda.</p>
  <?php else: ?>
  <div class="table-wrap"><table>
    <tr><th>Nome</th><th>Idioma</th><th>Categoria</th><th>Status</th><th>Texto</th><th></th></tr>
    <?php foreach ($rows as $t):
        $f = template_fields(json_decode((string)$t['componentes'], true) ?: []); ?>
      <tr>
        <td><strong><?= e($t['nome']) ?></strong></td>
        <td><?= e($t['idioma']) ?></td>
        <td><?= e($cat[$t['categoria']] ?? $t['categoria']) ?></td>
        <td><?= badge((string)$t['status']) ?></td>
        <td class="small" style="max-width:420px"><?= nl2br(e(mb_strimwidth($f['body_text'], 0, 220, '…'))) ?></td>
        <td><?php if ($t['status'] === 'APPROVED'): ?>
          <a class="btn sm" href="campanhas.php?acao=nova&template_id=<?= (int)$t['id'] ?>">Usar</a>
        <?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>
</div>
<?php page_footer();
