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
