-- Chat Phase 3: routing and classification foundation
-- Additive only. Safe to run more than once on the Phase 2 schema.
-- No legacy table is altered or truncated.

SET @db := DATABASE();

SET @sql := (
    SELECT IF(
        COUNT(*) = 0,
        "ALTER TABLE crm_conversations
            ADD COLUMN room VARCHAR(30) NOT NULL DEFAULT 'lainnya',
            ADD COLUMN room_source VARCHAR(20) NOT NULL DEFAULT 'auto',
            ADD COLUMN intent_category VARCHAR(100) NULL,
            ADD COLUMN payment_detected_at DATETIME NULL",
        "SELECT 1"
    )
    FROM information_schema.columns
    WHERE table_schema = @db
      AND table_name = 'crm_conversations'
      AND column_name IN ('room', 'room_source', 'intent_category', 'payment_detected_at')
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        COUNT(*) = 0,
        "ALTER TABLE crm_conversations
            ADD INDEX idx_crm_conversations_room_inbound (room, last_inbound_at),
            ADD INDEX idx_crm_conversations_source_room_inbound (room_source, room, last_inbound_at)",
        "SELECT 1"
    )
    FROM information_schema.statistics
    WHERE table_schema = @db
      AND table_name = 'crm_conversations'
      AND index_name IN ('idx_crm_conversations_room_inbound', 'idx_crm_conversations_source_room_inbound')
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
