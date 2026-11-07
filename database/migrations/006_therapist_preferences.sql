USE bella_vista_spa;

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
