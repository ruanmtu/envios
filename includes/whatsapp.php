<?php
declare(strict_types=1);

/**
 * Cliente da WhatsApp Cloud API (API oficial da Meta).
 * Documentação: https://developers.facebook.com/docs/whatsapp/cloud-api
 */
class WhatsApp
{
    public function __construct(
        private string $token,
        private string $phoneId,
        private string $wabaId,
        private string $version = 'v23.0'
    ) {
    }

    public static function fromSettings(): self
    {
        return new self(
            (string)setting('wa_token', ''),
            (string)setting('wa_phone_id', ''),
            (string)setting('wa_waba_id', ''),
            (string)setting('graph_version', 'v23.0')
        );
    }

    public function configured(): bool
    {
        return $this->token !== '' && $this->phoneId !== '';
    }

    /** @return array{ok:bool,status:int,data:array,error:?array{code:string,message:string}} */
    public function request(string $method, string $path, ?array $body = null, array $query = []): array
    {
        $url = 'https://graph.facebook.com/' . $this->version . '/' . ltrim($path, '/');
        if ($query) {
            $url .= '?' . http_build_query($query);
        }
        $headers = ['Authorization: Bearer ' . $this->token, 'Accept: application/json'];
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_CUSTOMREQUEST => $method,
        ];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return ['ok' => false, 'status' => 0, 'data' => [], 'error' => ['code' => 'rede', 'message' => 'Falha de conexão com a Meta: ' . $cerr]];
        }
        $data = json_decode((string)$raw, true);
        if (!is_array($data)) {
            $data = [];
        }
        if ($status >= 200 && $status < 300 && !isset($data['error'])) {
            return ['ok' => true, 'status' => $status, 'data' => $data, 'error' => null];
        }
        $err = $data['error'] ?? [];
        $msg = (string)($err['message'] ?? ('HTTP ' . $status));
        $details = $err['error_data']['details'] ?? ($err['error_user_msg'] ?? null);
        if (is_string($details) && $details !== '' && !str_contains($msg, $details)) {
            $msg .= ' — ' . $details;
        }
        return [
            'ok' => false,
            'status' => $status,
            'data' => $data,
            'error' => ['code' => (string)($err['code'] ?? $status), 'message' => $msg],
        ];
    }

    public function sendTemplate(string $to, string $name, string $lang, array $components = []): array
    {
        $tpl = ['name' => $name, 'language' => ['code' => $lang]];
        if ($components) {
            $tpl['components'] = $components;
        }
        return $this->request('POST', $this->phoneId . '/messages', [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'template',
            'template' => $tpl,
        ]);
    }

    public function sendText(string $to, string $text): array
    {
        return $this->request('POST', $this->phoneId . '/messages', [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'text',
            'text' => ['preview_url' => false, 'body' => $text],
        ]);
    }

    public function phoneInfo(): array
    {
        return $this->request('GET', $this->phoneId, null, [
            'fields' => 'display_phone_number,verified_name,quality_rating,name_status',
        ]);
    }

    public function messagingLimit(): array
    {
        return $this->request('GET', $this->phoneId, null, ['fields' => 'whatsapp_business_manager_messaging_limit']);
    }

    /** Lista todos os templates da conta (WABA), com paginação. */
    public function listTemplates(): array
    {
        if ($this->wabaId === '') {
            return ['ok' => false, 'status' => 0, 'data' => [], 'error' => ['code' => 'config', 'message' => 'Informe o WABA ID nas Configurações.']];
        }
        $all = [];
        $after = null;
        for ($i = 0; $i < 30; $i++) {
            $q = ['fields' => 'id,name,language,status,category,components,parameter_format', 'limit' => 100];
            if ($after) {
                $q['after'] = $after;
            }
            $r = $this->request('GET', $this->wabaId . '/message_templates', null, $q);
            if (!$r['ok']) {
                return $r;
            }
            $all = array_merge($all, $r['data']['data'] ?? []);
            $after = $r['data']['paging']['cursors']['after'] ?? null;
            if (empty($r['data']['paging']['next']) || !$after) {
                break;
            }
        }
        return ['ok' => true, 'status' => 200, 'data' => $all, 'error' => null];
    }
}

// ================== Templates ==================

function extract_params(string $text): array
{
    preg_match_all('/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/', $text, $m);
    return array_values(array_unique($m[1]));
}

