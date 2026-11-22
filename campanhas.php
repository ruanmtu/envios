<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();
$acao = get('acao', 'lista');

// ===================== CRIAR =====================
if (is_post() && post('op') === 'criar') {
    csrf_check();
    $tpl = q("SELECT * FROM templates WHERE id = ? AND status = 'APPROVED'", [(int)post('template_id')])->fetch();
    $back = 'campanhas.php?acao=nova&template_id=' . (int)post('template_id');
    if (!$tpl) {
        flash('erro', 'Selecione um template aprovado.');
        redirect('campanhas.php?acao=nova');
    }
    $nome = mb_substr(post('nome'), 0, 150);
    if ($nome === '') {
        flash('erro', 'Dê um nome para a campanha.');
        redirect($back);
    }
    $f = template_fields(json_decode((string)$tpl['componentes'], true) ?: []);
    $cfg = ['header' => null, 'body' => [], 'buttons' => []];
    $faltando = false;

    if ($f['header']) {
        if ($f['header']['type'] === 'text') {
            $params = [];
            foreach ($f['header']['params'] as $p) {
                $val = post('h_' . $p);
                $faltando = $faltando || $val === '';
                $params[] = ['name' => $p, 'value' => $val];
            }
            $cfg['header'] = ['type' => 'text', 'params' => $params];
        } else {
            $url = post('h_url');
            if (!preg_match('#^https://#i', $url)) {
                flash('erro', 'Informe um link público https:// para a mídia do cabeçalho.');
                redirect($back);
            }
            $cfg['header'] = ['type' => $f['header']['type'], 'url' => $url, 'filename' => mb_substr(post('h_filename'), 0, 120)];
        }
    }
    foreach ($f['body'] as $p) {
        $val = post('b_' . $p);
        $faltando = $faltando || $val === '';
        $cfg['body'][] = ['name' => $p, 'value' => $val];
    }
    foreach ($f['buttons'] as $b) {
        $val = post('btn_' . $b['index']);
        $faltando = $faltando || $val === '';
        $cfg['buttons'][] = ['index' => $b['index'], 'value' => $val];
    }
    if ($faltando) {
        flash('erro', 'Preencha todas as variáveis do template.');
        redirect($back);
    }
    $agendar = post('agendar_para');
    $agendarSql = null;
    if ($agendar !== '') {
        $ts = strtotime($agendar);
        if (!$ts) {
            flash('erro', 'Data de agendamento inválida.');
            redirect($back);
        }
        $agendarSql = date('Y-m-d H:i:s', $ts);
    }
    q('INSERT INTO campanhas (nome, template_id, template_nome, template_idioma, config, filtro_tag, status, agendar_para, criado_por, criado_em)
       VALUES (?,?,?,?,?,?,?,?,?,NOW())', [
        $nome, $tpl['id'], $tpl['nome'], $tpl['idioma'],
        json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        post('filtro_tag') ?: null, 'rascunho', $agendarSql, $user['id'],
    ]);
    $id = (int)db()->lastInsertId();
    flash('sucesso', 'Campanha criada como rascunho. Confira a pré-visualização, envie um teste e depois clique em "Iniciar envio".');
    redirect('campanha.php?id=' . $id);
}

