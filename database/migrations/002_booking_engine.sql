-- Run once on an existing Phase 1 bella_vista_spa database. Back up first.
-- Fresh installations use schema.sql instead, which already includes this change.
USE bella_vista_spa;
CREATE TABLE IF NOT EXISTS schema_migrations (
    version VARCHAR(80) PRIMARY KEY,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

ALTER TABLE appointments
    ADD COLUMN payment_status ENUM('unpaid','pending','partially_paid','paid') NOT NULL DEFAULT 'unpaid' AFTER status,
    ADD COLUMN request_key CHAR(64) NULL AFTER payment_status,
    ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    ADD UNIQUE KEY uq_booking_request (request_key);

ALTER TABLE therapists
    ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

UPDATE service_staffing_requirements SET therapists_required = 1 WHERE therapists_required IS NULL;
ALTER TABLE service_staffing_requirements
    MODIFY therapists_required TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Phase 2 default: one per appointment',
    ADD CONSTRAINT chk_staffing_positive CHECK (therapists_required >= 1);

INSERT INTO schema_migrations (version) VALUES ('002_booking_engine');
