CREATE TABLE IF NOT EXISTS staff (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  email VARCHAR(150) UNIQUE NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','inhouse') NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS freelancers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  email VARCHAR(150) UNIQUE NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  earnings INT DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS briefs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(150) NOT NULL,
  plot_width INT, plot_length INT, facing VARCHAR(10),
  house_type VARCHAR(50),
  requirements TEXT NOT NULL,
  payout INT NOT NULL,
  deadline DATE NOT NULL,
  status ENUM('open','claimed','in_review','needs_revision','approved','published') DEFAULT 'open',
  claimed_by INT NULL,
  created_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (claimed_by) REFERENCES freelancers(id),
  FOREIGN KEY (created_by) REFERENCES staff(id)
);

CREATE TABLE IF NOT EXISTS submissions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  brief_id INT NOT NULL,
  freelancer_id INT NOT NULL,
  cad_file_path VARCHAR(255),
  notes TEXT,
  submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  review_status ENUM('pending','rejected','approved') DEFAULT 'pending',
  reviewer_id INT NULL,
  review_notes TEXT,
  reviewed_at TIMESTAMP NULL,
  published TINYINT(1) DEFAULT 0,
  FOREIGN KEY (brief_id) REFERENCES briefs(id),
  FOREIGN KEY (freelancer_id) REFERENCES freelancers(id),
  FOREIGN KEY (reviewer_id) REFERENCES staff(id)
);

-- Extend orders with the real pipeline stages and an assignment column.
ALTER TABLE library_orders
  MODIFY status ENUM('new','design','structural','compliance','delivered') DEFAULT 'new',
  ADD COLUMN assigned_to INT NULL,
  ADD FOREIGN KEY (assigned_to) REFERENCES staff(id);

-- Trace a published design back to who created and who standardized it.
ALTER TABLE designs
  ADD COLUMN source_submission_id INT NULL,
  ADD COLUMN standardized_by INT NULL,
  ADD FOREIGN KEY (source_submission_id) REFERENCES submissions(id),
  ADD FOREIGN KEY (standardized_by) REFERENCES staff(id);
