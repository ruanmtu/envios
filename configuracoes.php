<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
$user = require_admin();

if (is_post()) {
    csrf_check();
    $op = post('op');

    if ($op === 'api') {
        setting_set('wa_phone_id', preg_replace('/\D+/', '', post('wa_phone_id')));
        setting_set('wa_waba_id', preg_replace('/\D+/', '', post('wa_waba_id')));
        $ver = post('graph_version');
        setting_set('graph_version', preg_match('/^v\d+\.\d+$/', $ver) ? $ver : 'v23.0');
        if (post('wa_token') !== '') {
            setting_set('wa_token', preg_replace('/\s+/', '', post('wa_token')));
        }
        if (post('wa_app_secret') !== '') {
            setting_set('wa_app_secret', post('wa_app_secret'));
        }
        if (isset($_POST['limpar_secret'])) {
            setting_set('wa_app_secret', '');
        }
        log_event('info', "Configurações da API alteradas por {$user['usuario']}");
        flash('sucesso', 'Dados da API salvos. Clique em "Testar conexão" para conferir.');
        redirect('configuracoes.php');
    }

    if ($op === 'testar') {
        $wa = WhatsApp::fromSettings();
        if (!$wa->configured()) {
            flash('erro', 'Informe o Phone Number ID e o Token primeiro.');
            redirect('configuracoes.php');
        }
        $r = $wa->phoneInfo();
        if (!$r['ok']) {
            flash('erro', 'Falha na conexão (código ' . $r['error']['code'] . '): ' . $r['error']['message']);
            redirect('configuracoes.php');
        }
        $d = $r['data'];
        $msg = "Conexão OK!\nNúmero: " . ($d['display_phone_number'] ?? '?')
            . "\nNome verificado: " . ($d['verified_name'] ?? '?')
            . "\nQualidade: " . ($d['quality_rating'] ?? '?');
        $lim = $wa->messagingLimit();
        $tier = (string)($lim['data']['whatsapp_business_manager_messaging_limit'] ?? '');
        if ($lim['ok'] && $tier !== '') {
            $num = tier_to_number($tier);
            $msg .= "\nLimite da Meta: " . $tier . ($num !== null ? ' (' . ($num === 0 ? 'ilimitado' : number_format($num, 0, ',', '.') . ' contatos/24h') . ')' : '');
            if ($num !== null && isset($_POST['ajustar_limite'])) {
                setting_set('limite_diario', (string)$num);
                $msg .= "\nO limite diário do sistema foi ajustado automaticamente.";
            }
        }
        $wr = $wa->listTemplates();
        $msg .= $wr['ok'] ? "\nWABA OK (" . count($wr['data']) . ' templates encontrados).' : "\nWABA ID: erro — " . $wr['error']['message'];
        flash('sucesso', $msg);
        redirect('configuracoes.php');
    }

    if ($op === 'envio') {
        setting_set('limite_diario', (string)max(0, (int)post('limite_diario')));
        setting_set('lote_por_minuto', (string)max(1, min(500, (int)post('lote_por_minuto'))));
        setting_set('intervalo_ms', (string)max(0, min(5000, (int)post('intervalo_ms'))));
        setting_set('palavras_optout', mb_substr(post('palavras_optout'), 0, 500) ?: 'PARAR,SAIR');
        setting_set('optout_resposta', mb_substr(trim((string)($_POST['optout_resposta'] ?? '')), 0, 1000));
        flash('sucesso', 'Configurações de envio salvas.');
        redirect('configuracoes.php');
    }

    if ($op === 'novo_verify') {
        setting_set('webhook_verify_token', bin2hex(random_bytes(16)));
        flash('aviso', 'Novo token de verificação gerado. Atualize-o também no painel da Meta.');
        redirect('configuracoes.php#webhook');
    }

    if ($op === 'nova_chave_cron') {
        setting_set('cron_chave', bin2hex(random_bytes(20)));
        flash('aviso', 'Nova chave do Cron gerada. Se você usa o Cron por URL, atualize o link.');
        redirect('configuracoes.php#cron');
    }

    if ($op === 'teste_hello') {
        $tel = normalize_phone(post('telefone'));
        if ($tel === '') {
            flash('erro', 'Número inválido.');
            redirect('configuracoes.php');
        }
        $r = WhatsApp::fromSettings()->sendTemplate($tel, 'hello_world', 'en_US');
        $r['ok']
            ? flash('sucesso', 'Template hello_world enviado para ' . format_phone($tel) . '.')
            : flash('erro', 'Falha (código ' . $r['error']['code'] . '): ' . $r['error']['message']);
        redirect('configuracoes.php');
    }
}

