-- =============================================================================
-- migrations/001_create_audit_log.sql
--
-- TSK-48: Audit Log Schema
-- Records WHO did WHAT to WHICH record and WHEN, for:
--   - payroll period approval / unapproval
--   - account create / update / delete
--
-- Run this once against the existing database, e.g.:
--   mysql -u <user> -p <database> < migrations/001_create_audit_log.sql
-- =============================================================================

CREATE TABLE IF NOT EXISTS audit_log (
    audit_id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    -- Who performed the action. Kept nullable + ON DELETE SET NULL so the log
    -- entry survives even if the acting account is later deleted; username
    -- is snapshotted separately so the log stays readable either way.
    account_id         INT UNSIGNED NULL,
    username_snapshot  VARCHAR(50)  NOT NULL,

    -- What happened. action fixed to a small known set; target_type/target_id
    -- point at the row that was affected.
    action             ENUM(
                            'account_create',
                            'account_update',
                            'account_delete',
                            'payroll_approve',
                            'payroll_unapprove'
                        ) NOT NULL,
    target_type        VARCHAR(30)  NOT NULL,   -- e.g. 'account', 'payroll_period'
    target_id          INT UNSIGNED NULL,       -- id of the affected row (NULL if deleted)

    -- Free-form JSON snapshot of what changed (old/new values, affected
    -- fields, etc). Kept as TEXT for portability across MySQL versions.
    details            TEXT NULL,

    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_audit_log_account
        FOREIGN KEY (account_id) REFERENCES accounts(account_id)
        ON DELETE SET NULL,

    INDEX idx_audit_log_action     (action),
    INDEX idx_audit_log_target     (target_type, target_id),
    INDEX idx_audit_log_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
