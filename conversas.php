<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

$tel = preg_replace('/\D+/', '', get('tel') ?: post('tel')) ?? '';

if (is_post() && $tel !== '') {
    csrf_check();
    $op = post('op');
    $contato = find_contact_by_phone($tel);

    if ($op === 'responder') {
        $texto = trim((string)($_POST['texto'] ?? ''));
        $ultima = q("SELECT MAX(criado_em) FROM conversas WHERE telefone = ? AND direcao = 'entrada'", [$tel])->fetchColumn();
        if ($texto === '') {
            flash('erro', 'Digite uma mensagem.');
        } elseif (!$ultima || strtotime((string)$ultima) < time() - 24 * 3600) {
            flash('erro', 'A janela de 24h desse contato está fechada. Só é possível iniciar a conversa com um template aprovado (crie uma campanha).');
        } else {
            $r = WhatsApp::fromSettings()->sendText($tel, mb_substr($texto, 0, 4096));
            if ($r['ok']) {
                q('INSERT INTO conversas (contato_id, telefone, direcao, tipo, texto, wamid, status, lida, usuario_id, criado_em)
                   VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, NOW())', [
                    $contato['id'] ?? null, $tel, 'saida', 'text', $texto, $r['data']['messages'][0]['id'] ?? null, 'enviada', $user['id'],
                ]);
            } else {
                flash('erro', 'Não foi possível enviar (código ' . $r['error']['code'] . '): ' . $r['error']['message']);
            }
        }
    }

    if ($op === 'optout' && $contato) {
        apply_optout((int)$contato['id']);
        flash('sucesso', 'Contato descadastrado.');
    }
    redirect('conversas.php?tel=' . $tel);
}

// ===================== CONVERSA =====================
if ($tel !== '') {
    q("UPDATE conversas SET lida = 1 WHERE telefone = ? AND direcao = 'entrada' AND lida = 0", [$tel]);
    $msgs = q('SELECT * FROM (SELECT * FROM conversas WHERE telefone = ? ORDER BY id DESC LIMIT 200) t ORDER BY id', [$tel])->fetchAll();
    $contato = find_contact_by_phone($tel);
    $perfil = '';
    $ultimaEntrada = null;
    foreach ($msgs as $m) {
        if ($m['direcao'] === 'entrada') {
            $perfil = $m['nome_perfil'] ?: $perfil;
            $ultimaEntrada = $m['criado_em'];
        }
    }
    $janelaAberta = $ultimaEntrada && strtotime($ultimaEntrada) >= time() - 24 * 3600;
    $titulo = ($contato['nome'] ?? '') ?: ($perfil ?: format_phone($tel));

    page_header('Conversa', 'conversas');
    ?>
    <div class="head">
      <h1><?= e($titulo) ?> <span class="muted small"><?= e(format_phone($tel)) ?></span></h1>
      <a class="btn sec" href="conversas.php">← Conversas</a>
    </div>
    <?php if ($contato): ?>
      <p class="small">
        <?= $contato['optout'] ? '<span class="badge vermelho">Descadastrado</span>' : ($contato['optin'] ? '<span class="badge verde">Apto</span>' : '<span class="badge amarelo">Sem opt-in</span>') ?>
        <a href="contatos.php?acao=editar&id=<?= (int)$contato['id'] ?>">Ver contato</a>
      </p>
    <?php else: ?>
      <p class="small muted">Este número não está na sua lista de contatos. <a href="contatos.php?acao=novo">Cadastrar</a></p>
    <?php endif; ?>

    <div class="chat" id="chat">
      <?php foreach ($msgs as $m): ?>
        <div class="msg <?= e($m['direcao']) ?>"><?= e($m['texto']) ?>
          <small><?= e(fmt_dt($m['criado_em'])) ?><?= $m['direcao'] === 'saida' && $m['status'] ? ' · ' . e(status_label($m['status'])[0]) : '' ?></small></div>
      <?php endforeach; ?>
    </div>

    <div class="card" style="margin-top:14px">
      <?php if ($janelaAberta): ?>
        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="op" value="responder"><input type="hidden" name="tel" value="<?= e($tel) ?>">
          <label style="margin-top:0">Responder</label>
          <textarea name="texto" required maxlength="4096" placeholder="Digite sua resposta…"></textarea>
          <div class="actions"><button class="btn" type="submit">Enviar</button></div>
          <p class="help">Janela de atendimento aberta até <?= e(fmt_dt(date('Y-m-d H:i:s', strtotime((string)$ultimaEntrada) + 24 * 3600))) ?>. Dentro dela, você pode responder com texto livre.</p>
        </form>
      <?php else: ?>
        <p class="muted" style="margin:0">A janela de 24h está fechada. Para falar de novo com este contato, use um template aprovado (campanha).</p>
      <?php endif; ?>
      <?php if ($contato && !$contato['optout']): ?>
        <form method="post" data-confirm="Descadastrar este contato das campanhas?" style="margin-top:12px">
          <?= csrf_field() ?><input type="hidden" name="op" value="optout"><input type="hidden" name="tel" value="<?= e($tel) ?>">
          <button class="btn sec sm" type="submit">Descadastrar contato</button></form>
      <?php endif; ?>
    </div>
    <script>var c=document.getElementById('chat');c.scrollTop=c.scrollHeight;</script>
    <?php
    page_footer();
    exit;
}

// ===================== LISTA =====================
$lista = q("SELECT t.telefone, t.ultimo_id, t.nao_lidas, cv.texto, cv.direcao, cv.criado_em, cv.nome_perfil, ct.nome
            FROM (SELECT telefone, MAX(id) AS ultimo_id, SUM(direcao = 'entrada' AND lida = 0) AS nao_lidas
                  FROM conversas GROUP BY telefone) t
            JOIN conversas cv ON cv.id = t.ultimo_id
            LEFT JOIN contatos ct ON ct.telefone = t.telefone
            ORDER BY t.ultimo_id DESC LIMIT 200")->fetchAll();

page_header('Conversas', 'conversas');
?>
<h1>Conversas</h1>
<p class="muted small">Respostas que os contatos enviaram para o seu número. Quem responder com uma palavra de descadastro (ex.: PARAR) é removido das campanhas automaticamente.</p>
<div class="card">
  <?php if (!$lista): ?>
    <p class="muted">Nenhuma mensagem recebida ainda. Confira se o webhook está configurado na Meta.</p>
  <?php else: ?>
  <div class="table-wrap"><table>
    <tr><th>Contato</th><th>Última mensagem</th><th>Quando</th><th></th></tr>
    <?php foreach ($lista as $l): ?>
      <tr class="<?= $l['nao_lidas'] > 0 ? 'unread' : '' ?>">
        <td><?= e($l['nome'] ?: ($l['nome_perfil'] ?: '—')) ?><div class="small muted"><?= e(format_phone($l['telefone'])) ?></div></td>
        <td class="small"><?= $l['direcao'] === 'saida' ? '<span class="muted">Você: </span>' : '' ?><?= e(mb_strimwidth((string)$l['texto'], 0, 90, '…')) ?></td>
        <td class="nowrap small"><?= e(fmt_dt($l['criado_em'])) ?></td>
        <td><a class="btn sm <?= $l['nao_lidas'] > 0 ? '' : 'sec' ?>" href="conversas.php?tel=<?= e($l['telefone']) ?>">Abrir<?= $l['nao_lidas'] > 0 ? ' (' . (int)$l['nao_lidas'] . ')' : '' ?></a></td>
      </tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>
</div>
<?php page_footer();
