-- =====================================================================
-- FRESHERS PARTY 2026 — Complete MySQL schema
-- Import via phpMyAdmin (InfinityFree) OR let install.php create tables.
-- Charset: utf8mb4 · Engine: InnoDB (row locking for atomic entries)
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- Admins (role-based)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS admins (
  id            INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  name          VARCHAR(100)     NOT NULL,
  username      VARCHAR(50)      NOT NULL,
  password_hash VARCHAR(255)     NOT NULL,
  role          ENUM('SUPER_ADMIN','EVENT_ADMIN','PAYMENT_ADMIN','ENTRY_MANAGER') NOT NULL DEFAULT 'EVENT_ADMIN',
  status        ENUM('ACTIVE','DISABLED') NOT NULL DEFAULT 'ACTIVE',
  created_at    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_admins_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Students (participants)
-- pnr_number = the college PNR / admission number (e.g. 2425000000).
-- It is THE universal unique ID: students check status with PNR + mobile,
-- admins search by PNR everywhere, volunteers type PNR at the gate.
-- NULL is allowed only for admin-created guests/faculty/VIP who have no
-- college PNR (MySQL unique keys permit multiple NULLs).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS students (
  id               INT UNSIGNED   NOT NULL AUTO_INCREMENT,
  pnr_number       VARCHAR(15)    DEFAULT NULL,
  pass_id          VARCHAR(20)    NOT NULL,
  name             VARCHAR(100)   NOT NULL,
  mobile           VARCHAR(15)    DEFAULT NULL,
  email            VARCHAR(120)   NOT NULL,
  course           VARCHAR(100)   NOT NULL,
  year             VARCHAR(10)    NOT NULL,
  division         VARCHAR(20)    NOT NULL DEFAULT '',
  photo            VARCHAR(255)   DEFAULT NULL,
  pass_pin         VARCHAR(255)   DEFAULT NULL,
  pass_type        ENUM('PAID','FREE','GUEST','ORGANIZER','FACULTY','VIP') NOT NULL DEFAULT 'PAID',
  qr_token         CHAR(32)       NOT NULL,
  pass_status      ENUM('ACTIVE','DISABLED','CANCELLED') NOT NULL DEFAULT 'ACTIVE',
  payment_status   ENUM('NOT_REQUIRED','PENDING','APPROVED','REJECTED','REFUNDED','CANCELLED') NOT NULL DEFAULT 'PENDING',
  required_amount  DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
  paid_amount      DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
  entry_status     ENUM('NOT_ENTERED','ENTERED') NOT NULL DEFAULT 'NOT_ENTERED',
  entry_time       DATETIME       DEFAULT NULL,
  entry_method     VARCHAR(20)    DEFAULT NULL,
  created_at       DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_students_pnr (pnr_number),
  UNIQUE KEY uq_students_pass_id (pass_id),
  UNIQUE KEY uq_students_qr_token (qr_token),
  UNIQUE KEY uq_students_mobile (mobile),
  KEY idx_students_payment_status (payment_status),
  KEY idx_students_entry_status (entry_status),
  KEY idx_students_pass_status (pass_status),
  KEY idx_students_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Payments (UTR + screenshot verification)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS payments (
  id               INT UNSIGNED   NOT NULL AUTO_INCREMENT,
  student_id       INT UNSIGNED   NOT NULL,
  utr_number       VARCHAR(40)    NOT NULL,
  amount           DECIMAL(10,2)  NOT NULL,
  screenshot       VARCHAR(255)   DEFAULT NULL,
  payment_date     DATE           DEFAULT NULL,
  status           ENUM('PENDING','APPROVED','REJECTED','REFUNDED') NOT NULL DEFAULT 'PENDING',
  rejection_reason VARCHAR(255)   DEFAULT NULL,
  verified_by      INT UNSIGNED   DEFAULT NULL,
  verified_at      DATETIME       DEFAULT NULL,
  created_at       DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_payments_student_id (student_id),
  KEY idx_payments_utr_number (utr_number),
  KEY idx_payments_status (status),
  CONSTRAINT fk_payments_student FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Volunteers (gate scanners)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS volunteers (
  id             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  volunteer_id   VARCHAR(20)   NOT NULL,
  name           VARCHAR(100)  NOT NULL,
  password_hash  VARCHAR(255)  NOT NULL,
  gate_name      VARCHAR(50)   NOT NULL DEFAULT 'Gate 1',
  status         ENUM('ACTIVE','DISABLED') NOT NULL DEFAULT 'ACTIVE',
  created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_volunteers_volunteer_id (volunteer_id),
  KEY idx_volunteers_gate (gate_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Entry logs (every scan attempt — success or denial)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS entry_logs (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id    INT UNSIGNED    DEFAULT NULL,
  volunteer_id  INT UNSIGNED    DEFAULT NULL,
  admin_id      INT UNSIGNED    DEFAULT NULL,
  gate_name     VARCHAR(50)     DEFAULT NULL,
  scan_token    VARCHAR(64)     DEFAULT NULL,
  result        ENUM('SUCCESS','ALREADY_USED','INVALID','PAYMENT_PENDING','PAYMENT_REJECTED','PASS_DISABLED','PASS_CANCELLED','MANUAL_APPROVED','MANUAL_RESET','ENTRY_CLOSED') NOT NULL,
  entry_method  VARCHAR(20)     DEFAULT NULL,
  reason        VARCHAR(255)    DEFAULT NULL,
  scan_time     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ip_address    VARCHAR(45)     DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_entry_logs_student_id (student_id),
  KEY idx_entry_logs_result (result),
  KEY idx_entry_logs_scan_time (scan_time),
  KEY idx_entry_logs_gate (gate_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Audit logs (every sensitive admin action)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_logs (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_type    ENUM('ADMIN','VOLUNTEER','STUDENT','SYSTEM') NOT NULL,
  user_id      INT UNSIGNED    DEFAULT NULL,
  action       VARCHAR(50)     NOT NULL,
  target_type  VARCHAR(30)     DEFAULT NULL,
  target_id    VARCHAR(30)     DEFAULT NULL,
  old_value    TEXT            DEFAULT NULL,
  new_value    TEXT            DEFAULT NULL,
  reason       VARCHAR(255)    DEFAULT NULL,
  ip_address   VARCHAR(45)     DEFAULT NULL,
  created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_logs_user (user_type, user_id),
  KEY idx_audit_logs_action (action),
  KEY idx_audit_logs_target (target_type, target_id),
  KEY idx_audit_logs_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Event settings (single row, id = 1)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS event_settings (
  id                   TINYINT UNSIGNED NOT NULL,
  event_name           VARCHAR(120)  NOT NULL DEFAULT 'FRESHERS PARTY 2026',
  college_name         VARCHAR(150)  NOT NULL DEFAULT 'Your College Name',
  event_date           DATE          DEFAULT NULL,
  start_time           TIME          DEFAULT NULL,
  end_time             TIME          DEFAULT NULL,
  registration_fee     DECIMAL(10,2) NOT NULL DEFAULT 300.00,
  currency             VARCHAR(5)    NOT NULL DEFAULT 'INR',
  upi_id               VARCHAR(100)  DEFAULT 'example@upi',
  payment_qr           VARCHAR(255)  DEFAULT NULL,
  college_logo         VARCHAR(255)  DEFAULT NULL,
  event_banner         VARCHAR(255)  DEFAULT NULL,
  event_logo           VARCHAR(255)  DEFAULT NULL,
  max_participants     INT UNSIGNED  NOT NULL DEFAULT 500,
  registration_status  ENUM('OPEN','CLOSED') NOT NULL DEFAULT 'OPEN',
  payment_status       ENUM('OPEN','CLOSED') NOT NULL DEFAULT 'OPEN',
  entry_status         ENUM('OPEN','CLOSED') NOT NULL DEFAULT 'OPEN',
  contact_number       VARCHAR(20)   DEFAULT NULL,
  contact_email        VARCHAR(120)  DEFAULT NULL,
  created_at           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default settings row (no default admin — create one via install.php)
INSERT INTO event_settings
  (id, event_name, college_name, event_date, start_time, end_time, registration_fee,
   currency, upi_id, max_participants, registration_status, payment_status, entry_status)
VALUES
  (1, 'FRESHERS PARTY 2026', 'Your College Name', NULL, NULL, NULL, 300.00,
   'INR', 'example@upi', 500, 'OPEN', 'OPEN', 'OPEN')
ON DUPLICATE KEY UPDATE id = id;

-- ---------------------------------------------------------------------
-- Default Super Admin (Username: admin, Password: admin123)
-- ---------------------------------------------------------------------
INSERT INTO admins (id, name, username, password_hash, role, status)
VALUES (1, 'Administrator', 'admin', '$2y$10$U6UkjwjZ99HrZuK.j6KOKuP9l2GmUIrgvelMf7TGWeXvB3J4pVvA2', 'SUPER_ADMIN', 'ACTIVE')
ON DUPLICATE KEY UPDATE id = id;
