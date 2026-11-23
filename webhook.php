<?php
/**
 * Webhook da Meta: recebe status (enviada/entregue/lida/falhou) e mensagens dos contatos.
 * Configure no painel do app da Meta: WhatsApp → Configuração → Webhook.
 */
declare(strict_types=1);
define('NO_SESSION', true);
require __DIR__ . '/includes/bootstrap.php';

// ---------- Verificação (GET) ----------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $mode = (string)($_GET['hub_mode'] ?? '');
    $token = (string)($_GET['hub_verify_token'] ?? '');
    $challenge = (string)($_GET['hub_challenge'] ?? '');
    if ($mode === 'subscribe' && $token !== '' && hash_equals((string)setting('webhook_verify_token', ''), $token)) {
        header('Content-Type: text/plain');
        echo $challenge;
        log_event('info', 'Webhook verificado pela Meta com sucesso.');
        exit;
    }
    http_response_code(403);
    exit('Token de verificação inválido');
}

// ---------- Notificações (POST) ----------
$raw = (string)file_get_contents('php://input');
$secret = (string)setting('wa_app_secret', '');
if ($secret !== '') {
    $sig = (string)($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');
    if (!hash_equals('sha256=' . hash_hmac('sha256', $raw, $secret), $sig)) {
        http_response_code(401);
        log_event('aviso', 'Webhook recebido com assinatura inválida (confira a Chave Secreta do App).');
        exit;
    }
}
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    http_response_code(400);
    exit;
}

const STATUS_RANK = ['pendente' => 0, 'processando' => 0, 'enviada' => 1, 'entregue' => 2, 'lida' => 3];
const STATUS_MAP = ['sent' => 'enviada', 'delivered' => 'entregue', 'read' => 'lida', 'failed' => 'falhou'];

foreach (($payload['entry'] ?? []) as $entry) {
    foreach (($entry['changes'] ?? []) as $change) {
        if (($change['field'] ?? '') !== 'messages') {
            continue;
        }
        $value = $change['value'] ?? [];

        // ----- Status das mensagens enviadas -----
        foreach (($value['statuses'] ?? []) as $s) {
            $wamid = (string)($s['id'] ?? '');
            $novo = STATUS_MAP[$s['status'] ?? ''] ?? null;
            if ($wamid === '' || !$novo) {
                continue;
            }
            $m = q('SELECT id, status, contato_id FROM campanha_mensagens WHERE wamid = ? LIMIT 1', [$wamid])->fetch();
            if ($m) {
                if ($novo === 'falhou') {
                    $err = $s['errors'][0] ?? [];
                    $code = (string)($err['code'] ?? '');
                    $msg = (string)($err['error_data']['details'] ?? ($err['message'] ?? ($err['title'] ?? 'Falha na entrega')));
                    q("UPDATE campanha_mensagens SET status = 'falhou', erro_codigo = ?, erro = ?, atualizado_em = NOW() WHERE id = ?",
                        [$code, mb_substr($msg, 0, 480), $m['id']]);
                    if ($code === '131050') {
                        apply_optout((int)$m['contato_id']);
                    }
                } elseif ((STATUS_RANK[$novo] ?? 0) > (STATUS_RANK[$m['status']] ?? 99)) {
                    q('UPDATE campanha_mensagens SET status = ?, atualizado_em = NOW() WHERE id = ?', [$novo, $m['id']]);
                }
            }
            q('UPDATE conversas SET status = ? WHERE wamid = ?', [$novo, $wamid]);
            if ($novo === 'falhou') {
                $err = $s['errors'][0] ?? [];
                $code = (string)($err['code'] ?? '');
                $det = (string)($err['error_data']['details'] ?? ($err['message'] ?? ($err['title'] ?? '')));
                log_event('erro', 'Mensagem não entregue para ' . format_phone((string)($s['recipient_id'] ?? '')) . " — código $code: " . explain_meta_error($code, $det), ['wamid' => $wamid]);
            }
        }

        // ----- Mensagens recebidas -----
        $perfil = (string)($value['contacts'][0]['profile']['name'] ?? '');
        foreach (($value['messages'] ?? []) as $msg) {
            $from = preg_replace('/\D+/', '', (string)($msg['from'] ?? '')) ?? '';
            $wamid = (string)($msg['id'] ?? '');
            if ($from === '' || $wamid === '') {
                continue;
            }
            $tipo = (string)($msg['type'] ?? 'text');
            $texto = match ($tipo) {
                'text' => (string)($msg['text']['body'] ?? ''),
                'button' => (string)($msg['button']['text'] ?? ''),
                'interactive' => (string)($msg['interactive']['button_reply']['title'] ?? ($msg['interactive']['list_reply']['title'] ?? '[interativo]')),
                'image' => '[Imagem] ' . ($msg['image']['caption'] ?? ''),
                'video' => '[Vídeo] ' . ($msg['video']['caption'] ?? ''),
                'document' => '[Documento] ' . ($msg['document']['filename'] ?? ''),
                'audio' => '[Áudio]',
                'sticker' => '[Figurinha]',
                'location' => '[Localização]',
                'contacts' => '[Contato compartilhado]',
                'reaction' => '[Reação] ' . ($msg['reaction']['emoji'] ?? ''),
                default => '[' . $tipo . ']',
            };
            $quando = isset($msg['timestamp']) ? date('Y-m-d H:i:s', (int)$msg['timestamp']) : date('Y-m-d H:i:s');

            $contato = find_contact_by_phone($from);
            if ($contato) {
                q('UPDATE contatos SET ultima_interacao = ? WHERE id = ?', [$quando, $contato['id']]);
            }
            $ins = q('INSERT IGNORE INTO conversas (contato_id, telefone, direcao, nome_perfil, tipo, texto, wamid, lida, criado_em)
                      VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?)', [
                $contato['id'] ?? null, $contato['telefone'] ?? $from, 'entrada', $perfil ?: null, $tipo, trim($texto), $wamid, $quando,
            ]);
            if ($ins->rowCount() === 0) {
                continue; // notificação repetida
            }

            // Pedido de descadastro
            if (in_array($tipo, ['text', 'button', 'interactive'], true) && is_optout_text($texto)) {
                if ($contato && !(int)$contato['optout']) {
                    apply_optout((int)$contato['id']);
                    log_event('info', 'Contato ' . format_phone($contato['telefone']) . ' pediu descadastro pelo WhatsApp.');
                }
                $resposta = (string)setting('optout_resposta', '');
                if ($resposta !== '') {
                    $r = WhatsApp::fromSettings()->sendText($from, $resposta);
                    if ($r['ok']) {
                        q('INSERT IGNORE INTO conversas (contato_id, telefone, direcao, tipo, texto, wamid, status, lida, criado_em)
                           VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW())', [
                            $contato['id'] ?? null, $contato['telefone'] ?? $from, 'saida', 'text', $resposta,
                            $r['data']['messages'][0]['id'] ?? null, 'enviada',
                        ]);
                    }
                }
            }
        }
    }
}

http_response_code(200);
echo 'OK';
