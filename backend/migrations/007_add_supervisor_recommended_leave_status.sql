-- Add 'Supervisor Recommended' status to leave_status table
-- This enables two-tier leave approval: Supervisor recommends → HR gives final approval
-- Existing: 1=Pending, 2=Approved, 3=Rejected, 4=Cancelled
INSERT INTO leave_status (leave_status_id, status_name) VALUES (5, 'Supervisor Recommended');
