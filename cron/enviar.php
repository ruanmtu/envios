<?php
/**
 * Processa a fila de envio. Deve rodar a cada minuto pelo Cron Job da Hostinger.
 *   Comando (hPanel → Avançado → Cron Jobs → tipo PHP):  caminho/para/cron/enviar.php
 *   Alternativa por URL: https://seusite.com.br/cron/enviar.php?chave=SUA_CHAVE
 */
declare(strict_types=1);
define('NO_SESSION', true);
require dirname(__DIR__) . '/includes/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    $k = $_GET['chave'] ?? '';
    if (!is_string($k) || $k === '' || !hash_equals((string)setting('cron_chave', ''), $k)) {
        http_response_code(403);
        exit('Acesso negado');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

@set_time_limit(120);
ignore_user_abort(true);

function out(string $s): void
{
    echo date('H:i:s') . ' ' . $s . PHP_EOL;
}

// Códigos de erro da Meta
const ERR_TOKEN = ['190', '10', '200', '3'];                                  // token/permissão
const ERR_RATE = ['4', '80007', '130429', '131048', '131056', '133016'];     // limites de velocidade: tenta depois
// problemas no template, pagamento ou conta: pausa a campanha para não gerar falhas em massa
const ERR_TEMPLATE = ['132000', '132001', '132005', '132007', '132012', '132015', '132016', '132068', '132069', '131042', '131031', '368'];
const ERR_TEMP = ['1', '2', '131000', '131016', 'rede'];                    // falhas temporárias

function run_queue(): void
{
    setting_set('cron_ultima', date('Y-m-d H:i:s'));

    // destrava mensagens presas (ex.: execução interrompida)
    q("UPDATE campanha_mensagens SET status = 'pendente' WHERE status = 'processando' AND atualizado_em < (NOW() - INTERVAL 10 MINUTE)");

    // ativa campanhas agendadas
    $n = q("UPDATE campanhas SET status = 'enviando', iniciado_em = COALESCE(iniciado_em, NOW())
            WHERE status = 'agendada' AND agendar_para <= NOW()")->rowCount();
    if ($n) {
        out("$n campanha(s) agendada(s) iniciada(s).");
    }

    $wa = WhatsApp::fromSettings();
    if (!$wa->configured()) {
        out('API da Meta não configurada.');
        return;
    }

    $limite = (int)setting('limite_diario', '250');
    $lote = max(1, min(500, (int)setting('lote_por_minuto', '30')));
    $intervalo = max(0, min(5000, (int)setting('intervalo_ms', '300')));

    $usados = (int)q('SELECT COUNT(DISTINCT telefone) FROM campanha_mensagens
                      WHERE wamid IS NOT NULL AND enviado_em >= (NOW() - INTERVAL 24 HOUR)')->fetchColumn();
    $disponivel = $limite > 0 ? $limite - $usados : PHP_INT_MAX;
    if ($disponivel <= 0) {
        out("Limite de $limite contatos em 24h atingido ($usados). Aguardando.");
        finish_campaigns();
        return;
    }
    $qtd = (int)min($lote, $disponivel);

    $rows = q("SELECT m.id, m.campanha_id, m.contato_id, m.telefone, m.tentativas,
                      ct.id AS cid, ct.nome, ct.campo1, ct.campo2, ct.campo3, ct.optin, ct.optout
               FROM campanha_mensagens m
               JOIN campanhas k ON k.id = m.campanha_id
               LEFT JOIN contatos ct ON ct.id = m.contato_id
               WHERE k.status = 'enviando' AND m.status = 'pendente'
               ORDER BY m.campanha_id, m.id
               LIMIT $qtd")->fetchAll();

    if (!$rows) {
        out('Nada na fila.');
        finish_campaigns();
        return;
    }

    $camps = [];
    $pausadas = [];
    $enviados = $falhas = 0;
    $inicio = microtime(true);

    foreach ($rows as $r) {
        if (microtime(true) - $inicio > 50) {
            break;
        }
        $mid = (int)$r['id'];
        $cid = (int)$r['campanha_id'];
        if (isset($pausadas[$cid])) {
            continue;
        }
        if (!$r['cid'] || (int)$r['optout'] === 1 || (int)$r['optin'] !== 1) {
            q("UPDATE campanha_mensagens SET status = 'cancelada', erro = 'Contato sem opt-in, descadastrado ou excluído', atualizado_em = NOW() WHERE id = ?", [$mid]);
            continue;
        }
        // reserva a mensagem (evita envio duplicado)
        if (q("UPDATE campanha_mensagens SET status = 'processando', atualizado_em = NOW() WHERE id = ? AND status = 'pendente'", [$mid])->rowCount() === 0) {
            continue;
        }
        if (!isset($camps[$cid])) {
            $k = q('SELECT * FROM campanhas WHERE id = ?', [$cid])->fetch();
            $camps[$cid] = ['nome' => $k['template_nome'], 'idioma' => $k['template_idioma'], 'cfg' => json_decode((string)$k['config'], true) ?: []];
        }
        $camp = $camps[$cid];
        $contato = ['nome' => $r['nome'], 'telefone' => $r['telefone'], 'campo1' => $r['campo1'], 'campo2' => $r['campo2'], 'campo3' => $r['campo3']];

        $res = $wa->sendTemplate($r['telefone'], $camp['nome'], $camp['idioma'], build_components($camp['cfg'], $contato));

        if ($res['ok']) {
            $wamid = $res['data']['messages'][0]['id'] ?? null;
            q("UPDATE campanha_mensagens SET status = 'enviada', wamid = ?, enviado_em = NOW(), atualizado_em = NOW(),
               tentativas = tentativas + 1, erro = NULL, erro_codigo = NULL WHERE id = ?", [$wamid, $mid]);
            $enviados++;
        } else {
            $code = (string)$res['error']['code'];
            $msg = mb_substr($res['error']['message'], 0, 480);
            $tent = (int)$r['tentativas'] + 1;

            if (in_array($code, ERR_TOKEN, true) || $res['status'] === 401) {
                q("UPDATE campanha_mensagens SET status = 'pendente', atualizado_em = NOW() WHERE id = ?", [$mid]);
                q("UPDATE campanhas SET status = 'pausada' WHERE status = 'enviando'");
                log_event('erro', 'Token da Meta inválido ou sem permissão. Campanhas pausadas. Atualize o token em Configurações.', $res['error']);
                out("ERRO de autenticação ($code): $msg. Campanhas pausadas.");
                return;
            }
            if (in_array($code, ERR_RATE, true)) {
                q("UPDATE campanha_mensagens SET status = 'pendente', atualizado_em = NOW() WHERE id = ?", [$mid]);
                log_event('aviso', "Limite de velocidade da Meta ($code). Envio retomado no próximo minuto.", $res['error']);
                out("Limite de velocidade ($code). Parando esta execução.");
                break;
            }
            if (in_array($code, ERR_TEMPLATE, true)) {
                q("UPDATE campanha_mensagens SET status = 'falhou', erro_codigo = ?, erro = ?, tentativas = ?, atualizado_em = NOW() WHERE id = ?", [$code, $msg, $tent, $mid]);
                q("UPDATE campanhas SET status = 'pausada' WHERE id = ?", [$cid]);
                $pausadas[$cid] = true;
                log_event('erro', "Campanha #$cid pausada: problema no template, pagamento ou conta ($code) - $msg", $res['error']);
                out("Campanha #$cid pausada: template ($code) $msg");
                $falhas++;
                continue;
            }
            if ((in_array($code, ERR_TEMP, true) || $res['status'] >= 500) && $tent < 3) {
                q("UPDATE campanha_mensagens SET status = 'pendente', tentativas = ?, erro_codigo = ?, erro = ?, atualizado_em = NOW() WHERE id = ?", [$tent, $code, $msg, $mid]);
                out("Falha temporária ($code) para {$r['telefone']}, nova tentativa depois.");
                if ($code === 'rede') {
                    break;
                }
                continue;
            }
            q("UPDATE campanha_mensagens SET status = 'falhou', erro_codigo = ?, erro = ?, tentativas = ?, atualizado_em = NOW() WHERE id = ?", [$code, $msg, $tent, $mid]);
            if ($code === '131050') { // usuário parou de receber marketing
                apply_optout((int)$r['cid']);
            }
            $falhas++;
        }
        if ($intervalo > 0) {
            usleep($intervalo * 1000);
        }
    }
    out("Enviadas: $enviados · Falhas: $falhas · Usados 24h: " . ($usados + $enviados) . ($limite > 0 ? "/$limite" : ''));
    finish_campaigns();
}

function finish_campaigns(): void
{
    $n = q("UPDATE campanhas k SET status = 'concluida', concluido_em = NOW()
            WHERE k.status = 'enviando' AND NOT EXISTS (
              SELECT 1 FROM campanha_mensagens m WHERE m.campanha_id = k.id AND m.status IN ('pendente','processando'))")->rowCount();
    if ($n) {
        out("$n campanha(s) concluída(s).");
    }
}

$lock = (int)q("SELECT GET_LOCK('disparador_fila', 0)")->fetchColumn();
if ($lock !== 1) {
    out('Outra execução já está em andamento.');
    exit;
}
try {
    run_queue();
} finally {
    q("SELECT RELEASE_LOCK('disparador_fila')");
}
