-- =====================================================================
-- Phase 9: security hardening. Run once in phpMyAdmin (after schema-phase8.sql).
--   * forced password change (temporary passwords, "everyone change your password")
--   * one active admin session at a time
--   * password reset links (only a hash of each link's secret is stored)
--   * failed sign-in tracking for lockouts and the admin Security list
-- =====================================================================

-- Force password change flag
ALTER TABLE staff ADD COLUMN must_change_password TINYINT(1) DEFAULT 0;

-- The admin's current session. Signing in from a new browser replaces it,
-- which signs the old browser out (only one active admin session).
ALTER TABLE staff ADD COLUMN session_token VARCHAR(64) NULL;

-- Password reset tokens (token_hash = password_hash() of the secret in the emailed link)
CREATE TABLE IF NOT EXISTS password_reset_tokens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_type ENUM('staff', 'freelancer') NOT NULL,
  user_id INT NOT NULL,
  token_hash VARCHAR(255) NOT NULL,
  expires_at TIMESTAMP NOT NULL,
  used TINYINT(1) DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_reset_user (user_type, user_id, created_at)
);

-- Login attempt tracking. Every attempt is kept (for the admin Security list);
-- "cleared" marks failures that no longer count (after a successful sign-in or an admin unlock).
CREATE TABLE IF NOT EXISTS login_attempts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(150) NOT NULL,
  ip_address VARCHAR(45) NOT NULL,
  attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  success TINYINT(1) DEFAULT 0,
  cleared TINYINT(1) DEFAULT 0,
  INDEX idx_ip_time (ip_address, attempted_at),
  INDEX idx_email_time (email, attempted_at)
);

-- Force existing admin to change the default password
UPDATE staff SET must_change_password = 1 WHERE email = 'admin@planzaa.in';
