-- Phase 5 additive upgrade. Existing users, bookings and verified payment ledger are retained.
USE bella_vista_spa;
ALTER TABLE business_settings
 ADD demo_payments_enabled BOOLEAN NOT NULL DEFAULT TRUE,
 ADD demo_downpayment_percentage DECIMAL(5,2) NOT NULL DEFAULT 30.00,
 ADD demo_hold_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 15,
 ADD CHECK (demo_downpayment_percentage > 0 AND demo_downpayment_percentage <= 100),
 ADD CHECK (demo_hold_minutes BETWEEN 1 AND 60);
ALTER TABLE appointments MODIFY booking_mode ENUM('legacy','unpaid','online','demo') NOT NULL DEFAULT 'legacy',
 ADD INDEX idx_admin_day_status (starts_at,status);
ALTER TABLE therapists ADD is_demo BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE refund_requests ADD is_demo BOOLEAN NOT NULL DEFAULT FALSE,
 ADD admin_note VARCHAR(1000) NULL,
 ADD reviewed_by BIGINT UNSIGNED NULL,
 ADD reviewed_at DATETIME NULL,
 ADD FOREIGN KEY (reviewed_by) REFERENCES users(id);
CREATE TABLE demo_payments (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 appointment_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 request_key CHAR(64) NOT NULL UNIQUE,
 transaction_reference VARCHAR(40) NOT NULL UNIQUE,
 payment_option ENUM('full','downpayment','balance') NOT NULL,
 method ENUM('demo_gcash','demo_maya','demo_card') NOT NULL,
 total_centavos BIGINT UNSIGNED NOT NULL,
 amount_centavos BIGINT UNSIGNED NOT NULL,
 remaining_centavos BIGINT UNSIGNED NOT NULL,
 percentage_snapshot DECIMAL(5,2) NULL,
 status ENUM('completed') NOT NULL DEFAULT 'completed',
 is_demo BOOLEAN NOT NULL DEFAULT TRUE,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (appointment_id) REFERENCES appointments(id),
 FOREIGN KEY (user_id) REFERENCES users(id),
 UNIQUE KEY uq_demo_appointment_option (appointment_id,payment_option),
 INDEX idx_demo_payment_day (created_at),
 CHECK (is_demo=TRUE),
 CHECK (amount_centavos>0),
 CHECK (amount_centavos<=total_centavos)
) ENGINE=InnoDB;
INSERT INTO schema_migrations (version) VALUES ('005_demo_admin');