$base = base_url();
$webhookUrl = $base . '/webhook.php';
$cronPath = APP_ROOT . '/cron/enviar.php';
$cronUrl = $base . '/cron/enviar.php?chave=' . setting('cron_chave', '');
$token = (string)setting('wa_token', '');
$cronUltima = setting('cron_ultima');

page_header('Configurações', 'configuracoes');
?>
<h1>Configurações</h1>

<div class="card">
  <div class="section-title"><span class="num">1</span><h2>Conexão com a API do WhatsApp (Meta)</h2></div>
  <p class="small muted">Os dados ficam em <a href="https://developers.facebook.com/apps" target="_blank" rel="noopener">developers.facebook.com</a> → seu app → WhatsApp → <strong>Configuração da API</strong>.
    Use um <strong>token permanente</strong> de Usuário do Sistema (Configurações do Negócio → Usuários do sistema), com as permissões
    <code>whatsapp_business_messaging</code> e <code>whatsapp_business_management</code>. O token temporário expira em 24h.</p>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="op" value="api">
    <div class="row">
      <div><label>Phone Number ID (Identificação do número)</label><input type="text" name="wa_phone_id" value="<?= e(setting('wa_phone_id', '')) ?>" placeholder="123456789012345"></div>
      <div><label>WABA ID (Identificação da conta do WhatsApp Business)</label><input type="text" name="wa_waba_id" value="<?= e(setting('wa_waba_id', '')) ?>" placeholder="123456789012345"></div>
    </div>
    <label>Access Token</label>
    <input type="password" name="wa_token" placeholder="<?= $token ? 'Salvo: …' . e(substr($token, -6)) . ' (deixe em branco para manter)' : 'Cole aqui o token' ?>" autocomplete="off">
    <div class="row">
      <div><label>Chave Secreta do App (App Secret), opcional</label>
        <input type="password" name="wa_app_secret" placeholder="<?= setting('wa_app_secret') ? 'Salva (deixe em branco para manter)' : 'Recomendado: valida a assinatura do webhook' ?>" autocomplete="off">
        <div class="help">Fica em Configurações do app → Básico. Com ela, o sistema só aceita notificações realmente vindas da Meta.</div>
        <?php if (setting('wa_app_secret')): ?><label class="check"><input type="checkbox" name="limpar_secret" value="1"> Remover a chave secreta</label><?php endif; ?>
      </div>
      <div><label>Versão da Graph API</label><input type="text" name="graph_version" value="<?= e(setting('graph_version', 'v23.0')) ?>">
        <div class="help">Mantenha a padrão, a menos que a Meta descontinue essa versão.</div></div>
    </div>
    <div class="actions"><button class="btn" type="submit">Salvar</button></div>
  </form>
  <form method="post" style="margin-top:10px">
    <?= csrf_field() ?><input type="hidden" name="op" value="testar">
    <label class="check"><input type="checkbox" name="ajustar_limite" value="1" checked> Ajustar o limite diário do sistema ao limite atual da Meta</label>
    <button class="btn sec" type="submit">Testar conexão</button>
  </form>
</div>

<div class="card" id="webhook">
  <div class="section-title"><span class="num">2</span><h2>Webhook (status de entrega e respostas)</h2></div>
  <p class="small muted">No app da Meta: WhatsApp → <strong>Configuração</strong> → Webhook → Editar. Cole os dados abaixo, clique em "Verificar e salvar" e depois <strong>assine o campo <code>messages</code></strong>.</p>
  <dl class="kv">
    <dt>URL de retorno</dt><dd><code><?= e($webhookUrl) ?></code></dd>
    <dt>Token de verificação</dt><dd><code><?= e(setting('webhook_verify_token', '')) ?></code></dd>
  </dl>
  <?php if (!is_https()): ?><div class="alert aviso" style="margin-top:12px">Você está acessando sem HTTPS. A Meta exige https. Ative o SSL do domínio na Hostinger.</div><?php endif; ?>
  <form method="post" data-confirm="Gerar um novo token? Você precisará atualizá-lo na Meta." style="margin-top:12px">
    <?= csrf_field() ?><input type="hidden" name="op" value="novo_verify"><button class="btn sec sm" type="submit">Gerar novo token</button></form>
