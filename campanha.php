<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

$id = (int)(get('id') ?: post('id'));
$c = q('SELECT * FROM campanhas WHERE id = ?', [$id])->fetch();
if (!$c) {
    flash('erro', 'Campanha não encontrada.');
    redirect('campanhas.php');
}
$cfg = json_decode((string)$c['config'], true) ?: [];
$self = 'campanha.php?id=' . $id;

function eligible_sql(?string $tag): array
{
    $sql = 'FROM contatos WHERE optin = 1 AND optout = 0';
    $p = [];
    if ($tag) {
        $sql .= ' AND FIND_IN_SET(?, tags)';
        $p[] = $tag;
    }
    return [$sql, $p];
}

// ===================== AÇÕES =====================
if (is_post()) {
    csrf_check();
    $op = post('op');

    if ($op === 'iniciar' && $c['status'] === 'rascunho') {
        if (!WhatsApp::fromSettings()->configured()) {
            flash('erro', 'Configure a API da Meta antes de iniciar o envio.');
            redirect($self);
        }
        [$from, $p] = eligible_sql($c['filtro_tag']);
        $st = q("INSERT IGNORE INTO campanha_mensagens (campanha_id, contato_id, telefone, status, atualizado_em)
                 SELECT ?, id, telefone, 'pendente', NOW() $from", array_merge([$id], $p));
        $n = $st->rowCount();
        if ($n === 0) {
            flash('erro', 'Nenhum contato apto (com opt-in) para essa campanha.');
            redirect($self);
        }
        $agendada = $c['agendar_para'] && strtotime($c['agendar_para']) > time();
        q('UPDATE campanhas SET total = ?, status = ?, iniciado_em = ? WHERE id = ?', [
            $n, $agendada ? 'agendada' : 'enviando', $agendada ? null : date('Y-m-d H:i:s'), $id,
        ]);
        log_event('info', "Campanha #$id \"{$c['nome']}\" iniciada por {$user['usuario']} com $n contatos");
        flash('sucesso', $agendada
            ? "Campanha agendada para " . fmt_dt($c['agendar_para']) . " com $n contatos."
            : "Envio iniciado para $n contatos. As mensagens saem em lotes a cada minuto pelo Cron.");
        redirect($self);
    }

    if ($op === 'pausar' && in_array($c['status'], ['enviando', 'agendada'], true)) {
        q("UPDATE campanhas SET status = 'pausada' WHERE id = ?", [$id]);
        flash('sucesso', 'Campanha pausada.');
        redirect($self);
    }

    if ($op === 'retomar' && $c['status'] === 'pausada') {
        q("UPDATE campanhas SET status = 'enviando', iniciado_em = COALESCE(iniciado_em, NOW()) WHERE id = ?", [$id]);
        flash('sucesso', 'Envio retomado.');
        redirect($self);
    }

    if ($op === 'cancelar' && in_array($c['status'], ['enviando', 'agendada', 'pausada'], true)) {
        q("UPDATE campanha_mensagens SET status = 'cancelada', erro = 'Campanha cancelada', atualizado_em = NOW() WHERE campanha_id = ? AND status = 'pendente'", [$id]);
        q("UPDATE campanhas SET status = 'cancelada', concluido_em = NOW() WHERE id = ?", [$id]);
        log_event('info', "Campanha #$id cancelada por {$user['usuario']}");
        flash('sucesso', 'Campanha cancelada. As mensagens que ainda estavam na fila não serão enviadas.');
        redirect($self);
    }

    if ($op === 'reenviar_falhas' && in_array($c['status'], ['concluida', 'pausada', 'enviando'], true)) {
        $n = q("UPDATE campanha_mensagens m JOIN contatos ct ON ct.id = m.contato_id
                SET m.status = 'pendente', m.tentativas = 0, m.erro = NULL, m.erro_codigo = NULL, m.wamid = NULL, m.atualizado_em = NOW()
                WHERE m.campanha_id = ? AND m.status = 'falhou' AND ct.optout = 0 AND ct.optin = 1", [$id])->rowCount();
        if ($n > 0) {
            q("UPDATE campanhas SET status = 'enviando', concluido_em = NULL WHERE id = ?", [$id]);
        }
        flash('sucesso', "$n mensagem(ns) com falha voltaram para a fila.");
        redirect($self);
    }

    if ($op === 'excluir' && in_array($c['status'], ['rascunho', 'concluida', 'cancelada'], true)) {
        q('DELETE FROM campanha_mensagens WHERE campanha_id = ?', [$id]);
        q('DELETE FROM campanhas WHERE id = ?', [$id]);
        flash('sucesso', 'Campanha excluída.');
        redirect('campanhas.php');
    }

    if ($op === 'teste') {
        $tel = normalize_phone(post('telefone'));
        if ($tel === '') {
            flash('erro', 'Telefone de teste inválido.');
            redirect($self);
        }
        $wa = WhatsApp::fromSettings();
        if (!$wa->configured()) {
            flash('erro', 'Configure a API da Meta primeiro.');
            redirect($self);
        }
        $contatoTeste = find_contact_by_phone($tel) ?? ['nome' => post('nome') ?: 'Teste', 'telefone' => $tel];
        $r = $wa->sendTemplate($tel, $c['template_nome'], $c['template_idioma'], build_components($cfg, $contatoTeste));
        if ($r['ok']) {
            flash('sucesso', 'Mensagem de teste enviada para ' . format_phone($tel) . '. Confira no WhatsApp.');
        } else {
            flash('erro', 'A Meta recusou o teste (código ' . $r['error']['code'] . '): ' . $r['error']['message']);
            log_event('erro', "Teste da campanha #$id falhou", $r['error']);
        }
        redirect($self);
    }

    redirect($self);
}

// ===================== EXPORTAR =====================
if (get('exportar')) {
    $rows = q('SELECT ct.nome, m.telefone, m.status, m.enviado_em, m.atualizado_em, m.erro_codigo, m.erro
               FROM campanha_mensagens m LEFT JOIN contatos ct ON ct.id = m.contato_id
               WHERE m.campanha_id = ? ORDER BY m.id', [$id]);
    csv_download('campanha-' . $id . '.csv', ['nome', 'telefone', 'status', 'enviado_em', 'atualizado_em', 'erro_codigo', 'erro'], $rows);
}

// ===================== DADOS =====================
$stats = [];
foreach (q('SELECT status, COUNT(*) n FROM campanha_mensagens WHERE campanha_id = ? GROUP BY status', [$id])->fetchAll() as $r) {
    $stats[$r['status']] = (int)$r['n'];
}
$enviadas = ($stats['enviada'] ?? 0) + ($stats['entregue'] ?? 0) + ($stats['lida'] ?? 0);
$total = (int)$c['total'];
$feitas = $enviadas + ($stats['falhou'] ?? 0) + ($stats['cancelada'] ?? 0);
$progresso = $total > 0 ? (int)round($feitas / $total * 100) : 0;

[$from, $p] = eligible_sql($c['filtro_tag']);
$elegiveis = $c['status'] === 'rascunho' ? (int)q("SELECT COUNT(*) $from", $p)->fetchColumn() : $total;
$amostra = q("SELECT * $from ORDER BY id LIMIT 1", $p)->fetch() ?: ['nome' => 'Maria Silva', 'telefone' => '5585999998888'];

$tpl = q('SELECT * FROM templates WHERE nome = ? AND idioma = ?', [$c['template_nome'], $c['template_idioma']])->fetch();
$fields = $tpl ? template_fields(json_decode((string)$tpl['componentes'], true) ?: []) : null;

$filtroStatus = get('status');
$pg = max(1, (int)get('p', '1'));
$per = 100;
$w = 'WHERE m.campanha_id = ?';
$wp = [$id];
if ($filtroStatus !== '') {
    $w .= ' AND m.status = ?';
    $wp[] = $filtroStatus;
}
$totalLista = (int)q("SELECT COUNT(*) FROM campanha_mensagens m $w", $wp)->fetchColumn();
$off = ($pg - 1) * $per;
$msgs = q("SELECT m.*, ct.nome FROM campanha_mensagens m LEFT JOIN contatos ct ON ct.id = m.contato_id $w ORDER BY m.id LIMIT $per OFFSET $off", $wp)->fetchAll();

$limite = (int)setting('limite_diario', '250');
$lote = (int)setting('lote_por_minuto', '30');
$pendentes = $stats['pendente'] ?? 0;

page_header('Campanha: ' . $c['nome'], 'campanhas', in_array($c['status'], ['enviando'], true) ? '<meta http-equiv="refresh" content="60">' : '');
?>
<div class="head">
  <h1><?= e($c['nome']) ?> <?= badge($c['status']) ?></h1>
  <a class="btn sec" href="campanhas.php">← Campanhas</a>
</div>

<div class="grid">
  <div class="stat"><div class="n"><?= number_format($c['status'] === 'rascunho' ? $elegiveis : $total, 0, ',', '.') ?></div><div class="l"><?= $c['status'] === 'rascunho' ? 'Contatos aptos agora' : 'Destinatários' ?></div></div>
  <div class="stat"><div class="n"><?= number_format($enviadas, 0, ',', '.') ?></div><div class="l">Enviadas</div></div>
  <div class="stat"><div class="n"><?= number_format(($stats['entregue'] ?? 0) + ($stats['lida'] ?? 0), 0, ',', '.') ?></div><div class="l">Entregues</div></div>
  <div class="stat"><div class="n"><?= number_format($stats['lida'] ?? 0, 0, ',', '.') ?></div><div class="l">Lidas</div></div>
  <div class="stat"><div class="n"><?= number_format($stats['falhou'] ?? 0, 0, ',', '.') ?></div><div class="l">Falhas</div></div>
</div>

<?php if ($c['status'] !== 'rascunho'): ?>
<div class="card">
  <div class="progress" style="height:12px"><div style="width:<?= $progresso ?>%"></div></div>
  <p class="small muted" style="margin-bottom:0"><?= $progresso ?>% processado · <?= number_format($pendentes, 0, ',', '.') ?> na fila
    <?php if ($c['status'] === 'enviando' && $pendentes > 0 && $lote > 0): ?>
      · ritmo de até <?= $lote ?>/min<?= $limite > 0 ? ', limitado a ' . number_format($limite, 0, ',', '.') . ' contatos únicos a cada 24h' : '' ?>
    <?php endif; ?>
    <?php if ($c['status'] === 'enviando'): ?> · página atualiza sozinha a cada minuto<?php endif; ?></p>
</div>
<?php endif; ?>

<div class="row">
  <div class="card">
    <h2>Pré-visualização</h2>
    <p class="small muted">Exemplo com o contato: <?= e($amostra['nome'] ?: format_phone($amostra['telefone'])) ?></p>
    <?php if ($fields): ?>
      <div class="wa-bg"><div class="preview"><?= e(preview_message($fields, $cfg, $amostra)) ?></div></div>
    <?php else: ?>
      <p class="muted">Template não encontrado localmente (sincronize os templates).</p>
    <?php endif; ?>
    <dl class="kv small" style="margin-top:14px">
      <dt>Template</dt><dd><?= e($c['template_nome']) ?> (<?= e($c['template_idioma']) ?>)</dd>
      <dt>Público</dt><dd><?= $c['filtro_tag'] ? 'Tag: ' . e($c['filtro_tag']) : 'Todos os contatos aptos' ?></dd>
      <?php if ($c['agendar_para']): ?><dt>Agendada para</dt><dd><?= e(fmt_dt($c['agendar_para'])) ?></dd><?php endif; ?>
      <dt>Criada em</dt><dd><?= e(fmt_dt($c['criado_em'])) ?></dd>
      <?php if ($c['iniciado_em']): ?><dt>Iniciada em</dt><dd><?= e(fmt_dt($c['iniciado_em'])) ?></dd><?php endif; ?>
      <?php if ($c['concluido_em']): ?><dt>Finalizada em</dt><dd><?= e(fmt_dt($c['concluido_em'])) ?></dd><?php endif; ?>
    </dl>
  </div>

  <div class="card">
    <h2>Ações</h2>
    <?php if ($c['status'] === 'rascunho'): ?>
      <form method="post" data-confirm="Iniciar o envio para <?= $elegiveis ?> contato(s)? Cada mensagem é cobrada pela Meta.">
        <?= csrf_field() ?><input type="hidden" name="op" value="iniciar"><input type="hidden" name="id" value="<?= $id ?>">
        <button class="btn" type="submit" <?= $elegiveis === 0 ? 'disabled' : '' ?>>▶ <?= $c['agendar_para'] && strtotime($c['agendar_para']) > time() ? 'Agendar envio' : 'Iniciar envio' ?> (<?= $elegiveis ?>)</button>
      </form>
      <?php if ($limite > 0 && $elegiveis > $limite): ?>
        <p class="help">Seu limite atual é de <?= number_format($limite, 0, ',', '.') ?> contatos únicos por 24h. O restante será enviado automaticamente nos dias seguintes.</p>
      <?php endif; ?>
    <?php endif; ?>

    <div class="actions">
      <?php if (in_array($c['status'], ['enviando', 'agendada'], true)): ?>
        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="op" value="pausar"><input type="hidden" name="id" value="<?= $id ?>">
          <button class="btn sec" type="submit">⏸ Pausar</button></form>
      <?php endif; ?>
      <?php if ($c['status'] === 'pausada'): ?>
        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="op" value="retomar"><input type="hidden" name="id" value="<?= $id ?>">
          <button class="btn" type="submit">▶ Retomar</button></form>
      <?php endif; ?>
      <?php if (in_array($c['status'], ['enviando', 'agendada', 'pausada'], true)): ?>
        <form method="post" class="inline" data-confirm="Cancelar a campanha? As mensagens na fila não serão enviadas.">
          <?= csrf_field() ?><input type="hidden" name="op" value="cancelar"><input type="hidden" name="id" value="<?= $id ?>">
          <button class="btn danger" type="submit">Cancelar</button></form>
      <?php endif; ?>
      <?php if (($stats['falhou'] ?? 0) > 0 && in_array($c['status'], ['concluida', 'pausada', 'enviando'], true)): ?>
        <form method="post" class="inline" data-confirm="Colocar as mensagens com falha de volta na fila?">
          <?= csrf_field() ?><input type="hidden" name="op" value="reenviar_falhas"><input type="hidden" name="id" value="<?= $id ?>">
          <button class="btn sec" type="submit">↻ Reenviar falhas</button></form>
      <?php endif; ?>
      <?php if ($c['status'] !== 'rascunho'): ?>
        <a class="btn sec" href="<?= e($self) ?>&exportar=1">Exportar relatório</a>
      <?php endif; ?>
    </div>

    <h2 style="margin-top:22px">Enviar teste</h2>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="op" value="teste"><input type="hidden" name="id" value="<?= $id ?>">
      <div class="row">
        <div><label style="margin-top:0">Seu WhatsApp</label><input type="text" name="telefone" placeholder="85 99999-8888" required></div>
        <div><label style="margin-top:0">Nome no teste</label><input type="text" name="nome" placeholder="Teste"></div>
      </div>
      <div class="actions"><button class="btn sec" type="submit">Enviar teste</button></div>
      <p class="help">O teste é cobrado como uma mensagem normal e conta no limite diário.</p>
    </form>

    <?php if (in_array($c['status'], ['rascunho', 'concluida', 'cancelada'], true)): ?>
      <form method="post" data-confirm="Excluir esta campanha e seu histórico?" style="margin-top:20px">
        <?= csrf_field() ?><input type="hidden" name="op" value="excluir"><input type="hidden" name="id" value="<?= $id ?>">
        <button class="btn danger sm" type="submit">Excluir campanha</button></form>
    <?php endif; ?>
  </div>
</div>

<?php if ($c['status'] !== 'rascunho'): ?>
<div class="card">
  <div class="head" style="margin-bottom:10px">
    <h2 style="margin:0">Mensagens</h2>
    <form method="get" class="inline">
      <input type="hidden" name="id" value="<?= $id ?>">
      <select name="status" onchange="this.form.submit()" style="width:auto">
        <option value="">Todas</option>
        <?php foreach (['pendente', 'processando', 'enviada', 'entregue', 'lida', 'falhou', 'cancelada'] as $s): ?>
          <option value="<?= $s ?>" <?= $filtroStatus === $s ? 'selected' : '' ?>><?= e(status_label($s)[0]) ?> (<?= $stats[$s] ?? 0 ?>)</option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>
  <div class="table-wrap"><table>
    <tr><th>Contato</th><th>Status</th><th>Enviada em</th><th>Detalhe</th></tr>
    <?php foreach ($msgs as $m): ?>
      <tr>
        <td><?= e($m['nome'] ?: '—') ?><div class="small muted"><?= e(format_phone($m['telefone'])) ?></div></td>
        <td><?= badge($m['status']) ?></td>
        <td class="nowrap small"><?= e(fmt_dt($m['enviado_em'])) ?></td>
        <td class="small"><?= $m['erro'] ? e(($m['erro_codigo'] ? '[' . $m['erro_codigo'] . '] ' : '') . $m['erro']) : '' ?></td>
      </tr>
    <?php endforeach; ?>
  </table></div>
  <?= paginate($totalLista, $pg, $per, array_filter(['id' => $id, 'status' => $filtroStatus])) ?>
</div>
<?php endif; ?>
<?php page_footer();
