-- Phase 7: design codes, full audit trail, smart auto-assignment, standard design files,
-- internal notes and files on orders.
-- Run once, after schema-phase6.sql.

-- ---- 1. Design codes: PZ-{FACING}-{FLOORS}-{BHK}B-{SEQ}, e.g. PZ-E-G1-3B-0001 -----------
ALTER TABLE designs ADD COLUMN design_code VARCHAR(30) NULL UNIQUE;

-- Next sequence number per category (prefix). Codes are handed out with one atomic
-- "increment and read" on this table, so two designs published at the same moment can
-- never get the same number (includes/design_utils.php, generateDesignCode()).
CREATE TABLE IF NOT EXISTS design_code_counters (
  prefix VARCHAR(20) PRIMARY KEY,
  last_seq INT NOT NULL
);

-- Codes for the designs that already exist, numbered by id within each category.
UPDATE designs d
JOIN (
  SELECT id, prefix, ROW_NUMBER() OVER (PARTITION BY prefix ORDER BY id) AS seq
  FROM (
    SELECT id, CONCAT('PZ-',
      CASE facing WHEN 'East' THEN 'E' WHEN 'West' THEN 'W' WHEN 'North' THEN 'N' WHEN 'South' THEN 'S' ELSE 'X' END, '-',
      CASE floors WHEN 'G' THEN 'G' WHEN 'G+1' THEN 'G1' WHEN 'G+2' THEN 'G2' WHEN 'G+3' THEN 'G3' ELSE 'G' END, '-',
      LEAST(GREATEST(bhk, 1), 5), 'B') AS prefix
    FROM designs
  ) p
) c ON c.id = d.id
SET d.design_code = CONCAT(c.prefix, '-', LPAD(c.seq, 4, '0'))
WHERE d.design_code IS NULL;

INSERT INTO design_code_counters (prefix, last_seq)
SELECT LEFT(design_code, CHAR_LENGTH(design_code) - 5), MAX(CAST(RIGHT(design_code, 4) AS UNSIGNED))
FROM designs WHERE design_code IS NOT NULL
GROUP BY LEFT(design_code, CHAR_LENGTH(design_code) - 5)
ON DUPLICATE KEY UPDATE last_seq = GREATEST(last_seq, VALUES(last_seq));

-- ---- 2. Audit trail: who did what, and when ------------------------------------------------
ALTER TABLE designs ADD COLUMN brief_id INT NULL;
ALTER TABLE designs ADD COLUMN brief_created_by INT NULL;
ALTER TABLE designs ADD COLUMN brief_created_at TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE designs ADD COLUMN designed_by INT NULL;
ALTER TABLE designs ADD COLUMN designed_at TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE designs ADD COLUMN reviewed_by INT NULL;
ALTER TABLE designs ADD COLUMN reviewed_at TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE designs ADD COLUMN approved_by INT NULL;
ALTER TABLE designs ADD COLUMN approved_at TIMESTAMP NULL DEFAULT NULL;
-- NULL = draft (approved submission still being standardized; never shown to customers).
ALTER TABLE designs ADD COLUMN published_at TIMESTAMP NULL DEFAULT NULL;

ALTER TABLE designs ADD FOREIGN KEY (brief_id) REFERENCES briefs(id);
ALTER TABLE designs ADD FOREIGN KEY (brief_created_by) REFERENCES staff(id);
ALTER TABLE designs ADD FOREIGN KEY (designed_by) REFERENCES freelancers(id);
ALTER TABLE designs ADD FOREIGN KEY (reviewed_by) REFERENCES staff(id);
ALTER TABLE designs ADD FOREIGN KEY (approved_by) REFERENCES staff(id);

-- Fill the trail for designs already published from freelancer submissions.
UPDATE designs d
JOIN submissions s ON s.id = d.source_submission_id
JOIN briefs b ON b.id = s.brief_id
SET d.brief_id = b.id, d.brief_created_by = b.created_by, d.brief_created_at = b.created_at,
    d.designed_by = s.freelancer_id, d.designed_at = s.submitted_at,
    d.reviewed_by = s.reviewer_id, d.reviewed_at = s.reviewed_at,
    d.approved_by = s.reviewer_id, d.approved_at = s.reviewed_at
WHERE d.brief_id IS NULL;
-- Every design that exists today has already been published.
UPDATE designs SET published_at = created_at WHERE published_at IS NULL;

-- ---- 3. Settings and smart auto-assignment ---------------------------------------------------
CREATE TABLE IF NOT EXISTS app_settings (
  setting_key VARCHAR(50) PRIMARY KEY,
  setting_value VARCHAR(200) NOT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES
('max_active_orders_per_person', '5'),
('admin_notification_email', ''),
('site_url', 'https://test.planzaa.in');

ALTER TABLE library_orders ADD COLUMN overload_warning TINYINT(1) DEFAULT 0;
ALTER TABLE library_orders ADD COLUMN auto_assigned TINYINT(1) DEFAULT 0;
ALTER TABLE library_orders ADD COLUMN assignment_reason TEXT NULL;

-- ---- 4. Standard design files (one file per slot per design) --------------------------------
-- Files live in uploads/designs/{design_code}/{slot}.{ext} (drafts: uploads/designs/draft-{id}/)
-- and are only ever served through serve-file.php, which checks permissions.
CREATE TABLE IF NOT EXISTS design_files (
  id INT AUTO_INCREMENT PRIMARY KEY,
  design_id INT NOT NULL,
  file_slot VARCHAR(50) NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  original_filename VARCHAR(255) NOT NULL,
  file_size INT NOT NULL,
  uploaded_by_staff INT NULL,
  uploaded_by_freelancer INT NULL,
  uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY unique_slot (design_id, file_slot),
  FOREIGN KEY (design_id) REFERENCES designs(id),
  FOREIGN KEY (uploaded_by_staff) REFERENCES staff(id),
  FOREIGN KEY (uploaded_by_freelancer) REFERENCES freelancers(id)
);

-- ---- 5. Internal notes and files on orders ---------------------------------------------------
-- internal_notes holds a JSON list of {at, by, name, text}; never shown to customers.
ALTER TABLE library_orders ADD COLUMN internal_notes TEXT NULL;

CREATE TABLE IF NOT EXISTS order_files (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  file_name VARCHAR(255) NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  file_size INT NOT NULL,
  uploaded_by INT NOT NULL,
  uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (order_id) REFERENCES library_orders(id),
  FOREIGN KEY (uploaded_by) REFERENCES staff(id)
);
