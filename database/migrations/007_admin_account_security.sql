USE bella_vista_spa;

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
