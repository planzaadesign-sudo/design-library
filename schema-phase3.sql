-- Phase 3: customer modification configurator.
-- FRESH DATABASE: run this whole file once, after schema.sql and schema-phase2.sql.
-- DATABASE THAT ALREADY RAN PHASE 3: do NOT import the whole file again (the INSERTs
-- would add duplicate rows and the ALTERs would fail). Instead run, in order:
--   1. the "Simpler customer wording" UPDATE block (safe to repeat), then
--   2. the "Phase 3b" section at the bottom, once.

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

-- ===========================================================================
-- Phase 3b: room-by-room changes.
-- FRESH DATABASE: runs as part of this file.
-- DATABASE THAT ALREADY RAN PHASE 3: run everything from this line down, once.
-- ===========================================================================

-- Which room picker (if any) a change opens in the configurator, and how
-- api/order.php prices it. Room-based kinds are charged once per room picked.
--   resize, partition, washroom, opening, relabel -> pick rooms (priced per room)
--   colour -> pick a colour scheme;  other -> free text, always manual review
--   NULL   -> applies to the whole house
ALTER TABLE modifications ADD COLUMN detail_type VARCHAR(20) NULL;

UPDATE modifications SET detail_type = 'resize'    WHERE label IN ('Make a room bigger or smaller (house size stays the same)', 'Resize a room within the existing footprint');
UPDATE modifications SET detail_type = 'partition' WHERE label IN ('Move an inside wall (one that does not hold up the house)', 'Move a non-load-bearing partition');
UPDATE modifications SET detail_type = 'washroom'  WHERE label IN ('Add a bathroom attached to a bedroom', 'Add an attached washroom');
UPDATE modifications SET detail_type = 'opening'   WHERE label IN ('Add a door or window in a main wall (a wall that holds up the house)', 'Add a door or window in a load-bearing wall');
UPDATE modifications SET detail_type = 'relabel'   WHERE label IN ('Use a room for something else (for example, a study instead of a bedroom)', 'Change a room''s intended use');

INSERT INTO modifications (tier, label, price, struct_portion, added_days, detail_type) VALUES
(1, 'Pick your own colours for the outside of the house', 500, 0, 0, 'colour'),
(2, 'Any other changes you want', 0, 0, 0, 'other');

CREATE TABLE IF NOT EXISTS design_rooms (
  id INT AUTO_INCREMENT PRIMARY KEY,
  design_id INT NOT NULL,
  room_name VARCHAR(100) NOT NULL,
  room_type ENUM('bedroom','bathroom','kitchen','living','dining','pooja','balcony','parking','staircase','store','utility','other') NOT NULL,
  current_size_sqft INT NULL,
  floor VARCHAR(20) DEFAULT 'Ground',
  FOREIGN KEY (design_id) REFERENCES designs(id)
);

-- Seed rooms for the 6 sample designs (ids 1-6 from schema.sql).
-- Design 1: The Courtyard (30x40, G+1, 3BHK)
INSERT INTO design_rooms (design_id, room_name, room_type, current_size_sqft, floor) VALUES
(1, 'Master Bedroom', 'bedroom', 180, 'First Floor'),
(1, 'Bedroom 2', 'bedroom', 140, 'First Floor'),
(1, 'Bedroom 3', 'bedroom', 120, 'Ground Floor'),
(1, 'Kitchen', 'kitchen', 100, 'Ground Floor'),
(1, 'Living Room', 'living', 200, 'Ground Floor'),
(1, 'Dining Area', 'dining', 90, 'Ground Floor'),
(1, 'Bathroom 1', 'bathroom', 45, 'Ground Floor'),
(1, 'Bathroom 2', 'bathroom', 40, 'First Floor'),
(1, 'Pooja Room', 'pooja', 25, 'Ground Floor'),
(1, 'Balcony', 'balcony', 60, 'First Floor'),
(1, 'Parking', 'parking', 150, 'Ground Floor'),
(1, 'Staircase', 'staircase', 50, 'Ground Floor');

-- Design 2: The Terrace View (30x50, G+1, 3BHK)
INSERT INTO design_rooms (design_id, room_name, room_type, current_size_sqft, floor) VALUES
(2, 'Master Bedroom', 'bedroom', 200, 'First Floor'),
(2, 'Bedroom 2', 'bedroom', 150, 'First Floor'),
(2, 'Bedroom 3', 'bedroom', 130, 'Ground Floor'),
(2, 'Kitchen', 'kitchen', 120, 'Ground Floor'),
(2, 'Living Room', 'living', 240, 'Ground Floor'),
(2, 'Dining Area', 'dining', 100, 'Ground Floor'),
(2, 'Bathroom 1', 'bathroom', 50, 'Ground Floor'),
(2, 'Bathroom 2', 'bathroom', 45, 'First Floor'),
(2, 'Bathroom 3', 'bathroom', 35, 'First Floor'),
(2, 'Balcony', 'balcony', 80, 'First Floor'),
(2, 'Terrace', 'other', 120, 'First Floor'),
(2, 'Parking', 'parking', 160, 'Ground Floor');

