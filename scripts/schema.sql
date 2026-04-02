-- Schema SQL del modello dati applicativo:
-- utenti, anagrafiche, disponibilità, visite, audit, referti e autenticazione.
PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS category_visits (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE CHECK (trim(name) <> '')
);

CREATE TABLE IF NOT EXISTS patients (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    first_name TEXT NOT NULL CHECK (trim(first_name) <> ''),
    last_name TEXT NOT NULL CHECK (trim(last_name) <> ''),
    tax_code TEXT NOT NULL UNIQUE CHECK (trim(tax_code) <> ''),
    email TEXT NOT NULL UNIQUE CHECK (trim(email) <> ''),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS doctors (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    first_name TEXT NOT NULL CHECK (trim(first_name) <> ''),
    last_name TEXT NOT NULL CHECK (trim(last_name) <> ''),
    email TEXT NOT NULL UNIQUE CHECK (trim(email) <> ''),
    internal_code TEXT NOT NULL UNIQUE CHECK (trim(internal_code) <> ''),
    active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1)),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    role TEXT NOT NULL CHECK (role IN ('PATIENT', 'DOCTOR', 'RECEPTION', 'INTEGRATOR')),
    email TEXT NOT NULL UNIQUE CHECK (trim(email) <> ''),
    password_hash TEXT NOT NULL CHECK (trim(password_hash) <> ''),
    first_name TEXT NOT NULL CHECK (trim(first_name) <> ''),
    last_name TEXT NOT NULL CHECK (trim(last_name) <> ''),
    active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1)),
    must_change_password INTEGER NOT NULL DEFAULT 0 CHECK (must_change_password IN (0, 1)),
    patient_id INTEGER NULL,
    doctor_id INTEGER NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE RESTRICT,
    FOREIGN KEY (doctor_id) REFERENCES doctors(id) ON DELETE RESTRICT,
    CHECK (
        (role = 'PATIENT' AND patient_id IS NOT NULL AND doctor_id IS NULL)
        OR (role = 'DOCTOR' AND doctor_id IS NOT NULL AND patient_id IS NULL)
        OR (role IN ('RECEPTION', 'INTEGRATOR') AND patient_id IS NULL AND doctor_id IS NULL)
    )
);

CREATE TABLE IF NOT EXISTS doctor_availability (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    doctor_id INTEGER NOT NULL,
    weekday INTEGER NOT NULL CHECK (weekday BETWEEN 1 AND 7),
    start_time TEXT NOT NULL,
    end_time TEXT NOT NULL,
    valid_from TEXT NULL,
    valid_to TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (doctor_id) REFERENCES doctors(id) ON DELETE CASCADE,
    CHECK (time(end_time) > time(start_time)),
    CHECK (valid_from IS NULL OR valid_to IS NULL OR date(valid_to) >= date(valid_from))
);

CREATE TABLE IF NOT EXISTS appointments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    patient_id INTEGER NOT NULL,
    doctor_id INTEGER NOT NULL,
    visit_category_id INTEGER NOT NULL,
    visit_reason TEXT NOT NULL CHECK (trim(visit_reason) <> ''),
    notes TEXT NULL,
    scheduled_start TEXT NOT NULL,
    scheduled_end TEXT NOT NULL,
    status TEXT NOT NULL CHECK (status IN ('PRENOTATA', 'IN_CORSO', 'CONCLUSA', 'ANNULLATA')),
    created_by_user_id INTEGER NOT NULL,
    cancellation_by_role TEXT NULL CHECK (cancellation_by_role IS NULL OR cancellation_by_role IN ('PATIENT', 'DOCTOR', 'RECEPTION', 'INTEGRATOR')),
    cancellation_by_user_id INTEGER NULL,
    cancellation_reason TEXT NULL,
    canceled_at TEXT NULL,
    started_at TEXT NULL,
    ended_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE RESTRICT,
    FOREIGN KEY (doctor_id) REFERENCES doctors(id) ON DELETE RESTRICT,
    FOREIGN KEY (visit_category_id) REFERENCES category_visits(id) ON DELETE RESTRICT,
    FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (cancellation_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CHECK (datetime(scheduled_end) > datetime(scheduled_start)),
    CHECK (started_at IS NULL OR ended_at IS NULL OR datetime(ended_at) >= datetime(started_at))
);

