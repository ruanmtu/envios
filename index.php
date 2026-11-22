<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

$aptos = (int)q('SELECT COUNT(*) FROM contatos WHERE optin = 1 AND optout = 0')->fetchColumn();
$totalContatos = (int)q('SELECT COUNT(*) FROM contatos')->fetchColumn();
$usados24h = (int)q('SELECT COUNT(DISTINCT telefone) FROM campanha_mensagens WHERE wamid IS NOT NULL AND enviado_em >= (NOW() - INTERVAL 24 HOUR)')->fetchColumn();
$limite = (int)setting('limite_diario', '250');
$naFila = (int)q("SELECT COUNT(*) FROM campanha_mensagens m JOIN campanhas c ON c.id = m.campanha_id
                  WHERE m.status = 'pendente' AND c.status IN ('enviando','agendada')")->fetchColumn();

$st7 = q("SELECT
            SUM(wamid IS NOT NULL) AS enviadas,
            SUM(status IN ('entregue','lida')) AS entregues,
            SUM(status = 'lida') AS lidas,
            SUM(status = 'falhou') AS falhas
          FROM campanha_mensagens WHERE atualizado_em >= (NOW() - INTERVAL 7 DAY)")->fetch();
$env7 = (int)($st7['enviadas'] ?? 0);
$pct = fn($n) => $env7 > 0 ? round(((int)$n / $env7) * 100) . '%' : '—';

$cronUltima = setting('cron_ultima');
$cronAtrasado = !$cronUltima || (time() - strtotime($cronUltima)) > 300;
$apiOk = WhatsApp::fromSettings()->configured();

$campanhas = q('SELECT c.*,
        (SELECT COUNT(*) FROM campanha_mensagens m WHERE m.campanha_id = c.id AND m.wamid IS NOT NULL) AS enviadas
    FROM campanhas c ORDER BY c.id DESC LIMIT 6')->fetchAll();

page_header('Painel', 'painel');
?>
<h1>Olá, <?= e(explode(' ', $user['nome'])[0]) ?> 👋</h1>

<?php if (!$apiOk): ?>
  <div class="alert aviso">A conexão com a API da Meta ainda não foi configurada.
    <?php if ($user['perfil'] === 'admin'): ?><a href="configuracoes.php">Configurar agora</a><?php endif; ?></div>
<?php endif; ?>
<?php if ($cronAtrasado): ?>
  <div class="alert aviso">O envio automático (Cron Job) não rodou nos últimos 5 minutos<?= $cronUltima ? ' (última execução: ' . e(fmt_dt($cronUltima)) . ')' : '' ?>.
    Sem ele, as campanhas ficam paradas na fila.
    <?php if ($user['perfil'] === 'admin'): ?><a href="configuracoes.php#cron">Veja como configurar</a><?php endif; ?></div>
<?php endif; ?>

<div class="grid">
  <div class="stat"><div class="n"><?= number_format($aptos, 0, ',', '.') ?></div><div class="l">Contatos aptos (com opt-in)</div></div>
  <div class="stat"><div class="n"><?= number_format($totalContatos, 0, ',', '.') ?></div><div class="l">Contatos cadastrados</div></div>
  <div class="stat">
    <div class="n"><?= number_format($usados24h, 0, ',', '.') ?><span class="muted" style="font-size:16px"> / <?= $limite > 0 ? number_format($limite, 0, ',', '.') : '∞' ?></span></div>
    <div class="l">Contatos alcançados nas últimas 24h</div>
    <?php if ($limite > 0): ?><div class="progress" style="margin-top:8px"><div style="width:<?= min(100, (int)round($usados24h / max(1, $limite) * 100)) ?>%"></div></div><?php endif; ?>
  </div>
  <div class="stat"><div class="n"><?= number_format($naFila, 0, ',', '.') ?></div><div class="l">Mensagens na fila</div></div>
</div>

<div class="card">
  <h2>Últimos 7 dias</h2>
  <div class="grid" style="margin-bottom:0">
    <div><div class="n" style="font-size:22px;font-weight:700"><?= number_format($env7, 0, ',', '.') ?></div><div class="muted small">Enviadas</div></div>
    <div><div style="font-size:22px;font-weight:700"><?= $pct($st7['entregues'] ?? 0) ?></div><div class="muted small">Entregues</div></div>
    <div><div style="font-size:22px;font-weight:700"><?= $pct($st7['lidas'] ?? 0) ?></div><div class="muted small">Lidas</div></div>
    <div><div style="font-size:22px;font-weight:700"><?= number_format((int)($st7['falhas'] ?? 0), 0, ',', '.') ?></div><div class="muted small">Falhas</div></div>
  </div>
</div>

<div class="card">
  <div class="head"><h2 style="margin:0">Campanhas recentes</h2><a class="btn" href="campanhas.php?acao=nova">+ Nova campanha</a></div>
  <?php if (!$campanhas): ?>
    <p class="muted">Nenhuma campanha ainda. Comece sincronizando seus <a href="templates.php">templates</a> e importando seus <a href="contatos.php">contatos</a>.</p>
  <?php else: ?>
  <div class="table-wrap"><table>
    <tr><th>Campanha</th><th>Status</th><th>Progresso</th><th>Criada em</th></tr>
    <?php foreach ($campanhas as $c): $p = $c['total'] > 0 ? (int)round($c['enviadas'] / $c['total'] * 100) : 0; ?>
      <tr>
        <td><a href="campanha.php?id=<?= (int)$c['id'] ?>"><?= e($c['nome']) ?></a><div class="muted small"><?= e($c['template_nome']) ?></div></td>
        <td><?= badge($c['status']) ?></td>
        <td><div class="progress"><div style="width:<?= $p ?>%"></div></div><span class="small muted"><?= (int)$c['enviadas'] ?>/<?= (int)$c['total'] ?></span></td>
        <td class="nowrap"><?= e(fmt_dt($c['criado_em'])) ?></td>
      </tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>
</div>
<?php page_footer();