-- Design 3: The Corner Bloom (20x30, G, 2BHK)
INSERT INTO design_rooms (design_id, room_name, room_type, current_size_sqft, floor) VALUES
(3, 'Master Bedroom', 'bedroom', 150, 'Ground Floor'),
(3, 'Bedroom 2', 'bedroom', 120, 'Ground Floor'),
(3, 'Kitchen', 'kitchen', 80, 'Ground Floor'),
(3, 'Living Room', 'living', 160, 'Ground Floor'),
(3, 'Bathroom', 'bathroom', 40, 'Ground Floor'),
(3, 'Parking', 'parking', 100, 'Ground Floor');

-- Design 4: The Skyline (40x60, G+2, 4BHK)
INSERT INTO design_rooms (design_id, room_name, room_type, current_size_sqft, floor) VALUES
(4, 'Master Bedroom', 'bedroom', 250, 'Second Floor'),
(4, 'Bedroom 2', 'bedroom', 180, 'Second Floor'),
(4, 'Bedroom 3', 'bedroom', 160, 'First Floor'),
(4, 'Bedroom 4', 'bedroom', 140, 'First Floor'),
(4, 'Kitchen', 'kitchen', 140, 'Ground Floor'),
(4, 'Living Room', 'living', 300, 'Ground Floor'),
(4, 'Dining Area', 'dining', 120, 'Ground Floor'),
(4, 'Bathroom 1', 'bathroom', 55, 'Ground Floor'),
(4, 'Bathroom 2', 'bathroom', 50, 'First Floor'),
(4, 'Bathroom 3', 'bathroom', 50, 'Second Floor'),
(4, 'Bathroom 4', 'bathroom', 40, 'Second Floor'),
(4, 'Pooja Room', 'pooja', 30, 'Ground Floor'),
(4, 'Balcony 1', 'balcony', 70, 'First Floor'),
(4, 'Balcony 2', 'balcony', 60, 'Second Floor'),
(4, 'Parking', 'parking', 200, 'Ground Floor'),
(4, 'Terrace', 'other', 200, 'Second Floor');

-- Design 5: The Garden House (30x40, G, 2BHK)
INSERT INTO design_rooms (design_id, room_name, room_type, current_size_sqft, floor) VALUES
(5, 'Master Bedroom', 'bedroom', 170, 'Ground Floor'),
(5, 'Bedroom 2', 'bedroom', 130, 'Ground Floor'),
(5, 'Kitchen', 'kitchen', 90, 'Ground Floor'),
(5, 'Living Room', 'living', 190, 'Ground Floor'),
(5, 'Dining Area', 'dining', 80, 'Ground Floor'),
(5, 'Bathroom', 'bathroom', 45, 'Ground Floor'),
(5, 'Garden Area', 'other', 150, 'Ground Floor'),
(5, 'Parking', 'parking', 130, 'Ground Floor');

-- Design 6: The Urban Duplex (30x45, G+1, 3BHK)
INSERT INTO design_rooms (design_id, room_name, room_type, current_size_sqft, floor) VALUES
(6, 'Master Bedroom', 'bedroom', 190, 'First Floor'),
(6, 'Bedroom 2', 'bedroom', 150, 'First Floor'),
(6, 'Bedroom 3', 'bedroom', 130, 'Ground Floor'),
(6, 'Kitchen', 'kitchen', 110, 'Ground Floor'),
(6, 'Living Room', 'living', 220, 'Ground Floor'),
(6, 'Dining Area', 'dining', 95, 'Ground Floor'),
(6, 'Bathroom 1', 'bathroom', 45, 'Ground Floor'),
(6, 'Bathroom 2', 'bathroom', 42, 'First Floor'),
(6, 'Balcony', 'balcony', 65, 'First Floor'),
(6, 'Parking', 'parking', 140, 'Ground Floor');

-- What the customer picked per room (or the colour scheme / free-text request).
-- Stored in addition to library_orders.modifications, which keeps the list of ids.
CREATE TABLE IF NOT EXISTS order_modification_details (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  modification_id INT NOT NULL,
  room_id INT NULL,
  action ENUM('increase','decrease','add','remove','other') NULL,
  custom_note TEXT NULL,
  FOREIGN KEY (order_id) REFERENCES library_orders(id),
  FOREIGN KEY (modification_id) REFERENCES modifications(id),
  FOREIGN KEY (room_id) REFERENCES design_rooms(id)
);