// ===================== NOVA =====================
if ($acao === 'nova') {
    $templates = q("SELECT id, nome, idioma, categoria FROM templates WHERE status = 'APPROVED' ORDER BY nome")->fetchAll();
    $tpl = null;
    $f = null;
    if (get('template_id') !== '') {
        $tpl = q("SELECT * FROM templates WHERE id = ? AND status = 'APPROVED'", [(int)get('template_id')])->fetch() ?: null;
        if ($tpl) {
            $f = template_fields(json_decode((string)$tpl['componentes'], true) ?: []);
        }
    }
    $tags = all_tags();
    page_header('Nova campanha', 'campanhas');
    ?>
    <div class="head"><h1>Nova campanha</h1><a class="btn sec" href="campanhas.php">← Voltar</a></div>

    <?php if (!$templates): ?>
      <div class="alert aviso">Nenhum template aprovado disponível. Crie um template no WhatsApp Manager e <a href="templates.php">sincronize</a>.</div>
    <?php else: ?>
    <div class="card">
      <form method="get">
        <input type="hidden" name="acao" value="nova">
        <label style="margin-top:0">1. Escolha o template</label>
        <div class="toolbar" style="margin:0">
          <div class="grow"><select name="template_id" onchange="this.form.submit()">
            <option value="">Selecione…</option>
            <?php foreach ($templates as $t): ?>
              <option value="<?= (int)$t['id'] ?>" <?= $tpl && (int)$tpl['id'] === (int)$t['id'] ? 'selected' : '' ?>>
                <?= e($t['nome']) ?> (<?= e($t['idioma']) ?> · <?= e(strtolower((string)$t['categoria'])) ?>)</option>
            <?php endforeach; ?>
          </select></div>
          <noscript><button class="btn sec" type="submit">Carregar</button></noscript>
        </div>
      </form>
    </div>

    <?php if ($tpl && $f): ?>
    <div class="card">
      <h2>Texto do template</h2>
      <div class="wa-bg"><div class="preview"><?= e(preview_message($f, ['header' => null, 'body' => []], [])) ?></div></div>
    </div>

    <form method="post" class="card">
      <?= csrf_field() ?>
      <input type="hidden" name="op" value="criar">
      <input type="hidden" name="template_id" value="<?= (int)$tpl['id'] ?>">
      <h2>2. Dados da campanha</h2>
      <div class="row">
        <div><label>Nome da campanha *</label><input type="text" name="nome" required placeholder="Ex.: Black Friday 2026"></div>
        <div><label>Enviar para</label>
          <select name="filtro_tag"><option value="">Todos os contatos aptos</option>
            <?php foreach ($tags as $t): ?><option value="<?= e($t) ?>">Somente tag: <?= e($t) ?></option><?php endforeach; ?>
          </select></div>
        <div><label>Agendar para (opcional)</label><input type="datetime-local" name="agendar_para">
          <div class="help">Em branco: começa quando você clicar em "Iniciar envio".</div></div>
      </div>

      <?php if ($f['header'] || $f['body'] || $f['buttons']): ?>
      <h2 style="margin-top:22px">3. Variáveis</h2>
      <p class="help">Você pode usar texto fixo e/ou os dados do contato:
        <code>{nome}</code> <code>{primeiro_nome}</code> <code>{telefone}</code> <code>{campo1}</code> <code>{campo2}</code> <code>{campo3}</code>.
        Valor padrão quando o dado estiver vazio: <code>{primeiro_nome|Cliente}</code></p>

      <?php if ($f['header'] && $f['header']['type'] === 'text'): ?>
        <?php foreach ($f['header']['params'] as $p): ?>
          <label>Cabeçalho {{<?= e($p) ?>}}</label>
          <input type="text" name="h_<?= e($p) ?>" required>
        <?php endforeach; ?>
      <?php elseif ($f['header']): ?>
        <label>Link da mídia do cabeçalho (<?= e(['image' => 'imagem JPG/PNG', 'video' => 'vídeo MP4', 'document' => 'documento PDF'][$f['header']['type']]) ?>) *</label>
        <input type="url" name="h_url" placeholder="https://seusite.com.br/arquivo.jpg" required>
        <div class="help">Precisa ser um link público https. Dica: suba o arquivo no Gerenciador de Arquivos da Hostinger e use o endereço dele.</div>
        <?php if ($f['header']['type'] === 'document'): ?>
          <label>Nome do arquivo exibido</label><input type="text" name="h_filename" placeholder="catalogo.pdf">
        <?php endif; ?>
      <?php endif; ?>

      <?php foreach ($f['body'] as $i => $p): ?>
        <label>Corpo {{<?= e($p) ?>}}</label>
        <input type="text" name="b_<?= e($p) ?>" value="<?= $i === 0 ? '{primeiro_nome|Cliente}' : '' ?>" required>
      <?php endforeach; ?>

      <?php foreach ($f['buttons'] as $b): ?>
        <label>Botão "<?= e($b['text']) ?>": final do link</label>
        <input type="text" name="btn_<?= (int)$b['index'] ?>" required>
        <div class="help">Link do template: <code><?= e($b['url']) ?></code>. Informe o que entra no lugar de {{1}}.</div>
      <?php endforeach; ?>
      <?php endif; ?>

      <div class="actions"><button class="btn" type="submit">Criar campanha (rascunho)</button></div>
    </form>
    <?php endif; ?>
    <?php endif;
    page_footer();
    exit;
}

// ===================== LISTA =====================
$page = max(1, (int)get('p', '1'));
$per = 25;
$total = (int)q('SELECT COUNT(*) FROM campanhas')->fetchColumn();
$offset = ($page - 1) * $per;
$rows = q("SELECT c.*,
            (SELECT COUNT(*) FROM campanha_mensagens m WHERE m.campanha_id = c.id AND m.wamid IS NOT NULL) AS enviadas,
            (SELECT COUNT(*) FROM campanha_mensagens m WHERE m.campanha_id = c.id AND m.status = 'falhou') AS falhas
           FROM campanhas c ORDER BY c.id DESC LIMIT $per OFFSET $offset")->fetchAll();

page_header('Campanhas', 'campanhas');
?>
<div class="head"><h1>Campanhas</h1><a class="btn" href="campanhas.php?acao=nova">+ Nova campanha</a></div>
<div class="card">
  <?php if (!$rows): ?>
    <p class="muted">Nenhuma campanha criada.</p>
  <?php else: ?>
  <div class="table-wrap"><table>
    <tr><th>Campanha</th><th>Público</th><th>Status</th><th>Progresso</th><th>Falhas</th><th>Criada</th></tr>
    <?php foreach ($rows as $c): $p = $c['total'] > 0 ? (int)round($c['enviadas'] / $c['total'] * 100) : 0; ?>
      <tr>
        <td><a href="campanha.php?id=<?= (int)$c['id'] ?>"><strong><?= e($c['nome']) ?></strong></a><div class="muted small"><?= e($c['template_nome']) ?></div></td>
        <td class="small"><?= $c['filtro_tag'] ? 'Tag: ' . e($c['filtro_tag']) : 'Todos aptos' ?></td>
        <td><?= badge($c['status']) ?><?php if ($c['status'] === 'agendada'): ?><div class="small muted"><?= e(fmt_dt($c['agendar_para'])) ?></div><?php endif; ?></td>
        <td><div class="progress"><div style="width:<?= $p ?>%"></div></div><span class="small muted"><?= (int)$c['enviadas'] ?>/<?= (int)$c['total'] ?></span></td>
        <td><?= (int)$c['falhas'] ?></td>
        <td class="nowrap small"><?= e(fmt_dt($c['criado_em'])) ?></td>
      </tr>
    <?php endforeach; ?>
  </table></div>
  <?= paginate($total, $page, $per) ?>
  <?php endif; ?>
</div>
<?php page_footer();
