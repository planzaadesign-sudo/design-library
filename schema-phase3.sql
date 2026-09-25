-- Phase 3: customer modification configurator.
-- Run once, after schema.sql and schema-phase2.sql.

-- Menu of modifications a customer can order on top of a library design.
-- Tiers 1-3 have a fixed price; struct_portion is the part of that price that
-- covers structural design, deducted when the customer picks "architectural only".
-- Tier 4 items are only ever estimated (price_min..price_max) and always go to manual review.
CREATE TABLE IF NOT EXISTS modifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tier TINYINT NOT NULL,
  label VARCHAR(200) NOT NULL,
  price INT NULL,
  price_min INT NULL,
  price_max INT NULL,
  struct_portion INT DEFAULT 0,
  added_days INT DEFAULT 0,
  is_active TINYINT(1) DEFAULT 1
);

INSERT INTO modifications (tier, label, price, struct_portion, added_days) VALUES
(1, 'Change the exterior elevation style', 1500, 0, 0),
(1, 'Change the exterior finish and colour scheme', 800, 0, 0),
(2, 'Resize a room within the existing footprint', 3000, 800, 1),
(2, 'Move a non-load-bearing partition', 2500, 700, 1),
(2, 'Change a room''s intended use', 500, 0, 0),
(2, 'Mirror the design for a different facing direction', 1200, 300, 1),
(2, 'Fit to your exact plot size (up to 5% variance)', 2000, 500, 1),
(3, 'Add an attached washroom', 8000, 3500, 3),
(3, 'Add a door or window in a load-bearing wall', 6000, 3000, 2),
(3, 'Add a room, extending the footprint', 15000, 7000, 4),
(3, 'Fit to your exact plot size (5-15% variance)', 6000, 2500, 3);

INSERT INTO modifications (tier, label, price_min, price_max, added_days) VALUES
(4, 'Add an additional floor', 80000, 140000, 10),
(4, 'Change the plot size by more than 15%', 40000, 70000, 8);

-- Orders now record what was customised. modifications holds a JSON array of
-- modification ids. For orders needing manual review, total_price stores the
-- low end of the estimate until the team confirms the final price.
ALTER TABLE library_orders ADD COLUMN modifications TEXT NULL;
ALTER TABLE library_orders ADD COLUMN structural_included TINYINT(1) DEFAULT 0;
ALTER TABLE library_orders ADD COLUMN needs_manual_review TINYINT(1) DEFAULT 0;
ALTER TABLE library_orders ADD COLUMN estimated_delivery_days INT NULL;