</div>

<div class="card" id="cron">
  <div class="section-title"><span class="num">3</span><h2>Cron Job (envio automático)</h2></div>
  <p class="small">Na Hostinger: <strong>hPanel → Avançado → Cron Jobs</strong> → tipo <strong>PHP</strong> → no campo do comando, informe o caminho abaixo → frequência <strong>a cada minuto</strong> (* * * * *).</p>
  <dl class="kv">
    <dt>Caminho do script</dt><dd><code><?= e($cronPath) ?></code></dd>
    <dt>Alternativa (URL)</dt><dd><code><?= e($cronUrl) ?></code></dd>
    <dt>Última execução</dt><dd><?= $cronUltima ? e(fmt_dt($cronUltima)) . ((time() - strtotime($cronUltima)) > 300 ? ' <span class="badge vermelho">atrasado</span>' : ' <span class="badge verde">ok</span>') : '<span class="badge vermelho">nunca rodou</span>' ?></dd>
  </dl>
  <p class="help">Se o hPanel pedir só o caminho relativo, use algo como <code>domains/SEUDOMINIO/public_html/cron/enviar.php</code>. A URL alternativa serve para serviços externos de cron. Mantenha a chave em segredo.</p>
  <form method="post" data-confirm="Gerar nova chave do Cron?" style="margin-top:8px">
    <?= csrf_field() ?><input type="hidden" name="op" value="nova_chave_cron"><button class="btn sec sm" type="submit">Gerar nova chave</button></form>
</div>

<div class="card">
  <div class="section-title"><span class="num">4</span><h2>Regras de envio</h2></div>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="op" value="envio">
    <div class="row">
      <div><label>Limite de contatos únicos a cada 24h</label><input type="number" name="limite_diario" min="0" value="<?= e(setting('limite_diario', '250')) ?>">
        <div class="help">Use o limite da sua conta na Meta: 250 (inicial), 2.000, 10.000, 100.000. 0 = sem limite.</div></div>
      <div><label>Mensagens por minuto (lote do Cron)</label><input type="number" name="lote_por_minuto" min="1" max="500" value="<?= e(setting('lote_por_minuto', '30')) ?>">
        <div class="help">Em hospedagem compartilhada, 30 a 60 é o recomendado.</div></div>
      <div><label>Intervalo entre mensagens (ms)</label><input type="number" name="intervalo_ms" min="0" max="5000" value="<?= e(setting('intervalo_ms', '300')) ?>"></div>
    </div>
    <label>Palavras de descadastro</label>
    <input type="text" name="palavras_optout" value="<?= e(setting('palavras_optout', 'PARAR,SAIR,STOP,CANCELAR,DESCADASTRAR')) ?>">
    <div class="help">Separadas por vírgula. Se o contato responder exatamente uma delas, ele sai das campanhas.</div>
    <label>Resposta automática ao descadastro (opcional)</label>
    <textarea name="optout_resposta"><?= e(setting('optout_resposta', '')) ?></textarea>
    <div class="actions"><button class="btn" type="submit">Salvar regras</button></div>
  </form>
</div>

<div class="card">
  <div class="section-title"><span class="num">5</span><h2>Teste rápido</h2></div>
  <p class="small muted">Envia o template padrão <code>hello_world</code> da Meta. Com o número de teste da Meta, o destino precisa estar cadastrado na lista de destinatários do app.</p>
  <form method="post" class="toolbar">
    <?= csrf_field() ?><input type="hidden" name="op" value="teste_hello">
    <div class="grow"><input type="text" name="telefone" placeholder="Seu WhatsApp com DDD" required></div>
    <button class="btn sec" type="submit">Enviar hello_world</button>
  </form>
</div>
<?php page_footer();
