-- Chat Phase 2: conversation/message layer
-- Run manually after deploying this PR.
-- No legacy table is altered or truncated.

CREATE TABLE IF NOT EXISTS crm_conversations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nowa VARCHAR(50) NOT NULL,
    nama VARCHAR(150) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'open',
    last_message_at DATETIME NULL,
    last_inbound_at DATETIME NULL,
    last_outbound_at DATETIME NULL,
    last_read_at DATETIME NULL,
    unread_count INT UNSIGNED NOT NULL DEFAULT 0,
    followup_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_crm_conversations_nowa (nowa),
    INDEX idx_crm_conversations_last_message (last_message_at),
    INDEX idx_crm_conversations_unread (unread_count, last_inbound_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversation_id BIGINT UNSIGNED NOT NULL,
    nowa VARCHAR(50) NOT NULL,
    direction VARCHAR(10) NOT NULL,
    sender_type VARCHAR(20) NOT NULL,
    message TEXT NOT NULL,
    source VARCHAR(30) NOT NULL DEFAULT 'crm',
    template_id INT NULL,
    template_name VARCHAR(150) NULL,
    external_id VARCHAR(191) NULL,
    sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_crm_messages_conversation_time (conversation_id, sent_at, id),
    INDEX idx_crm_messages_nowa_time (nowa, sent_at, id),
    INDEX idx_crm_messages_direction_time (direction, sent_at),
    UNIQUE KEY uq_crm_messages_external_id (external_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_followups (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversation_id BIGINT UNSIGNED NOT NULL,
    message_id BIGINT UNSIGNED NULL,
    template_id INT NULL,
    template_name VARCHAR(150) NULL,
    sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status VARCHAR(20) NOT NULL DEFAULT 'sent',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_crm_followups_conversation_time (conversation_id, sent_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
