-- ============================================
-- 004_clock_resume_and_claim_corrections.sql
-- 
-- Feature 1: Clock-in resume support
--   - resumed_at: when the employee re-clocked-in after a clock-out
--   - accumulated_hours: hours from previous sessions (before resume)
--
-- Feature 2: Enhanced claims with time correction / missing hours
--   - requested_clock_in: corrected clock-in time
--   - requested_clock_out: corrected clock-out time
--   - requested_hours: missing hours to add
--
-- Both changes are backward-compatible. Run on your MySQL database.
-- ============================================

-- Feature 1: Clock-in resume support on time_logs
ALTER TABLE time_logs
  ADD COLUMN resumed_at DATETIME NULL DEFAULT NULL AFTER clock_out,
  ADD COLUMN accumulated_hours DECIMAL(6,2) NOT NULL DEFAULT 0.00 AFTER total_hours;

-- Feature 2: Missing time / time correction fields on claims
ALTER TABLE time_log_claims
  ADD COLUMN requested_clock_in DATETIME NULL DEFAULT NULL AFTER remarks,
  ADD COLUMN requested_clock_out DATETIME NULL DEFAULT NULL AFTER requested_clock_in,
  ADD COLUMN requested_hours DECIMAL(6,2) NULL DEFAULT NULL AFTER requested_clock_out;
