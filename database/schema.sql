-- Bella Vista Oasis Spa · Phase 3 · MySQL 8 / XAMPP MariaDB 10.4+
-- Fresh-install script: deliberately does not DROP or overwrite existing tables.
CREATE DATABASE IF NOT EXISTS bella_vista_spa CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE bella_vista_spa;

CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(254) NOT NULL,
    phone VARCHAR(25) NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('customer','admin') NOT NULL DEFAULT 'customer',
    terms_accepted_at DATETIME NOT NULL,
    terms_version VARCHAR(30) NOT NULL DEFAULT 'phase-1',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB;

CREATE TABLE services (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(80) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    description TEXT NOT NULL,
    base_price DECIMAL(10,2) NOT NULL,
    duration_minutes SMALLINT UNSIGNED NOT NULL,
    base_capacity TINYINT UNSIGNED NOT NULL,
    max_capacity TINYINT UNSIGNED NOT NULL,
    extra_person_fee DECIMAL(10,2) NOT NULL DEFAULT 0,
    image_filename VARCHAR(100) NOT NULL,
    sort_order SMALLINT NOT NULL DEFAULT 0,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    CHECK (base_price >= 0 AND extra_person_fee >= 0),
    CHECK (base_capacity >= 1 AND max_capacity >= base_capacity),
    CHECK (duration_minutes IN (60,90))
) ENGINE=InnoDB;

CREATE TABLE rooms (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    service_id INT UNSIGNED NOT NULL,
    code VARCHAR(10) NOT NULL UNIQUE,
    capacity TINYINT UNSIGNED NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    FOREIGN KEY (service_id) REFERENCES services(id),
    UNIQUE KEY uq_room_service (id, service_id),
    CHECK (capacity BETWEEN 1 AND 6)
) ENGINE=InnoDB;

CREATE TABLE therapists (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE business_settings (
    id TINYINT UNSIGNED PRIMARY KEY,
    timezone VARCHAR(60) NOT NULL DEFAULT 'Asia/Manila',
    slot_interval_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    cleaning_gap_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    arrival_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 15,
    cancellation_notice_hours SMALLINT UNSIGNED NOT NULL DEFAULT 6,
    grace_period_minutes SMALLINT UNSIGNED NULL,
    downpayment_percentage DECIMAL(5,2) NULL,
    refund_policy TEXT NULL,
    CHECK (id = 1),
    CHECK (downpayment_percentage IS NULL OR downpayment_percentage BETWEEN 0 AND 100)
) ENGINE=InnoDB;

CREATE TABLE operating_hours (
    weekday TINYINT UNSIGNED PRIMARY KEY COMMENT 'ISO weekday: Monday=1, Sunday=7',
    opens_at TIME NOT NULL,
    closes_at TIME NOT NULL,
    CHECK (weekday BETWEEN 1 AND 7),
    CHECK (opens_at < closes_at)
) ENGINE=InnoDB;

CREATE TABLE service_staffing_requirements (
    service_id INT UNSIGNED PRIMARY KEY,
    therapists_required TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Phase 2 default: one per appointment',
    CHECK (therapists_required >= 1),
    FOREIGN KEY (service_id) REFERENCES services(id)
) ENGINE=InnoDB;

CREATE TABLE health_declarations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    declaration_version VARCHAR(30) NOT NULL,
    responses_json JSON NOT NULL,
    consented_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    UNIQUE KEY uq_declaration_owner (id, user_id)
) ENGINE=InnoDB;

CREATE TABLE appointments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_reference VARCHAR(32) NOT NULL UNIQUE,
    user_id BIGINT UNSIGNED NOT NULL,
    service_id INT UNSIGNED NOT NULL,
    room_id INT UNSIGNED NOT NULL,
    health_declaration_id BIGINT UNSIGNED NOT NULL,
    guest_count TINYINT UNSIGNED NOT NULL,
    starts_at DATETIME NOT NULL COMMENT 'UTC; convert business time before storing',
    ends_at DATETIME NOT NULL,
    cleaning_ends_at DATETIME NOT NULL,
    total_amount DECIMAL(10,2) NOT NULL COMMENT 'Price snapshot at booking',
    downpayment_amount DECIMAL(10,2) NULL,
    currency CHAR(3) NOT NULL DEFAULT 'PHP',
    status ENUM('pending','confirmed','completed','cancelled','no_show') NOT NULL DEFAULT 'pending',
    payment_status ENUM('unpaid','pending','partially_paid','paid') NOT NULL DEFAULT 'unpaid',
    request_key CHAR(64) NULL,
    terms_accepted_at DATETIME NOT NULL,
    terms_version VARCHAR(30) NOT NULL,
    cancelled_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_booking_request (request_key),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (service_id) REFERENCES services(id),
    FOREIGN KEY (room_id, service_id) REFERENCES rooms(id, service_id),
    FOREIGN KEY (health_declaration_id, user_id) REFERENCES health_declarations(id, user_id),
    INDEX ix_appointments_room_window (room_id, status, starts_at, cleaning_ends_at),
    INDEX ix_appointments_customer (user_id, starts_at),
    CHECK (ends_at > starts_at AND cleaning_ends_at >= ends_at),
    CHECK (guest_count BETWEEN 1 AND 6),
    CHECK (total_amount >= 0 AND (downpayment_amount IS NULL OR downpayment_amount BETWEEN 0 AND total_amount))
) ENGINE=InnoDB;

