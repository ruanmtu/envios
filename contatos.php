<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();
$acao = get('acao', 'lista');

// ===================== AÇÕES (POST) =====================
if (is_post()) {
    csrf_check();
    $op = post('op');

    if ($op === 'salvar') {
        $id = (int)post('id');
        $back = 'contatos.php?acao=' . ($id ? 'editar&id=' . $id : 'novo');
        $nome = mb_substr(post('nome'), 0, 150);
        $tel = normalize_phone(post('telefone'));
        $tags = normalize_tags(post('tags'));
        $c1 = mb_substr(post('campo1'), 0, 255);
        $c2 = mb_substr(post('campo2'), 0, 255);
        $c3 = mb_substr(post('campo3'), 0, 255);
        $optin = isset($_POST['optin']) ? 1 : 0;
        $origem = mb_substr(post('optin_origem'), 0, 150);
        $optout = isset($_POST['optout']) ? 1 : 0;

        if ($tel === '') {
            flash('erro', 'Telefone inválido. Informe DDD + número (ex.: 85 99999-8888) ou o número com código do país.');
            redirect($back);
        }
        if ($optin && $origem === '') {
            flash('erro', 'Informe a origem do opt-in (ex.: formulário do site, cadastro na loja).');
            redirect($back);
        }
        $dup = q('SELECT id FROM contatos WHERE telefone = ? AND id <> ?', [$tel, $id])->fetchColumn();
        if ($dup) {
            flash('erro', 'Já existe um contato com esse telefone.');
            redirect('contatos.php?acao=editar&id=' . (int)$dup);
        }
        $now = date('Y-m-d H:i:s');
        if ($id) {
            $old = q('SELECT * FROM contatos WHERE id = ?', [$id])->fetch();
            if (!$old) {
                redirect('contatos.php');
            }
            q('UPDATE contatos SET nome=?, telefone=?, tags=?, campo1=?, campo2=?, campo3=?, optin=?, optin_origem=?, optin_em=?, optout=?, optout_em=? WHERE id=?', [
                $nome, $tel, $tags, $c1, $c2, $c3,
                $optin, $origem ?: null, $optin ? ($old['optin'] ? $old['optin_em'] : $now) : null,
                $optout, $optout ? ($old['optout'] ? $old['optout_em'] : $now) : null,
                $id,
            ]);
            if ($optout && !$old['optout']) {
                apply_optout($id);
            }
        } else {
            q('INSERT INTO contatos (nome, telefone, tags, campo1, campo2, campo3, optin, optin_origem, optin_em, optout, optout_em, criado_em)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())', [
                $nome, $tel, $tags, $c1, $c2, $c3, $optin, $origem ?: null, $optin ? $now : null, $optout, $optout ? $now : null,
            ]);
        }
        flash('sucesso', 'Contato salvo.');
        redirect('contatos.php');
    }

    if ($op === 'excluir') {
        $id = (int)post('id');
        q("UPDATE campanha_mensagens SET status = 'cancelada', erro = 'Contato excluído', atualizado_em = NOW() WHERE contato_id = ? AND status = 'pendente'", [$id]);
        q('DELETE FROM contatos WHERE id = ?', [$id]);
        flash('sucesso', 'Contato excluído.');
        redirect('contatos.php');
    }

    if ($op === 'importar') {
        $back = 'contatos.php?acao=importar';
        $origem = mb_substr(post('optin_origem'), 0, 150);
        $tagExtra = normalize_tags(post('tag'));
        if (!isset($_POST['confirmo'])) {
            flash('erro', 'Confirme que todos os contatos do arquivo autorizaram receber mensagens (opt-in).');
            redirect($back);
        }
        if ($origem === '') {
            flash('erro', 'Informe a origem do opt-in desses contatos.');
            redirect($back);
        }
        $f = $_FILES['arquivo'] ?? null;
        if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) {
            flash('erro', 'Selecione um arquivo CSV válido.');
            redirect($back);
        }
        if ($f['size'] > 10 * 1024 * 1024) {
            flash('erro', 'Arquivo muito grande (máximo 10 MB).');
            redirect($back);
        }
        @set_time_limit(300);
        $content = (string)file_get_contents($f['tmp_name']);
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }
        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        $first = $lines[0] ?? '';
        $counts = [';' => substr_count($first, ';'), ',' => substr_count($first, ','), "\t" => substr_count($first, "\t")];
        arsort($counts);
        $delim = (string)array_key_first($counts);

        $aliases = [
            'nome' => ['nome', 'name', 'cliente', 'nome completo'],
            'telefone' => ['telefone', 'celular', 'whatsapp', 'fone', 'phone', 'numero', 'número', 'tel'],
            'tags' => ['tags', 'tag', 'grupo', 'grupos', 'etiqueta', 'etiquetas'],
            'campo1' => ['campo1', 'campo 1'],
            'campo2' => ['campo2', 'campo 2'],
            'campo3' => ['campo3', 'campo 3'],
        ];
        $hdr = array_map(fn($h) => mb_strtolower(trim((string)$h)), str_getcsv($first, $delim, '"', ''));
        $map = ['nome' => 0, 'telefone' => 1, 'tags' => 2, 'campo1' => 3, 'campo2' => 4, 'campo3' => 5];
        $start = 0;
        $isHeader = false;
        foreach ($hdr as $h) {
            foreach ($aliases as $als) {
                if (in_array($h, $als, true)) {
                    $isHeader = true;
                }
            }
        }
        if ($isHeader) {
            $map = [];
            foreach ($aliases as $k => $als) {
                foreach ($hdr as $i => $h) {
                    if (in_array($h, $als, true)) {
                        $map[$k] = $i;
                        break;
                    }
                }
            }
            $start = 1;
            if (!isset($map['telefone'])) {
                flash('erro', 'Não encontrei a coluna "telefone" no cabeçalho do arquivo.');
                redirect($back);
            }
        }

        $col = fn(array $cols, string $k): string => isset($map[$k]) ? trim((string)($cols[$map[$k]] ?? '')) : '';
        $novos = $atualizados = $invalidos = $descadastrados = 0;
        $listaInvalidos = [];
        $sel = db()->prepare('SELECT * FROM contatos WHERE telefone = ?');
        $ins = db()->prepare('INSERT INTO contatos (nome, telefone, tags, campo1, campo2, campo3, optin, optin_origem, optin_em, criado_em) VALUES (?,?,?,?,?,?,1,?,NOW(),NOW())');
        $upd = db()->prepare('UPDATE contatos SET nome=?, tags=?, campo1=?, campo2=?, campo3=?, optin=?, optin_origem=?, optin_em=? WHERE id=?');

        db()->beginTransaction();
        for ($i = $start, $n = count($lines); $i < $n; $i++) {
            if (trim($lines[$i]) === '') {
                continue;
            }
            $cols = str_getcsv($lines[$i], $delim, '"', '');
            $tel = normalize_phone($col($cols, 'telefone'));
            if ($tel === '') {
                $invalidos++;
                if (count($listaInvalidos) < 15) {
                    $listaInvalidos[] = 'Linha ' . ($i + 1) . ': ' . mb_substr($lines[$i], 0, 60);
                }
                continue;
            }
            $nome = mb_substr($col($cols, 'nome'), 0, 150);
            $tags = merge_tags($col($cols, 'tags'), $tagExtra);
            $c1 = mb_substr($col($cols, 'campo1'), 0, 255);
            $c2 = mb_substr($col($cols, 'campo2'), 0, 255);
            $c3 = mb_substr($col($cols, 'campo3'), 0, 255);

            $sel->execute([$tel]);
            $ex = $sel->fetch();
            if ($ex) {
                $keepOptin = (int)$ex['optout'] === 1; // respeita quem pediu para sair
                if ($keepOptin) {
                    $descadastrados++;
                }
                $upd->execute([
                    $nome !== '' ? $nome : $ex['nome'],
                    merge_tags($ex['tags'], $tags),
                    $c1 !== '' ? $c1 : $ex['campo1'],
                    $c2 !== '' ? $c2 : $ex['campo2'],
                    $c3 !== '' ? $c3 : $ex['campo3'],
                    $keepOptin ? $ex['optin'] : 1,
                    $ex['optin_origem'] ?: $origem,
                    $ex['optin_em'] ?: date('Y-m-d H:i:s'),
                    $ex['id'],
                ]);
                $atualizados++;
            } else {
                $ins->execute([$nome, $tel, $tags, $c1 ?: null, $c2 ?: null, $c3 ?: null, $origem]);
                $novos++;
            }
        }
        db()->commit();

        $msg = "Importação concluída: $novos novos, $atualizados atualizados, $invalidos inválidos.";
        if ($descadastrados) {
            $msg .= "\n$descadastrados contato(s) já tinham pedido para sair e continuam descadastrados.";
        }
        if ($listaInvalidos) {
            $msg .= "\nTelefones inválidos:\n" . implode("\n", $listaInvalidos);
        }
        log_event('info', "Importação de contatos por {$user['usuario']}: $novos novos, $atualizados atualizados, $invalidos inválidos");
        flash($invalidos ? 'aviso' : 'sucesso', $msg);
        redirect('contatos.php');
    }
}

