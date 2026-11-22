-- Disparador WhatsApp - estrutura do banco (MySQL 5.7+ / MariaDB 10.3+)

CREATE TABLE IF NOT EXISTS usuarios (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(100) NOT NULL,
  usuario VARCHAR(60) NOT NULL UNIQUE,
  senha_hash VARCHAR(255) NOT NULL,
  perfil VARCHAR(20) NOT NULL DEFAULT 'operador',
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  trocar_senha TINYINT(1) NOT NULL DEFAULT 0,
  ultimo_login DATETIME NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_tentativas (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip VARCHAR(45) NOT NULL,
  usuario VARCHAR(60) NOT NULL,
  criado_em DATETIME NOT NULL,
  INDEX idx_ip (ip),
  INDEX idx_usuario (usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS configuracoes (
  chave VARCHAR(64) NOT NULL PRIMARY KEY,
  valor TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contatos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(150) NOT NULL DEFAULT '',
  telefone VARCHAR(20) NOT NULL UNIQUE,
  tags VARCHAR(255) NOT NULL DEFAULT '',
  campo1 VARCHAR(255) NULL,
  campo2 VARCHAR(255) NULL,
  campo3 VARCHAR(255) NULL,
  optin TINYINT(1) NOT NULL DEFAULT 0,
  optin_origem VARCHAR(150) NULL,
  optin_em DATETIME NULL,
  optout TINYINT(1) NOT NULL DEFAULT 0,
  optout_em DATETIME NULL,
  ultima_interacao DATETIME NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_optin (optin, optout)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS templates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  meta_id VARCHAR(64) NULL,
  nome VARCHAR(191) NOT NULL,
  idioma VARCHAR(15) NOT NULL,
  categoria VARCHAR(30) NULL,
  status VARCHAR(30) NULL,
  parametro_formato VARCHAR(20) NULL,
  componentes LONGTEXT NULL,
  atualizado_em DATETIME NULL,
  UNIQUE KEY uk_nome_idioma (nome, idioma)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS campanhas (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(150) NOT NULL,
  template_id INT UNSIGNED NULL,
  template_nome VARCHAR(191) NOT NULL,
  template_idioma VARCHAR(15) NOT NULL,
  config LONGTEXT NOT NULL,
  filtro_tag VARCHAR(100) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'rascunho',
  agendar_para DATETIME NULL,
  total INT UNSIGNED NOT NULL DEFAULT 0,
  criado_por INT UNSIGNED NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  iniciado_em DATETIME NULL,
  concluido_em DATETIME NULL,
  INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS campanha_mensagens (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  campanha_id INT UNSIGNED NOT NULL,
  contato_id INT UNSIGNED NOT NULL,
  telefone VARCHAR(20) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pendente',
  wamid VARCHAR(128) NULL,
  erro_codigo VARCHAR(20) NULL,
  erro VARCHAR(500) NULL,
  tentativas TINYINT UNSIGNED NOT NULL DEFAULT 0,
  enviado_em DATETIME NULL,
  atualizado_em DATETIME NULL,
  UNIQUE KEY uk_campanha_contato (campanha_id, contato_id),
  INDEX idx_fila (campanha_id, status),
  INDEX idx_wamid (wamid),
  INDEX idx_enviado (enviado_em),
  INDEX idx_contato (contato_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS conversas (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  contato_id INT UNSIGNED NULL,
  telefone VARCHAR(20) NOT NULL,
  direcao VARCHAR(10) NOT NULL,
  nome_perfil VARCHAR(150) NULL,
  tipo VARCHAR(30) NOT NULL DEFAULT 'text',
  texto TEXT NULL,
  wamid VARCHAR(128) NULL,
  status VARCHAR(20) NULL,
  lida TINYINT(1) NOT NULL DEFAULT 0,
  usuario_id INT UNSIGNED NULL,
  criado_em DATETIME NOT NULL,
  UNIQUE KEY uk_wamid (wamid),
  INDEX idx_tel (telefone, id),
  INDEX idx_lida (direcao, lida)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nivel VARCHAR(10) NOT NULL,
  mensagem VARCHAR(500) NOT NULL,
  contexto TEXT NULL,
  criado_em DATETIME NOT NULL,
  INDEX idx_criado (criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