CREATE TABLE IF NOT EXISTS appointment_status_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    appointment_id INTEGER NOT NULL,
    from_status TEXT NULL CHECK (from_status IS NULL OR from_status IN ('PRENOTATA', 'IN_CORSO', 'CONCLUSA', 'ANNULLATA')),
    to_status TEXT NOT NULL CHECK (to_status IN ('PRENOTATA', 'IN_CORSO', 'CONCLUSA', 'ANNULLATA')),
    changed_by_user_id INTEGER NULL,
    changed_at TEXT NOT NULL,
    note TEXT NULL,
    FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE,
    FOREIGN KEY (changed_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS reports (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    appointment_id INTEGER NOT NULL UNIQUE,
    created_by_doctor_id INTEGER NOT NULL,
    cipher_text TEXT NOT NULL CHECK (trim(cipher_text) <> ''),
    iv TEXT NOT NULL CHECK (trim(iv) <> ''),
    tag TEXT NOT NULL CHECK (trim(tag) <> ''),
    algorithm TEXT NOT NULL CHECK (trim(algorithm) <> ''),
    created_at TEXT NOT NULL,
    FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by_doctor_id) REFERENCES doctors(id) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS report_keys (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    report_id INTEGER NOT NULL,
    recipient_user_id INTEGER NOT NULL,
    encrypted_dek TEXT NOT NULL CHECK (trim(encrypted_dek) <> ''),
    iv TEXT NOT NULL CHECK (trim(iv) <> ''),
    tag TEXT NOT NULL CHECK (trim(tag) <> ''),
    wrapped_by_kek_version TEXT NOT NULL CHECK (trim(wrapped_by_kek_version) <> ''),
    created_at TEXT NOT NULL,
    UNIQUE (report_id, recipient_user_id),
    FOREIGN KEY (report_id) REFERENCES reports(id) ON DELETE CASCADE,
    FOREIGN KEY (recipient_user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS api_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    token_hash TEXT NOT NULL UNIQUE CHECK (trim(token_hash) <> ''),
    name TEXT NOT NULL CHECK (trim(name) <> ''),
    created_at TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    last_used_at TEXT NULL,
    revoked_at TEXT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS web_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    session_hash TEXT NOT NULL UNIQUE CHECK (trim(session_hash) <> ''),
    csrf_token TEXT NOT NULL CHECK (trim(csrf_token) <> ''),
    created_at TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    last_used_at TEXT NULL,
    revoked_at TEXT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_users_patient_id ON users(patient_id) WHERE patient_id IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uq_users_doctor_id ON users(doctor_id) WHERE doctor_id IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uq_users_single_integrator ON users(role) WHERE role = 'INTEGRATOR';

CREATE INDEX IF NOT EXISTS idx_doctor_availability_doc_week ON doctor_availability(doctor_id, weekday, start_time, end_time);
CREATE INDEX IF NOT EXISTS idx_appointments_status ON appointments(status);
CREATE INDEX IF NOT EXISTS idx_appointments_category_start ON appointments(visit_category_id, scheduled_start);
CREATE INDEX IF NOT EXISTS idx_appointments_doctor_slot ON appointments(doctor_id, scheduled_start, scheduled_end);
CREATE INDEX IF NOT EXISTS idx_appointments_patient_slot ON appointments(patient_id, scheduled_start, scheduled_end);
CREATE UNIQUE INDEX IF NOT EXISTS uq_appointments_doctor_active_slot ON appointments(doctor_id, scheduled_start) WHERE status <> 'ANNULLATA';
CREATE UNIQUE INDEX IF NOT EXISTS uq_appointments_patient_single_active ON appointments(patient_id) WHERE status IN ('PRENOTATA', 'IN_CORSO');
CREATE INDEX IF NOT EXISTS idx_appointment_status_history_appt ON appointment_status_history(appointment_id, changed_at, id);
CREATE INDEX IF NOT EXISTS idx_reports_created_by_doctor ON reports(created_by_doctor_id, created_at);
CREATE INDEX IF NOT EXISTS idx_report_keys_user ON report_keys(recipient_user_id);
CREATE INDEX IF NOT EXISTS idx_api_tokens_user ON api_tokens(user_id, expires_at);
CREATE INDEX IF NOT EXISTS idx_web_sessions_user ON web_sessions(user_id, expires_at);
