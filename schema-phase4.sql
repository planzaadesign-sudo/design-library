-- Phase 4: admin dashboard.
-- Run once, after schema-phase3.sql (all of its sections).

-- When an order was last assigned to a team member (shown in the order timeline).
ALTER TABLE library_orders ADD COLUMN assigned_at TIMESTAMP NULL DEFAULT NULL;
