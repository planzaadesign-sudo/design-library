-- =====================================================================
-- Phase 8: designer self-registration with admin approval.
-- Run once in phpMyAdmin (after schema-phase7.sql).
--
-- Existing freelancers (like the test account Aman Verma) stay active:
-- the new status column defaults to 'active', so every existing row gets it.
-- New sign-ups from register.php are saved as 'pending' until the admin approves.
-- =====================================================================

ALTER TABLE freelancers ADD COLUMN phone VARCHAR(15) NULL;
ALTER TABLE freelancers ADD COLUMN city VARCHAR(100) NULL;
ALTER TABLE freelancers ADD COLUMN qualification ENUM(
  'b_arch', 'diploma_arch', 'm_arch', 'civil_engineering',
  'interior_design', 'student', 'other'
) NULL;
ALTER TABLE freelancers ADD COLUMN qualification_other VARCHAR(100) NULL;
ALTER TABLE freelancers ADD COLUMN experience ENUM('0_1', '1_3', '3_5', '5_plus') NULL;
ALTER TABLE freelancers ADD COLUMN about_me TEXT NULL;
ALTER TABLE freelancers ADD COLUMN portfolio_link VARCHAR(255) NULL;
ALTER TABLE freelancers ADD COLUMN status ENUM('pending', 'active', 'rejected', 'suspended') DEFAULT 'active';
ALTER TABLE freelancers ADD COLUMN rejection_reason TEXT NULL;
ALTER TABLE freelancers ADD COLUMN reviewed_by INT NULL;
ALTER TABLE freelancers ADD COLUMN reviewed_at TIMESTAMP NULL;
ALTER TABLE freelancers ADD FOREIGN KEY (reviewed_by) REFERENCES staff(id);
ALTER TABLE freelancers ADD INDEX idx_freelancer_status (status);

-- Simple rate limiting for the public registration page and the email check:
-- one row per attempt (by IP address). Old rows are deleted automatically.
CREATE TABLE IF NOT EXISTS rate_limit_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  action VARCHAR(30) NOT NULL,
  ip VARCHAR(45) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_rate (action, ip, created_at)
);