CREATE TABLE appointment_therapists (
    appointment_id BIGINT UNSIGNED NOT NULL,
    therapist_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (appointment_id, therapist_id),
    FOREIGN KEY (appointment_id) REFERENCES appointments(id),
    FOREIGN KEY (therapist_id) REFERENCES therapists(id),
    INDEX ix_therapist_assignments (therapist_id, appointment_id)
) ENGINE=InnoDB;

CREATE TABLE therapist_schedules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    therapist_id INT UNSIGNED NOT NULL,
    starts_at DATETIME NOT NULL COMMENT 'UTC',
    ends_at DATETIME NOT NULL,
    schedule_type ENUM('available','unavailable') NOT NULL,
    FOREIGN KEY (therapist_id) REFERENCES therapists(id),
    UNIQUE KEY uq_therapist_schedule (therapist_id, starts_at, ends_at, schedule_type),
    CHECK (ends_at > starts_at)
) ENGINE=InnoDB;

CREATE TABLE room_blocks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    room_id INT UNSIGNED NOT NULL,
    starts_at DATETIME NOT NULL COMMENT 'UTC',
    ends_at DATETIME NOT NULL,
    reason VARCHAR(200) NOT NULL,
    FOREIGN KEY (room_id) REFERENCES rooms(id),
    INDEX ix_room_blocks_window (room_id, starts_at, ends_at),
    CHECK (ends_at > starts_at)
) ENGINE=InnoDB;

CREATE TABLE payments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    appointment_id BIGINT UNSIGNED NOT NULL,
    provider VARCHAR(60) NOT NULL,
    provider_reference VARCHAR(150) NULL,
    idempotency_key VARCHAR(100) NOT NULL UNIQUE,
    amount DECIMAL(10,2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'PHP',
    payment_type ENUM('full','downpayment','balance','refund') NOT NULL,
    status ENUM('pending','verified','failed','refunded') NOT NULL DEFAULT 'pending',
    verified_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (appointment_id) REFERENCES appointments(id),
    UNIQUE KEY uq_provider_payment (provider, provider_reference),
    CHECK (amount > 0),
    CHECK (status <> 'verified' OR verified_at IS NOT NULL)
) ENGINE=InnoDB;

CREATE TABLE payment_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payment_id BIGINT UNSIGNED NOT NULL,
    provider_event_id VARCHAR(150) NOT NULL,
    received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (payment_id) REFERENCES payments(id),
    UNIQUE KEY uq_payment_event (payment_id, provider_event_id)
) ENGINE=InnoDB;

CREATE TABLE login_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email_hash CHAR(64) NOT NULL,
    ip_hash CHAR(64) NOT NULL,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX ix_login_email_time (email_hash, attempted_at),
    INDEX ix_login_ip_time (ip_hash, attempted_at)
) ENGINE=InnoDB;

INSERT INTO business_settings (id) VALUES (1);
INSERT INTO operating_hours VALUES (1,'13:00','21:00'),(2,'13:00','21:00'),(3,'13:00','21:00'),(4,'13:00','21:00'),(5,'13:00','21:00'),(6,'13:00','21:00'),(7,'13:00','21:00');
INSERT INTO services (slug,name,description,base_price,duration_minutes,base_capacity,max_capacity,extra_person_fee,image_filename,sort_order) VALUES
('single-room-massage','Single Room Massage','Includes a dedicated therapist and private treatment room.',800,60,1,1,0,'single.jpg',1),
('couples-suite','Couples Suite','Includes complimentary herbal tea and private room access for two.',1800,60,2,2,0,'couples.jpg',2),
('family-lounge','Barkada / Family Spa Lounge','A private lounge experience for your barkada or family. Base price covers up to 4 persons; maximum capacity is 6.',4000,90,4,6,750,'lounge.jpg',3);
INSERT INTO rooms (service_id,code,capacity) VALUES (1,'SR-1',1),(1,'SR-2',1),(1,'SR-3',1),(2,'CS-1',2),(2,'CS-2',2),(3,'FL-1',6),(3,'FL-2',6);
INSERT INTO service_staffing_requirements (service_id,therapists_required) VALUES (1,1),(2,1),(3,1);

