<?php
declare(strict_types=1);

/** Ícones SVG (estilo traço). */
function icon(string $name, int $size = 18): string
{
    $p = [
        'painel' => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
        'contatos' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'campanhas' => '<path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/>',
        'templates' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M16 13H8"/><path d="M16 17H8"/><path d="M10 9H8"/>',
        'conversas' => '<path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/>',
        'configuracoes' => '<path d="M4 21v-7"/><path d="M4 10V3"/><path d="M12 21v-9"/><path d="M12 8V3"/><path d="M20 21v-5"/><path d="M20 12V3"/><path d="M1 14h6"/><path d="M9 8h6"/><path d="M17 16h6"/>',
        'usuarios' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/>',
        'logs' => '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
        'conta' => '<circle cx="12" cy="8" r="4"/><path d="M20 21a8 8 0 0 0-16 0"/>',
        'sair' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
        'menu' => '<path d="M4 6h16"/><path d="M4 12h16"/><path d="M4 18h16"/>',
        'check' => '<path d="M20 6 9 17l-5-5"/>',
        'clock' => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
        'eye' => '<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>',
        'alert' => '<circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><path d="M12 16h.01"/>',
        'inbox' => '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
        'zap' => '<path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z"/>',
    ][$name] ?? '';
    return '<svg class="ico-svg" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
}

function brand_logo(int $size = 36): string
{
    return '<span class="logo" style="width:' . $size . 'px;height:' . $size . 'px"><svg viewBox="0 0 24 24" width="' . (int)($size * 0.58) . '" height="' . (int)($size * 0.58) . '" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/><path d="m9 11 2 2 4-4"/></svg></span>';
}

function page_header(string $title, string $active = '', string $extraHead = ''): void
{
    $u = current_user();
    $naoLidas = 0;
    try {
        $naoLidas = (int)q("SELECT COUNT(*) FROM conversas WHERE direcao = 'entrada' AND lida = 0")->fetchColumn();
    } catch (Throwable) {
    }
    $apiOk = setting('wa_token', '') !== '' && setting('wa_phone_id', '') !== '';
    $cronUlt = setting('cron_ultima');
    $cronOk = $cronUlt && (time() - strtotime($cronUlt)) <= 300;

    $items = [
        ['index.php', 'painel', 'Painel'],
        ['campanhas.php', 'campanhas', 'Campanhas'],
        ['contatos.php', 'contatos', 'Contatos'],
        ['templates.php', 'templates', 'Templates'],
        ['conversas.php', 'conversas', 'Conversas'],
    ];
    $admin = [
        ['configuracoes.php', 'configuracoes', 'Configurações'],
        ['usuarios.php', 'usuarios', 'Usuários'],
        ['logs.php', 'logs', 'Logs'],
    ];
    $iniciais = '';
    if ($u) {
        $parts = preg_split('/\s+/', trim($u['nome'])) ?: [];
        $iniciais = mb_strtoupper(mb_substr($parts[0] ?? '', 0, 1) . (count($parts) > 1 ? mb_substr(end($parts), 0, 1) : ''));
    }
    ?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#063b33">
<title><?= e($title) ?> · <?= e(APP_NAME) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css?v=<?= e(APP_VERSION) ?>">
<?= $extraHead ?>
</head>
<body>
<div class="layout">
  <aside class="sidebar" id="sidebar">
    <div class="brand-row">
      <a class="brand" href="index.php"><?= brand_logo() ?><span><strong>Disparador</strong><small>WhatsApp Cloud API</small></span></a>
      <button class="menu-btn" type="button" aria-label="Menu" onclick="document.getElementById('sidebar').classList.toggle('open')"><?= icon('menu', 22) ?></button>
    </div>
    <nav>
      <div class="nav-label">Principal</div>
      <?php foreach ($items as [$href, $key, $label]): ?>
        <a href="<?= $href ?>" class="<?= $active === $key ? 'active' : '' ?>">
          <?= icon($key) ?><span><?= e($label) ?></span>
          <?php if ($key === 'conversas' && $naoLidas > 0): ?><span class="pill"><?= $naoLidas ?></span><?php endif; ?>
        </a>
      <?php endforeach; ?>
      <?php if ($u && $u['perfil'] === 'admin'): ?>
        <div class="nav-label">Administração</div>
        <?php foreach ($admin as [$href, $key, $label]): ?>
          <a href="<?= $href ?>" class="<?= $active === $key ? 'active' : '' ?>"><?= icon($key) ?><span><?= e($label) ?></span></a>
        <?php endforeach; ?>
      <?php endif; ?>
      <div class="nav-label">Conta</div>
      <a href="conta.php" class="<?= $active === 'conta' ? 'active' : '' ?>"><?= icon('conta') ?><span>Minha conta</span></a>
      <a href="logout.php?t=<?= e(csrf_token()) ?>"><?= icon('sair') ?><span>Sair</span></a>
    </nav>
    <?php if ($u): ?>
    <div class="side-user">
      <span class="avatar"><?= e($iniciais) ?></span>
      <span class="who"><strong><?= e($u['nome']) ?></strong><small><?= $u['perfil'] === 'admin' ? 'Administrador' : 'Operador' ?></small></span>
    </div>
    <?php endif; ?>
  </aside>
  <div class="content">
    <header class="topbar">
      <div class="crumb"><?= e($title) ?></div>
      <div class="status-chips">
        <a href="<?= $u && $u['perfil'] === 'admin' ? 'configuracoes.php' : '#' ?>" class="chip <?= $apiOk ? 'ok' : 'off' ?>"><span class="dot"></span>API <?= $apiOk ? 'conectada' : 'pendente' ?></a>
        <a href="<?= $u && $u['perfil'] === 'admin' ? 'configuracoes.php#cron' : '#' ?>" class="chip <?= $cronOk ? 'ok' : 'off' ?>"><span class="dot"></span>Envio automático <?= $cronOk ? 'ativo' : 'parado' ?></a>
        <?php if ($u): ?><a href="conta.php" class="avatar sm" title="<?= e($u['nome']) ?>"><?= e($iniciais) ?></a><?php endif; ?>
      </div>
    </header>
    <main class="main">
    <?php render_flashes(); ?>
<?php
}

