-- Chat Phase 3: routing and classification foundation
-- Additive migration for the Phase 2 crm_conversations table.
--
-- Production was verified before applying these statements:
--   - room, room_source, intent_category, payment_detected_at did not exist.
--   - the Phase 3 indexes did not exist.
--
-- Run once against a Phase 2 database.

ALTER TABLE crm_conversations
    ADD COLUMN room VARCHAR(30) NOT NULL DEFAULT 'lainnya',
    ADD COLUMN room_source VARCHAR(20) NOT NULL DEFAULT 'auto',
    ADD COLUMN intent_category VARCHAR(100) NULL,
    ADD COLUMN payment_detected_at DATETIME NULL;

ALTER TABLE crm_conversations
    ADD INDEX idx_crm_conversations_room_inbound (room, last_inbound_at),
    ADD INDEX idx_crm_conversations_source_room_inbound (room_source, room, last_inbound_at);
