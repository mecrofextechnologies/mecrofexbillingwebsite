-- Daraja Paybill + automatic creator withdrawals (B2C).
--
-- The application also applies all of this itself on first use
-- (kc_wd_ensure_schema() in msanii/includes/withdrawal_payout.php and
-- kc_payment_settings_ensure_table() in msanii/includes/daraja.php), so
-- running this file by hand is optional. Run it during a quiet period if
-- you prefer the ALTERs not to happen on a live request.
--
-- Safe to re-run: "Duplicate column name" / "Duplicate key name" errors on
-- a second run are harmless.

-- 1. Encrypted payment-gateway settings (Admin01 → Settings → Daraja API Credentials).
--    Secret values are AES-256-GCM ciphertext (kc_encrypt); the key lives
--    outside the webroot in keychele_secrets/encryption.key.
CREATE TABLE IF NOT EXISTS payment_gateway_settings (
    setting_key   VARCHAR(64)  NOT NULL PRIMARY KEY,
    setting_value TEXT         NULL,
    is_secret     TINYINT(1)   NOT NULL DEFAULT 0,
    updated_by    INT          NULL,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. withdrawal_requests: status must hold 'successful' / 'failed' as well
--    as the legacy 'pending' / 'processing' / 'completed' / 'rejected'.
ALTER TABLE withdrawal_requests MODIFY `status` VARCHAR(20) NOT NULL DEFAULT 'pending';

-- 3. withdrawal_requests: automatic-payout tracking.
--    payout_method 'manual' = legacy admin-approved rows (unchanged behaviour),
--    'b2c' = automatic Daraja payout. Existing rows default to 'manual'.
ALTER TABLE withdrawal_requests
    ADD COLUMN `payout_method`        VARCHAR(20)  NOT NULL DEFAULT 'manual',
    ADD COLUMN `reference`            VARCHAR(40)  NULL COMMENT 'Internal ref, sent to Daraja as OriginatorConversationID',
    ADD COLUMN `idempotency_key`      VARCHAR(64)  NULL COMMENT 'One per confirmed withdrawal form; blocks duplicate submits',
    ADD COLUMN `creator_username`     VARCHAR(100) NULL,
    ADD COLUMN `conversation_id`      VARCHAR(100) NULL COMMENT 'Daraja ConversationID',
    ADD COLUMN `provider_result_code` VARCHAR(20)  NULL,
    ADD COLUMN `provider_response`    TEXT         NULL COMMENT 'Summary of Daraja response (no PII)',
    ADD COLUMN `failure_reason`       VARCHAR(255) NULL,
    ADD COLUMN `balance_state`        VARCHAR(20)  NULL COMMENT 'reserved | finalized | released',
    ADD COLUMN `completed_at`         DATETIME     NULL,
    ADD COLUMN `sms_status`           VARCHAR(20)  NULL COMMENT 'sending | sent | failed',
    ADD COLUMN `sms_error`            VARCHAR(255) NULL,
    ADD COLUMN `sms_sent_at`          DATETIME     NULL;

-- The M-Pesa receipt of an automatic payout is stored in the existing
-- transaction_code column, so current history/admin views show it unchanged.

ALTER TABLE withdrawal_requests
    ADD UNIQUE KEY uq_wd_reference (reference),
    ADD UNIQUE KEY uq_wd_idem (idempotency_key),
    ADD INDEX idx_wd_conv (conversation_id),
    ADD INDEX idx_wd_creator_status (creator_id, status);
