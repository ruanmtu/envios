<?php
declare(strict_types=1);

function page_header(string $title, string $active = '', string $extraHead = ''): void
{
    $u = current_user();
    $naoLidas = 0;
    try {
        $naoLidas = (int)q("SELECT COUNT(*) FROM conversas WHERE direcao = 'entrada' AND lida = 0")->fetchColumn();
    } catch (Throwable) {
    }
    $items = [
        ['index.php', 'painel', 'Painel'],
        ['contatos.php', 'contatos', 'Contatos'],
        ['campanhas.php', 'campanhas', 'Campanhas'],
        ['templates.php', 'templates', 'Templates'],
        ['conversas.php', 'conversas', 'Conversas'],
    ];
    $admin = [
        ['configuracoes.php', 'configuracoes', 'Configurações'],
        ['usuarios.php', 'usuarios', 'Usuários'],
        ['logs.php', 'logs', 'Logs'],
    ];
    ?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?> · <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="assets/style.css?v=<?= e(APP_VERSION) ?>">
<?= $extraHead ?>
</head>
<body>
<div class="layout">
  <aside class="sidebar" id="sidebar">
    <div class="brand-row">
      <div class="brand">💬 <?= e(APP_NAME) ?></div>
      <button class="menu-btn" type="button" onclick="document.getElementById('sidebar').classList.toggle('open')">☰</button>
    </div>
    <nav>
      <?php foreach ($items as [$href, $key, $label]): ?>
        <a href="<?= $href ?>" class="<?= $active === $key ? 'active' : '' ?>">
          <span><?= e($label) ?></span>
          <?php if ($key === 'conversas' && $naoLidas > 0): ?><span class="pill"><?= $naoLidas ?></span><?php endif; ?>
        </a>
      <?php endforeach; ?>
      <?php if ($u && $u['perfil'] === 'admin'): ?>
        <div class="sep"></div>
        <?php foreach ($admin as [$href, $key, $label]): ?>
          <a href="<?= $href ?>" class="<?= $active === $key ? 'active' : '' ?>"><span><?= e($label) ?></span></a>
        <?php endforeach; ?>
      <?php endif; ?>
      <div class="sep"></div>
      <a href="conta.php" class="<?= $active === 'conta' ? 'active' : '' ?>"><span>Minha conta</span></a>
      <a href="logout.php?t=<?= e(csrf_token()) ?>"><span>Sair</span></a>
      <?php if ($u): ?><div class="who">Conectado como <strong><?= e($u['nome']) ?></strong></div><?php endif; ?>
    </nav>
  </aside>
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
<script>
document.querySelectorAll('form[data-confirm]').forEach(function (f) {
  f.addEventListener('submit', function (ev) { if (!confirm(f.getAttribute('data-confirm'))) ev.preventDefault(); });
});
</script>
</body>
</html>
<?php
}

function render_flashes(): void
{
    foreach (flashes() as [$type, $msg]) {
        echo '<div class="alert ' . e($type) . '">' . nl2br(e($msg)) . '</div>';
    }
}

function status_label(string $s): array
{
    return [
        // campanhas
        'rascunho' => ['Rascunho', 'cinza'],
        'agendada' => ['Agendada', 'azul'],
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
        $html .= '<a href="?' . e(http_build_query(array_merge($params, ['p' => $page - 1]))) . '">‹ Anterior</a>';
    }
    for ($i = $start; $i <= $end; $i++) {
        $html .= $i === $page
            ? '<span class="cur">' . $i . '</span>'
            : '<a href="?' . e(http_build_query(array_merge($params, ['p' => $i]))) . '">' . $i . '</a>';
    }
    if ($page < $pages) {
        $html .= '<a href="?' . e(http_build_query(array_merge($params, ['p' => $page + 1]))) . '">Próxima ›</a>';
    }
    return $html . '<span class="muted"> ' . number_format($total, 0, ',', '.') . ' registros</span></div>';
}
