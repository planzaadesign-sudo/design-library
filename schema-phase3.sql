-- Phase 3: customer modification configurator.
-- FRESH DATABASE: run this whole file once, after schema.sql and schema-phase2.sql.
-- DATABASE THAT ALREADY RAN PHASE 3: do NOT import the whole file again (the INSERTs
-- would add duplicate rows and the ALTERs would fail). Run only the UPDATE block
-- at the bottom, which renames the old labels to the simpler customer wording.

-- Menu of modifications a customer can order on top of a library design.
-- Tiers 1-3 have a fixed price; struct_portion is the part of that price that
-- covers structural design, deducted when the customer picks "design only".
-- Tier 4 items are only ever estimated (price_min..price_max) and always go to manual review.
-- Labels are shown to customers as-is, so keep them in plain, simple English.
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
(1, 'Change the front look of the house (outside style)', 1500, 0, 0),
(1, 'Change the outside colours and wall finish', 800, 0, 0),
(2, 'Make a room bigger or smaller (house size stays the same)', 3000, 800, 1),
(2, 'Move an inside wall (one that does not hold up the house)', 2500, 700, 1),
(2, 'Use a room for something else (for example, a study instead of a bedroom)', 500, 0, 0),
(2, 'Flip the design for a plot that faces a different direction', 1200, 300, 1),
(2, 'My plot is slightly different in size (small change)', 2000, 500, 1),
(3, 'Add a bathroom attached to a bedroom', 8000, 3500, 3),
(3, 'Add a door or window in a main wall (a wall that holds up the house)', 6000, 3000, 2),
(3, 'Add a new room (the house gets bigger)', 15000, 7000, 4),
(3, 'My plot is quite different in size (medium change)', 6000, 2500, 3);

INSERT INTO modifications (tier, label, price_min, price_max, added_days) VALUES
(4, 'Add one more floor on top', 80000, 140000, 10),
(4, 'My plot is very different in size (big change)', 40000, 70000, 8);

-- Orders now record what was customised. modifications holds a JSON array of
-- modification ids. For orders needing manual review, total_price stores the
-- low end of the estimate until the team confirms the final price.
ALTER TABLE library_orders ADD COLUMN modifications TEXT NULL;
ALTER TABLE library_orders ADD COLUMN structural_included TINYINT(1) DEFAULT 0;
ALTER TABLE library_orders ADD COLUMN needs_manual_review TINYINT(1) DEFAULT 0;
ALTER TABLE library_orders ADD COLUMN estimated_delivery_days INT NULL;

-- ---------------------------------------------------------------------------
-- Simpler customer wording for databases created with the original labels.
-- Safe to run more than once: each UPDATE only matches the old wording.
-- ---------------------------------------------------------------------------
UPDATE modifications SET label = 'Change the front look of the house (outside style)' WHERE label = 'Change the exterior elevation style';
UPDATE modifications SET label = 'Change the outside colours and wall finish' WHERE label = 'Change the exterior finish and colour scheme';
UPDATE modifications SET label = 'Make a room bigger or smaller (house size stays the same)' WHERE label = 'Resize a room within the existing footprint';
UPDATE modifications SET label = 'Move an inside wall (one that does not hold up the house)' WHERE label = 'Move a non-load-bearing partition';
UPDATE modifications SET label = 'Use a room for something else (for example, a study instead of a bedroom)' WHERE label = 'Change a room''s intended use';
UPDATE modifications SET label = 'Flip the design for a plot that faces a different direction' WHERE label = 'Mirror the design for a different facing direction';
UPDATE modifications SET label = 'My plot is slightly different in size (small change)' WHERE label = 'Fit to your exact plot size (up to 5% variance)';
UPDATE modifications SET label = 'Add a bathroom attached to a bedroom' WHERE label = 'Add an attached washroom';
UPDATE modifications SET label = 'Add a door or window in a main wall (a wall that holds up the house)' WHERE label = 'Add a door or window in a load-bearing wall';
UPDATE modifications SET label = 'Add a new room (the house gets bigger)' WHERE label = 'Add a room, extending the footprint';
UPDATE modifications SET label = 'My plot is quite different in size (medium change)' WHERE label = 'Fit to your exact plot size (5-15% variance)';
UPDATE modifications SET label = 'Add one more floor on top' WHERE label = 'Add an additional floor';
UPDATE modifications SET label = 'My plot is very different in size (big change)' WHERE label = 'Change the plot size by more than 15%';
