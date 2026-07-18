-- =============================================================================
-- migrations/002_expand_audit_log_actions.sql
--
-- Leave Management: Leave Application, Leave Approval, Leave Balance,
-- Holiday Management, Leave Types
--
-- The original audit_log.action ENUM only allowed:
--   account_create, account_update, account_delete,
--   payroll_approve, payroll_unapprove
--
-- routes/leave_records.php already calls logAudit() with action
-- 'leave_approval', which is NOT in that list — meaning every approval/
-- rejection audit entry has been silently failing (logAudit() swallows
-- errors) or, on non-strict MySQL modes, being written as an empty string.
--
-- This migration widens the ENUM to cover the existing 'leave_approval'
-- action plus every new leave/holiday/leave-type action added alongside
-- the Leave Types management screen.
--
-- Run this once against the existing database, e.g.:
--   mysql -u <user> -p <database> < migrations/002_expand_audit_log_actions.sql
-- =============================================================================

ALTER TABLE audit_log
    MODIFY COLUMN action ENUM(
        'account_create',
        'account_update',
        'account_delete',
        'payroll_approve',
        'payroll_unapprove',
        'leave_approval',
        'leave_file',
        'leave_edit',
        'leave_cancel',
        'leave_delete',
        'leave_balance_create',
        'leave_balance_update',
        'leave_balance_delete',
        'holiday_create',
        'holiday_update',
        'holiday_delete',
        'leave_type_create',
        'leave_type_update',
        'leave_type_delete'
    ) NOT NULL;
