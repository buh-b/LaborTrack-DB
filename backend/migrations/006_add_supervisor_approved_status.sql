-- Add 'Supervisor Approved' status to validation_status table
-- This enables two-tier approval: Supervisor validates → HR gives final approval
INSERT INTO validation_status (validation_status_id, status_name) VALUES (4, 'Supervisor Approved');
