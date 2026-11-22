# Disparador WhatsApp: guia de instalação (Hostinger)

Plataforma para envio de mensagens em massa pela **API oficial do WhatsApp (Cloud API da Meta)**. Ela foi feita para rodar em hospedagem compartilhada com PHP 8.1+ e MySQL.

## Acesso inicial

O usuário padrão é `admin`. A senha você define no instalador (`install.php`). No primeiro login, o sistema pede uma nova senha.

## Deploy automático (GitHub → Hostinger)

Cada envio para a branch `main` dispara o GitHub Actions, que sobe os arquivos para a pasta `painel/` do site (dentro de `public_html`) por FTP.
Cadastre em *Settings → Secrets and variables → Actions*: `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD`.
Arquivos gerados no servidor (`includes/config.php` e `includes/install.lock`) não fazem parte do repositório e não são apagados pelo deploy.

---

## 1. Subir os arquivos

1. No hPanel, abra **Arquivos → Gerenciador de Arquivos** → pasta `public_html`.
2. Com o deploy automático configurado, os arquivos chegam sozinhos em `public_html/painel`. Se for subir manualmente, envie o `.zip` e use a opção **Extrair**.
3. O sistema ficará em `https://seudominio.com.br/painel/`.
   - Para usar um subdomínio (ex.: `painel.seudominio.com.br`), crie-o em **Domínios → Subdomínios** e extraia o conteúdo da pasta `painel` dentro da pasta dele.
4. Confirme que o **SSL (HTTPS)** está ativo no domínio (hPanel → Segurança → SSL). A Meta exige HTTPS.
5. Em **Avançado → Configuração do PHP**, use **PHP 8.1 ou superior** (recomendado 8.2/8.3).

## 2. Criar o banco de dados

hPanel → **Bancos de dados → Gerenciamento de bancos MySQL** → crie um banco, um usuário e uma senha. Anote os três (a Hostinger adiciona um prefixo, como `u123456789_`).

## 3. Rodar o instalador

1. Acesse `https://seudominio.com.br/painel/install.php`.
2. Preencha os dados do banco. Os dados do administrador já vêm preenchidos.
3. Clique em **Instalar**.
4. **Exclua o arquivo `install.php`** pelo Gerenciador de Arquivos.
5. Entre em `https://seudominio.com.br/painel/` e defina sua nova senha.

## 4. Conectar com a Meta

