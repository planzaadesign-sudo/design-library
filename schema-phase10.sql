-- =====================================================================
-- Phase 10: quotations for call-back orders. Run once in phpMyAdmin (after schema-phase9.sql).
-- The in-house team prepares a quotation after the call; the customer opens a private link
-- (quote.php) and confirms it; the admin then confirms the payment.
-- =====================================================================

CREATE TABLE IF NOT EXISTS quotations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  design_id INT NOT NULL,
  created_by INT NOT NULL,

  -- What the team configured on behalf of the customer
  structural_included TINYINT(1) DEFAULT 0,
  modifications JSON NULL,
  modification_details JSON NULL,
  -- Frozen, customer-ready price lines (label, rooms, amount) so the quote never changes after sending
  line_items JSON NULL,

  -- Pricing (calculated server-side)
  base_price INT NOT NULL,
  modification_total INT NOT NULL,
  structural_price INT DEFAULT 0,
  total_price INT NOT NULL,
  total_price_max INT NULL,
  estimated_delivery_days INT NOT NULL,
  needs_manual_pricing TINYINT(1) DEFAULT 0,

  -- Customer-facing notes from the team, and notes only the team sees
  notes_for_customer TEXT NULL,
  internal_notes TEXT NULL,
  -- Where the quotation link was emailed (customers give only name + phone when they ask for a call)
  customer_email VARCHAR(150) NULL,

  -- Status tracking
  status ENUM('draft', 'sent', 'viewed', 'confirmed', 'expired', 'cancelled') DEFAULT 'draft',
  -- token: a random lookup id (not secret). token_hash: password_hash() of the secret part of
  -- the link. The link itself (quote.php?token=<token>.<secret>) is never stored.
  token VARCHAR(64) UNIQUE NOT NULL,
  token_hash VARCHAR(255) NOT NULL,

  sent_at TIMESTAMP NULL,
  viewed_at TIMESTAMP NULL,
  confirmed_at TIMESTAMP NULL,
  expires_at TIMESTAMP NULL,

  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  INDEX idx_quote_order (order_id, status),
  FOREIGN KEY (order_id) REFERENCES library_orders(id),
  FOREIGN KEY (design_id) REFERENCES designs(id),
  FOREIGN KEY (created_by) REFERENCES staff(id)
);

-- Track which quotation was accepted for an order
ALTER TABLE library_orders ADD COLUMN quotation_id INT NULL;
ALTER TABLE library_orders ADD COLUMN payment_status ENUM('pending', 'confirmed', 'received') DEFAULT 'pending';
ALTER TABLE library_orders ADD COLUMN payment_confirmed_at TIMESTAMP NULL;
ALTER TABLE library_orders ADD COLUMN payment_confirmed_by INT NULL;
ALTER TABLE library_orders ADD FOREIGN KEY (quotation_id) REFERENCES quotations(id);
ALTER TABLE library_orders ADD FOREIGN KEY (payment_confirmed_by) REFERENCES staff(id);
