-- ============================================
-- 005_fix_audit_log_enum.sql
--
-- Fix #12: Add missing ENUM values to audit_log.action
-- These actions are already called by logAudit() but silently fail
-- because the ENUM doesn't include them.
-- ============================================

ALTER TABLE audit_log MODIFY COLUMN action ENUM(
  'account_create','account_update','account_delete',
  'payroll_approve','payroll_unapprove',
  'leave_approval','leave_file','leave_edit','leave_cancel','leave_delete',
  'leave_balance_create','leave_balance_update','leave_balance_delete',
  'holiday_create','holiday_update','holiday_delete',
  'leave_type_create','leave_type_update','leave_type_delete',
  'login','password_reset',
  'claim_validation','report_validation',
  'employee_create','employee_update','schedule_assignment'
) NOT NULL;