CREATE TABLE schema_migrations (
    version VARCHAR(80) PRIMARY KEY,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
INSERT INTO schema_migrations (version) VALUES ('002_booking_engine');


-- Phase 3 additions (fresh installs include these automatically).
-- Phase 2 -> Phase 3. Back up first. Run once; the CLI runner checks version.
USE bella_vista_spa;
ALTER TABLE business_settings ADD checkout_hold_minutes SMALLINT UNSIGNED NULL,
    ADD CONSTRAINT chk_hold_minutes CHECK (checkout_hold_minutes IS NULL OR checkout_hold_minutes > 0);
ALTER TABLE appointments
    MODIFY status ENUM('pending','awaiting_payment','confirmed','ongoing','completed','cancelled','no_show','expired') NOT NULL DEFAULT 'pending',
    MODIFY payment_status ENUM('unpaid','pending','partially_paid','paid','failed','cancelled','refund_pending_review','partially_refunded','refunded') NOT NULL DEFAULT 'unpaid',
    ADD booking_mode ENUM('legacy','unpaid','online') NOT NULL DEFAULT 'legacy',
    ADD hold_expires_at DATETIME NULL,
    ADD revision INT UNSIGNED NOT NULL DEFAULT 0,
    ADD service_name_snapshot VARCHAR(100) NULL,
    ADD duration_minutes_snapshot SMALLINT UNSIGNED NULL,
    ADD base_amount DECIMAL(10,2) NULL,
    ADD extra_amount DECIMAL(10,2) NULL;
UPDATE appointments a JOIN services s ON s.id=a.service_id
    SET a.service_name_snapshot=s.name, a.duration_minutes_snapshot=TIMESTAMPDIFF(MINUTE,a.starts_at,a.ends_at),
    a.base_amount=LEAST(a.total_amount,s.base_price), a.extra_amount=a.total_amount-LEAST(a.total_amount,s.base_price), a.updated_at=a.updated_at;

CREATE TABLE payment_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    appointment_id BIGINT UNSIGNED NOT NULL,
    public_id CHAR(32) NOT NULL UNIQUE,
    request_key CHAR(64) NOT NULL UNIQUE,
    provider VARCHAR(60) NOT NULL,
    provider_attempt_reference VARCHAR(150) NULL,
    payment_type ENUM('full','downpayment','balance') NOT NULL,
    amount_centavos BIGINT UNSIGNED NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'PHP',
    status ENUM('initiated','pending','verified','failed','cancelled','expired','requires_review') NOT NULL DEFAULT 'initiated',
    checkout_url TEXT NULL,
    expires_at DATETIME NOT NULL,
    verified_at DATETIME NULL,
    terms_accepted_at DATETIME NOT NULL,
    terms_version VARCHAR(30) NOT NULL DEFAULT 'phase-3',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (appointment_id) REFERENCES appointments(id),
    UNIQUE KEY uq_attempt_provider (provider,provider_attempt_reference),
    INDEX ix_active_attempts (appointment_id,status),
    CHECK (amount_centavos > 0)
) ENGINE=InnoDB;
ALTER TABLE payments ADD attempt_id BIGINT UNSIGNED NULL,
    ADD allocation_status ENUM('allocated','review') NOT NULL DEFAULT 'allocated',
    ADD FOREIGN KEY (attempt_id) REFERENCES payment_attempts(id);

