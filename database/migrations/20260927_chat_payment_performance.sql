-- Chat performance: payment detection lookup
-- The Chat page checks legacy log_wa for payment-state detection.
-- Index nowa so those correlated lookups can seek by contact number
-- instead of scanning the whole legacy log for every conversation.
--
-- Run once after deploying this PR.

ALTER TABLE log_wa
    ADD INDEX IF NOT EXISTS idx_log_wa_nowa (nowa);