No [Meta for Developers](https://developers.facebook.com/apps):

1. **Crie um app** do tipo *Empresa* (Business) e adicione o produto **WhatsApp**.
2. Em **WhatsApp → Configuração da API**, adicione e verifique o seu número de telefone. Esse número não pode estar em uso no aplicativo do WhatsApp; se estiver, exclua a conta do app nele antes.
3. Copie o **Phone Number ID** e o **WhatsApp Business Account ID (WABA ID)**.
4. Gere um **token permanente**:
   - [Configurações do Negócio](https://business.facebook.com/settings) → **Usuários do sistema** → Adicionar (função Administrador).
   - Clique em **Atribuir ativos** → selecione o app e a conta do WhatsApp com controle total.
   - Clique em **Gerar token** → selecione o app → marque `whatsapp_business_messaging` e `whatsapp_business_management` → expiração **Nunca**.
5. Cadastre uma **forma de pagamento** na conta do WhatsApp (WhatsApp Manager → Configurações de pagamento). As mensagens de template são cobradas pela Meta.
6. No painel, vá em **Configurações → 1. Conexão**, cole os dados, salve e clique em **Testar conexão**.
7. (Recomendado) Copie a **Chave Secreta do App** (Configurações do app → Básico) para o campo App Secret.

### Webhook (status de entrega e respostas)

No app da Meta: **WhatsApp → Configuração → Webhook → Editar**:

- **URL de retorno:** `https://seudominio.com.br/painel/webhook.php`
- **Token de verificação:** está em *Configurações → 2. Webhook* no painel.
- Clique em **Verificar e salvar**, depois em **Gerenciar** e **assine o campo `messages`**.
- Coloque o app em modo **Ativo/Publicado** para receber eventos de números reais.

## 5. Ativar o envio automático (Cron Job)

hPanel → **Avançado → Cron Jobs**:

- Tipo: **PHP**
- Comando: o caminho mostrado em *Configurações → 3. Cron Job*, por exemplo `domains/seudominio.com.br/public_html/painel/cron/enviar.php`
- Frequência: **A cada minuto** (`* * * * *`)

Sem o cron, as campanhas ficam paradas na fila. O painel avisa quando ele não roda há mais de 5 minutos.

## 6. Usar

1. **Templates:** crie modelos no [WhatsApp Manager](https://business.facebook.com/wa/manage/message-templates/), aguarde a aprovação e clique em **Sincronizar**.
   - Dica: em templates de marketing, inclua um botão ou rodapé como "Responda PARAR para não receber mais".
2. **Contatos:** importe o CSV (veja `modelo-contatos.csv`) ou cadastre manualmente. Só recebem campanhas os contatos **com opt-in e não descadastrados**.
3. **Campanhas:** crie a campanha, escolha o template e preencha as variáveis. Depois envie um **teste para o seu número** e clique em **Iniciar envio**.
4. **Conversas:** veja as respostas e responda em até 24h após a última mensagem do cliente.

### Variáveis disponíveis nas campanhas

`{nome}` `{primeiro_nome}` `{telefone}` `{campo1}` `{campo2}` `{campo3}`. Valor padrão: `{primeiro_nome|Cliente}`.

## Limites da Meta

- Contas novas: **250 contatos únicos a cada 24h**. Com a **verificação da empresa** na Meta, o limite sobe para **2.000** e depois aumenta automaticamente (10 mil, 100 mil, ilimitado) se a qualidade se mantiver boa.
- Ajuste em *Configurações → 4. Regras de envio*. O botão **Testar conexão** pode ajustar o limite automaticamente.
- O que passar do limite fica na fila e sai automaticamente nas 24h seguintes.

## Boas práticas (evitam bloqueio do número)

- Envie **somente para quem autorizou** (opt-in). Nunca use listas compradas.
- Respeite pedidos de saída. O sistema descadastra automaticamente quem responder PARAR, SAIR, STOP, CANCELAR ou DESCADASTRAR.
- Acompanhe a **qualidade do número** no WhatsApp Manager. Muitos bloqueios ou denúncias reduzem o limite e podem suspender o envio.

## Estrutura

```
painel/
├── index.php            Painel
├── contatos.php         Contatos, importação e exportação CSV
├── templates.php        Sincronização de templates
├── campanhas.php        Lista e criação de campanhas
├── campanha.php         Detalhe, teste, iniciar/pausar/cancelar, relatório
├── conversas.php        Respostas recebidas e atendimento na janela de 24h
├── configuracoes.php    API, webhook, cron e regras (admin)
├── usuarios.php         Usuários (admin)
├── logs.php             Logs (admin)
├── webhook.php          Recebe eventos da Meta
├── cron/enviar.php      Processa a fila (Cron Job)
├── install.php          Instalador (excluir depois de usar)
└── includes/            Núcleo, protegido contra acesso pelo navegador
```

## Problemas comuns

| Sintoma | Solução |
|---|---|
| Erro 190 / token inválido | O token expirou. Gere um token permanente de Usuário do Sistema. |
| Erro 131030 | Você está usando o número de teste e o destino não está na lista de destinatários permitidos do app. |
| Erro 132001 | O template não existe com esse idioma. Sincronize os templates. |
| Erro 131049 | A Meta limitou mensagens de marketing para esse contato. Isso é normal e não indica erro do sistema. |
| Erro 131047 | Fora da janela de 24h. Use um template, não texto livre. |
| Erro 131042 | Problema de pagamento. Cadastre uma forma de pagamento no WhatsApp Manager. |
| Webhook não verifica | Confira o HTTPS e o token de verificação, e veja se a URL abre no navegador (deve mostrar "Token de verificação inválido"). |
| Campanha parada em "Enviando" | O Cron Job não está rodando. Confira em Configurações → 3. |