CREATE TABLE payment_webhook_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provider VARCHAR(60) NOT NULL,
    provider_event_id VARCHAR(150) NOT NULL,
    attempt_id BIGINT UNSIGNED NULL,
    payload_sha256 CHAR(64) NOT NULL,
    signature_verified_at DATETIME NOT NULL,
    status ENUM('processed','requires_review') NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (attempt_id) REFERENCES payment_attempts(id),
    UNIQUE KEY uq_webhook_event (provider,provider_event_id)
) ENGINE=InnoDB;
CREATE TABLE appointment_changes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    appointment_id BIGINT UNSIGNED NOT NULL,
    actor_user_id BIGINT UNSIGNED NULL,
    action VARCHAR(40) NOT NULL,
    request_key CHAR(64) NULL UNIQUE,
    before_json JSON NOT NULL,
    after_json JSON NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (appointment_id) REFERENCES appointments(id),
    FOREIGN KEY (actor_user_id) REFERENCES users(id)
) ENGINE=InnoDB;
CREATE TABLE cancellation_records (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    appointment_id BIGINT UNSIGNED NOT NULL UNIQUE,
    actor_user_id BIGINT UNSIGNED NOT NULL,
    reason VARCHAR(1000) NOT NULL,
    notice_deadline DATETIME NOT NULL,
    cancelled_at DATETIME NOT NULL,
    is_late BOOLEAN NOT NULL DEFAULT FALSE,
    known_forfeiture_centavos BIGINT UNSIGNED NULL,
    FOREIGN KEY (appointment_id) REFERENCES appointments(id),
    FOREIGN KEY (actor_user_id) REFERENCES users(id)
) ENGINE=InnoDB;
CREATE TABLE refund_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    appointment_id BIGINT UNSIGNED NOT NULL,
    cancellation_id BIGINT UNSIGNED NULL UNIQUE,
    status ENUM('pending_policy_review','pending_admin_review','resolved') NOT NULL DEFAULT 'pending_policy_review',
    requested_amount_centavos BIGINT UNSIGNED NULL,
    reason VARCHAR(200) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (appointment_id) REFERENCES appointments(id),
    FOREIGN KEY (cancellation_id) REFERENCES cancellation_records(id)
) ENGINE=InnoDB;
CREATE TABLE payment_reconciliation_cases (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    case_key CHAR(64) NOT NULL UNIQUE,
    appointment_id BIGINT UNSIGNED NULL,
    attempt_id BIGINT UNSIGNED NULL,
    provider VARCHAR(60) NOT NULL,
    transaction_reference VARCHAR(150) NULL,
    observed_amount_centavos BIGINT UNSIGNED NULL,
    observed_currency CHAR(3) NULL,
    reason_code VARCHAR(50) NOT NULL,
    status ENUM('open','resolved') NOT NULL DEFAULT 'open',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (appointment_id) REFERENCES appointments(id),
    FOREIGN KEY (attempt_id) REFERENCES payment_attempts(id)
) ENGINE=InnoDB;
INSERT INTO schema_migrations (version) VALUES ('003_customer_management');

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

ALTER TABLE appointments
    ADD preferred_therapist_id INT UNSIGNED NULL,
    ADD CONSTRAINT fk_appointment_therapist_preference
        FOREIGN KEY (preferred_therapist_id) REFERENCES therapists(id);

-- Preserve eligibility for existing staff. Administrators explicitly exclude services.
CREATE TABLE therapist_service_exclusions (
    therapist_id INT UNSIGNED NOT NULL,
    service_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (therapist_id, service_id),
    FOREIGN KEY (therapist_id) REFERENCES therapists(id),
    FOREIGN KEY (service_id) REFERENCES services(id)
) ENGINE=InnoDB;

INSERT INTO schema_migrations (version) VALUES ('006_therapist_preferences');

ALTER TABLE users
    MODIFY password_hash VARCHAR(255) NULL,
    MODIFY terms_accepted_at DATETIME NULL,
    ADD account_status ENUM('active','pending','inactive') NOT NULL DEFAULT 'active',
    ADD session_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
    ADD last_login_at DATETIME NULL,
    ADD CONSTRAINT ck_users_pending_admin CHECK (account_status <> 'pending' OR role = 'admin'),
    ADD CONSTRAINT ck_users_active_credentials CHECK (account_status = 'pending' OR (password_hash IS NOT NULL AND terms_accepted_at IS NOT NULL));

CREATE TABLE admin_invitations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    revoked_at DATETIME NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_admin_invitation_hash (token_hash),
    INDEX ix_admin_invitation_user (user_id,id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE admin_security_throttles (
    user_id BIGINT UNSIGNED PRIMARY KEY,
    attempts TINYINT UNSIGNED NOT NULL,
    started_at DATETIME NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE admin_security_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_type VARCHAR(50) NOT NULL,
    actor_user_id BIGINT UNSIGNED NOT NULL,
    target_user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (actor_user_id) REFERENCES users(id),
    FOREIGN KEY (target_user_id) REFERENCES users(id)
) ENGINE=InnoDB;

INSERT INTO schema_migrations (version) VALUES ('007_admin_account_security');

