-- =====================================================================
-- Phase 10b: call queue for call-back orders. Run once in phpMyAdmin (after schema-phase10.sql).
-- New call-back orders start as 'pending' and the assigned team member gets an email.
-- call_notes is a JSON list: [{staff_id, staff_name, note, timestamp, outcome}] -- notes are added, never replaced.
-- =====================================================================

ALTER TABLE library_orders ADD COLUMN call_status ENUM('pending','contacted','in_progress','completed') DEFAULT NULL;
ALTER TABLE library_orders ADD COLUMN called_at TIMESTAMP NULL;
ALTER TABLE library_orders ADD COLUMN called_by INT NULL;
ALTER TABLE library_orders ADD COLUMN call_notes TEXT NULL;
ALTER TABLE library_orders ADD FOREIGN KEY (called_by) REFERENCES staff(id);
ALTER TABLE library_orders ADD INDEX idx_call_status (call_status, assigned_to);

-- Call-back orders that already exist:
--   still waiting (no quotation yet, not closed) -> pending, so they show up in the call queue
--   a quotation exists                           -> contacted
--   closed                                       -> completed
UPDATE library_orders SET call_status = 'pending'
  WHERE contact_preference = 'call' AND quotation_id IS NULL AND status <> 'delivered'
    AND NOT EXISTS (SELECT 1 FROM quotations q WHERE q.order_id = library_orders.id AND q.status <> 'cancelled');
UPDATE library_orders SET call_status = 'contacted'
  WHERE contact_preference = 'call' AND call_status IS NULL AND status <> 'delivered';
UPDATE library_orders SET call_status = 'completed'
  WHERE contact_preference = 'call' AND call_status IS NULL;