// ===================== FILTROS =====================
$busca = get('q');
$tag = get('tag');
$situacao = get('situacao');
$where = [];
$params = [];
if ($busca !== '') {
    $dig = preg_replace('/\D+/', '', $busca) ?? '';
    if ($dig !== '') {
        $where[] = '(nome LIKE ? OR telefone LIKE ?)';
        $params[] = '%' . $busca . '%';
        $params[] = '%' . $dig . '%';
    } else {
        $where[] = 'nome LIKE ?';
        $params[] = '%' . $busca . '%';
    }
}
if ($tag !== '') {
    $where[] = 'FIND_IN_SET(?, tags)';
    $params[] = $tag;
}
if ($situacao === 'aptos') {
    $where[] = 'optin = 1 AND optout = 0';
} elseif ($situacao === 'sem_optin') {
    $where[] = 'optin = 0 AND optout = 0';
} elseif ($situacao === 'optout') {
    $where[] = 'optout = 1';
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// ===================== EXPORTAR =====================
if ($acao === 'exportar') {
    $rows = q("SELECT nome, telefone, tags, campo1, campo2, campo3,
                IF(optin=1,'sim','não'), optin_origem, optin_em, IF(optout=1,'sim','não'), optout_em, criado_em
               FROM contatos $whereSql ORDER BY id", $params);
    csv_download('contatos-' . date('Y-m-d') . '.csv',
        ['nome', 'telefone', 'tags', 'campo1', 'campo2', 'campo3', 'optin', 'optin_origem', 'optin_em', 'descadastrado', 'descadastrado_em', 'criado_em'],
        $rows);
}

// ===================== FORMULÁRIO =====================
if ($acao === 'novo' || $acao === 'editar') {
    $c = ['id' => 0, 'nome' => '', 'telefone' => '', 'tags' => '', 'campo1' => '', 'campo2' => '', 'campo3' => '', 'optin' => 1, 'optin_origem' => '', 'optout' => 0, 'optin_em' => null, 'optout_em' => null, 'ultima_interacao' => null];
    if ($acao === 'editar') {
        $c = q('SELECT * FROM contatos WHERE id = ?', [(int)get('id')])->fetch();
        if (!$c) {
            flash('erro', 'Contato não encontrado.');
            redirect('contatos.php');
        }
    }
    page_header($c['id'] ? 'Editar contato' : 'Novo contato', 'contatos');
    ?>
    <div class="head"><h1><?= $c['id'] ? 'Editar contato' : 'Novo contato' ?></h1><a class="btn sec" href="contatos.php">← Voltar</a></div>
    <div class="card">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="op" value="salvar">
        <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
        <div class="row">
          <div><label>Nome</label><input type="text" name="nome" value="<?= e($c['nome']) ?>"></div>
          <div><label>WhatsApp *</label><input type="text" name="telefone" value="<?= e($c['telefone']) ?>" placeholder="85 99999-8888" required>
            <div class="help">Com DDD. Para outros países, inclua o código (ex.: 351...).</div></div>
        </div>
        <label>Tags</label>
        <input type="text" name="tags" value="<?= e($c['tags']) ?>" placeholder="clientes, fortaleza, vip">
        <div class="help">Separe por vírgula. Use para segmentar campanhas.</div>
        <div class="row">
          <div><label>Campo 1</label><input type="text" name="campo1" value="<?= e($c['campo1']) ?>"></div>
          <div><label>Campo 2</label><input type="text" name="campo2" value="<?= e($c['campo2']) ?>"></div>
          <div><label>Campo 3</label><input type="text" name="campo3" value="<?= e($c['campo3']) ?>"></div>
        </div>
        <div class="help">Campos livres para usar nas mensagens: {campo1}, {campo2}, {campo3} (ex.: cupom, cidade, vencimento).</div>

        <label class="check" style="margin-top:18px"><input type="checkbox" name="optin" value="1" <?= $c['optin'] ? 'checked' : '' ?>>
          <span>Este contato <strong>autorizou</strong> receber mensagens da empresa pelo WhatsApp (opt-in)</span></label>
        <label>Origem do opt-in</label>
        <input type="text" name="optin_origem" value="<?= e($c['optin_origem']) ?>" placeholder="Ex.: formulário do site, cadastro na loja">
        <?php if ($c['optin_em']): ?><div class="help">Opt-in registrado em <?= e(fmt_dt($c['optin_em'])) ?></div><?php endif; ?>

        <label class="check" style="margin-top:14px"><input type="checkbox" name="optout" value="1" <?= $c['optout'] ? 'checked' : '' ?>>
          <span>Contato <strong>pediu para não receber</strong> mais mensagens (descadastrado)</span></label>
        <?php if ($c['optout_em']): ?><div class="help">Descadastrado em <?= e(fmt_dt($c['optout_em'])) ?></div><?php endif; ?>

        <div class="actions"><button class="btn" type="submit">Salvar</button></div>
      </form>
    </div>
    <?php if ($c['id']): ?>
      <form method="post" data-confirm="Excluir este contato definitivamente?">
        <?= csrf_field() ?><input type="hidden" name="op" value="excluir"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
        <button class="btn danger sm" type="submit">Excluir contato</button>
      </form>
    <?php endif;
    page_footer();
    exit;
}

// ===================== IMPORTAR =====================
if ($acao === 'importar') {
    page_header('Importar contatos', 'contatos');
    ?>
    <div class="head"><h1>Importar contatos (CSV)</h1><a class="btn sec" href="contatos.php">← Voltar</a></div>
    <div class="card">
      <p>Envie um arquivo <strong>.csv</strong> (pode salvar pelo Excel em "CSV separado por ponto e vírgula"). A primeira linha pode ter os títulos:</p>
      <p><code>nome;telefone;tags;campo1;campo2;campo3</code></p>
      <p class="muted small">Só a coluna <strong>telefone</strong> é obrigatória. Números já cadastrados são atualizados, e quem pediu para sair continua descadastrado.</p>
      <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="op" value="importar">
        <label>Arquivo CSV</label>
        <input type="file" name="arquivo" accept=".csv,.txt,text/csv" required>
        <div class="row">
          <div><label>Origem do opt-in *</label><input type="text" name="optin_origem" placeholder="Ex.: cadastro no site em 2026" required></div>
          <div><label>Adicionar tag a todos (opcional)</label><input type="text" name="tag" placeholder="Ex.: lista-novembro"></div>
        </div>
        <label class="check" style="margin-top:16px"><input type="checkbox" name="confirmo" value="1" required>
          <span>Confirmo que <strong>todos</strong> os contatos deste arquivo autorizaram receber mensagens da minha empresa pelo WhatsApp. Listas compradas ou sem autorização violam as políticas da Meta e a LGPD, e podem levar ao bloqueio do número.</span></label>
        <div class="actions"><button class="btn" type="submit">Importar</button></div>
      </form>
    </div>
    <?php
    page_footer();
    exit;
}

// ===================== LISTA =====================
$page = max(1, (int)get('p', '1'));
$per = 50;
$total = (int)q("SELECT COUNT(*) FROM contatos $whereSql", $params)->fetchColumn();
$offset = ($page - 1) * $per;
$rows = q("SELECT * FROM contatos $whereSql ORDER BY id DESC LIMIT $per OFFSET $offset", $params)->fetchAll();
$tags = all_tags();
$filtros = array_filter(['q' => $busca, 'tag' => $tag, 'situacao' => $situacao], fn($v) => $v !== '');

page_header('Contatos', 'contatos');
?>
<div class="head">
  <h1>Contatos</h1>
  <div class="actions" style="margin:0">
    <a class="btn" href="contatos.php?acao=novo">+ Novo</a>
    <a class="btn sec" href="contatos.php?acao=importar">Importar CSV</a>
    <a class="btn sec" href="contatos.php?<?= e(http_build_query(array_merge($filtros, ['acao' => 'exportar']))) ?>">Exportar</a>
  </div>
</div>
<form class="toolbar card" method="get" style="padding:14px">
  <div class="grow"><label style="margin-top:0">Buscar</label><input type="search" name="q" value="<?= e($busca) ?>" placeholder="Nome ou telefone"></div>
  <div><label style="margin-top:0">Tag</label>
    <select name="tag"><option value="">Todas</option>
      <?php foreach ($tags as $t): ?><option value="<?= e($t) ?>" <?= $tag === $t ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?>
    </select></div>
  <div><label style="margin-top:0">Situação</label>
    <select name="situacao">
      <option value="">Todas</option>
      <option value="aptos" <?= $situacao === 'aptos' ? 'selected' : '' ?>>Aptos (com opt-in)</option>
      <option value="sem_optin" <?= $situacao === 'sem_optin' ? 'selected' : '' ?>>Sem opt-in</option>
      <option value="optout" <?= $situacao === 'optout' ? 'selected' : '' ?>>Descadastrados</option>
    </select></div>
  <div><button class="btn sec" type="submit">Filtrar</button></div>
</form>

<div class="card">
  <?php if (!$rows): ?>
    <p class="muted">Nenhum contato encontrado.</p>
  <?php else: ?>
  <div class="table-wrap"><table>
    <tr><th>Nome</th><th>WhatsApp</th><th>Tags</th><th>Situação</th><th>Cadastro</th><th></th></tr>
    <?php foreach ($rows as $c): ?>
      <tr>
        <td><?= e($c['nome'] ?: '—') ?></td>
        <td class="nowrap"><?= e(format_phone($c['telefone'])) ?></td>
        <td class="small"><?= e(str_replace(',', ', ', $c['tags'])) ?></td>
        <td><?php
            if ($c['optout']) echo '<span class="badge vermelho">Descadastrado</span>';
            elseif ($c['optin']) echo '<span class="badge verde">Apto</span>';
            else echo '<span class="badge amarelo">Sem opt-in</span>';
        ?></td>
        <td class="nowrap small"><?= e(fmt_dt($c['criado_em'])) ?></td>
        <td><a class="btn sec sm" href="contatos.php?acao=editar&id=<?= (int)$c['id'] ?>">Editar</a></td>
      </tr>
    <?php endforeach; ?>
  </table></div>
  <?= paginate($total, $page, $per, $filtros) ?>
  <?php endif; ?>
</div>
<?php page_footer();