function page_footer(): void
{
    ?>
    <footer class="foot"><?= e(APP_NAME) ?> v<?= e(APP_VERSION) ?> · API oficial WhatsApp Cloud (Meta)</footer>
    </main>
  </div>
</div>
<script>
document.querySelectorAll('form[data-confirm]').forEach(function (f) {
  f.addEventListener('submit', function (ev) { if (!confirm(f.getAttribute('data-confirm'))) ev.preventDefault(); });
});
document.querySelectorAll('.alert').forEach(function (a) {
  var b = document.createElement('button'); b.type = 'button'; b.className = 'alert-x'; b.innerHTML = '&times;';
  b.onclick = function () { a.style.opacity = 0; setTimeout(function () { a.remove(); }, 200); }; a.appendChild(b);
});
</script>
</body>
</html>
<?php
}

function render_flashes(): void
{
    $ic = ['sucesso' => 'check', 'erro' => 'alert', 'aviso' => 'alert', 'info' => 'eye'];
    foreach (flashes() as [$type, $msg]) {
        echo '<div class="alert ' . e($type) . '"><span class="alert-ico">' . icon($ic[$type] ?? 'alert') . '</span><div>' . nl2br(e($msg)) . '</div></div>';
    }
}

function status_label(string $s): array
{
    return [
        // campanhas
        'rascunho' => ['Rascunho', 'cinza'],
        'agendada' => ['Agendada', 'roxo'],
        'enviando' => ['Enviando', 'azul'],
        'pausada' => ['Pausada', 'amarelo'],
        'concluida' => ['Concluída', 'verde'],
        'cancelada' => ['Cancelada', 'vermelho'],
        // mensagens
        'pendente' => ['Na fila', 'cinza'],
        'processando' => ['Processando', 'azul'],
        'enviada' => ['Enviada', 'azul'],
        'entregue' => ['Entregue', 'verde'],
        'lida' => ['Lida', 'verde'],
        'falhou' => ['Falhou', 'vermelho'],
        // templates
        'APPROVED' => ['Aprovado', 'verde'],
        'PENDING' => ['Em análise', 'amarelo'],
        'REJECTED' => ['Rejeitado', 'vermelho'],
        'PAUSED' => ['Pausado', 'amarelo'],
        'DISABLED' => ['Desativado', 'vermelho'],
    ][$s] ?? [$s, 'cinza'];
}

function badge(string $status): string
{
    [$label, $cor] = status_label($status);
    return '<span class="badge ' . $cor . '">' . e($label) . '</span>';
}

function paginate(int $total, int $page, int $per, array $params = []): string
{
    $pages = (int)ceil($total / $per);
    if ($pages <= 1) {
        return '';
    }
    $html = '<div class="pagination">';
    $start = max(1, $page - 3);
    $end = min($pages, $page + 3);
    if ($page > 1) {
        $html .= '<a href="?' . e(http_build_query(array_merge($params, ['p' => $page - 1]))) . '">‹</a>';
    }
    for ($i = $start; $i <= $end; $i++) {
        $html .= $i === $page
            ? '<span class="cur">' . $i . '</span>'
            : '<a href="?' . e(http_build_query(array_merge($params, ['p' => $i]))) . '">' . $i . '</a>';
    }
    if ($page < $pages) {
        $html .= '<a href="?' . e(http_build_query(array_merge($params, ['p' => $page + 1]))) . '">›</a>';
    }
    return $html . '<span class="muted small" style="margin-left:6px">' . number_format($total, 0, ',', '.') . ' registros</span></div>';
}