/** Lê os componentes do template e descobre quais variáveis ele exige. */
function template_fields(array $components): array
{
    $f = ['header' => null, 'header_text' => '', 'body' => [], 'body_text' => '', 'footer_text' => '', 'buttons' => [], 'button_labels' => []];
    foreach ($components as $c) {
        $type = strtoupper((string)($c['type'] ?? ''));
        if ($type === 'HEADER') {
            $fmt = strtoupper((string)($c['format'] ?? 'TEXT'));
            if ($fmt === 'TEXT') {
                $f['header_text'] = (string)($c['text'] ?? '');
                $p = extract_params($f['header_text']);
                if ($p) {
                    $f['header'] = ['type' => 'text', 'params' => $p];
                }
            } elseif (in_array($fmt, ['IMAGE', 'VIDEO', 'DOCUMENT'], true)) {
                $f['header'] = ['type' => strtolower($fmt), 'params' => []];
            }
        } elseif ($type === 'BODY') {
            $f['body_text'] = (string)($c['text'] ?? '');
            $f['body'] = extract_params($f['body_text']);
        } elseif ($type === 'FOOTER') {
            $f['footer_text'] = (string)($c['text'] ?? '');
        } elseif ($type === 'BUTTONS') {
            foreach (($c['buttons'] ?? []) as $i => $b) {
                $f['button_labels'][] = (string)($b['text'] ?? '');
                if (strtoupper((string)($b['type'] ?? '')) === 'URL' && str_contains((string)($b['url'] ?? ''), '{{')) {
                    $f['buttons'][] = ['index' => (int)$i, 'url' => (string)$b['url'], 'text' => (string)($b['text'] ?? '')];
                }
            }
        }
    }
    return $f;
}

function make_text_param(string $name, string $value): array
{
    $p = ['type' => 'text', 'text' => clean_param($value)];
    if (!ctype_digit($name)) {
        $p['parameter_name'] = $name; // templates com variáveis nomeadas
    }
    return $p;
}

/** Monta o array "components" da API para um contato, a partir da configuração da campanha. */
function build_components(array $cfg, array $contato): array
{
    $out = [];
    $h = $cfg['header'] ?? null;
    if ($h) {
        if ($h['type'] === 'text') {
            $params = [];
            foreach ($h['params'] as $p) {
                $params[] = make_text_param((string)$p['name'], render_placeholders((string)$p['value'], $contato));
            }
            $out[] = ['type' => 'header', 'parameters' => $params];
        } else {
            $media = ['link' => (string)$h['url']];
            if ($h['type'] === 'document' && !empty($h['filename'])) {
                $media['filename'] = (string)$h['filename'];
            }
            $out[] = ['type' => 'header', 'parameters' => [['type' => $h['type'], $h['type'] => $media]]];
        }
    }
    if (!empty($cfg['body'])) {
        $params = [];
        foreach ($cfg['body'] as $p) {
            $params[] = make_text_param((string)$p['name'], render_placeholders((string)$p['value'], $contato));
        }
        $out[] = ['type' => 'body', 'parameters' => $params];
    }
    foreach (($cfg['buttons'] ?? []) as $b) {
        $out[] = [
            'type' => 'button',
            'sub_type' => 'url',
            'index' => (string)$b['index'],
            'parameters' => [['type' => 'text', 'text' => clean_param(render_placeholders((string)$b['value'], $contato))]],
        ];
    }
    return $out;
}

/** Texto aproximado de como a mensagem chegará ao contato (pré-visualização). */
function preview_message(array $fields, array $cfg, array $contato): string
{
    $map = [];
    foreach (($cfg['body'] ?? []) as $p) {
        $map['body'][$p['name']] = clean_param(render_placeholders((string)$p['value'], $contato));
    }
    if (($cfg['header']['type'] ?? '') === 'text') {
        foreach ($cfg['header']['params'] as $p) {
            $map['header'][$p['name']] = clean_param(render_placeholders((string)$p['value'], $contato));
        }
    }
    $fill = function (string $text, array $vals): string {
        return preg_replace_callback('/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/', fn($m) => $vals[$m[1]] ?? $m[0], $text) ?? $text;
    };
    $parts = [];
    $ht = $cfg['header']['type'] ?? null;
    if ($ht && $ht !== 'text') {
        $parts[] = '[' . ['image' => 'Imagem', 'video' => 'Vídeo', 'document' => 'Documento'][$ht] . ']';
    } elseif ($fields['header_text'] !== '') {
        $parts[] = '*' . $fill($fields['header_text'], $map['header'] ?? []) . '*';
    }
    $parts[] = $fill($fields['body_text'], $map['body'] ?? []);
    if ($fields['footer_text'] !== '') {
        $parts[] = '_' . $fields['footer_text'] . '_';
    }
    if ($fields['button_labels']) {
        $parts[] = implode('  |  ', array_map(fn($l) => '[' . $l . ']', $fields['button_labels']));
    }
    return implode("\n\n", $parts);
}

function tier_to_number(string $tier): ?int
{
    return [
        'TIER_250' => 250, 'TIER_1K' => 1000, 'TIER_2K' => 2000, 'TIER_10K' => 10000,
        'TIER_100K' => 100000, 'TIER_UNLIMITED' => 0, 'UNLIMITED' => 0,
    ][strtoupper($tier)] ?? null;
}
