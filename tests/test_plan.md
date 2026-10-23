# Fresher Party System — Comprehensive Test Plan & QA Matrix

## 1. Overview & Objective
This document defines the Quality Assurance and Verification plan for the **Fresher Party System (PHP 8.0+ / MySQL 5.7+ / Bootstrap 5.3)**.
The objective is to ensure 100% stability, security compliance, mobile responsiveness, and high-concurrency resilience during the event.

---

## 2. Test Execution Environments

| Parameter | Development | Production (InfinityFree) |
|---|---|---|
| OS | Windows 10/11 (XAMPP / Apache) | Linux (Apache 2.4) |
| PHP Version | PHP 8.2.12 | PHP 8.2.x |
| MySQL Version | MariaDB 10.4 / MySQL 8.0 | MySQL 5.7 / MariaDB 10.4 |
| Protocol | HTTP (localhost) | HTTPS (SSL Required for Camera) |

---

## 3. Automated Test Suites

The codebase includes two automated CLI test suites in `tests/`:

### 3.1 Regression Test Suite (`tests/regression_test.php`)
* **Total Tests:** 107 test cases.
* **Execution:** `php tests/regression_test.php`
* **Coverage:**
  - Database schema integrity and InnoDB verification
  - Security helper functions (`e()`, `normalize_pnr()`, `status_badge()`)
  - SQL injection immunity
  - XSS output encoding
  - CSRF token issuance, verification, and HTTP header support
  - Session security (HttpOnly, SameSite=Lax, UA fingerprint binding)
  - RBAC permission barriers (`SUPER_ADMIN`, `EVENT_ADMIN`, `PAYMENT_ADMIN`, `ENTRY_MANAGER`)
  - Business rules for gate entry eligibility
  - Input validation (name, mobile, email, UTR, capacity)
  - File upload restrictions (MIME whitelist, randomized filenames)
  - Web server headers (CSP, Permissions-Policy, X-Content-Type-Options)

### 3.2 Full Automated Test Suite (`tests/automated_test.php`)
* **Total Tests:** 179 test cases.
* **Execution:** `php tests/automated_test.php`
* **Coverage:**
  - End-to-end multi-step registration → payment → approval → gate scan flow
  - Atomic database transactions with row-locking (`FOR UPDATE`)
  - Duplicate scan prevention across racing gate scanners
  - Admin audit logging on every sensitive state change
  - PDO emulation settings and error resilience

---

## 4. Manual Test Matrix & Edge Cases

### 4.1 Student Portal (Public)
| Test ID | Scenario | Expected Result | Pass/Fail |
|---|---|---|---|
| STU-01 | Self-registration with valid data | Creates PENDING student record, redirects to `payment.php` | PASS |
| STU-02 | Duplicate PNR registration | Blocked with "College PNR is already registered" | PASS |
| STU-03 | Registration rate limiting (4 submissions in 5 min) | Blocked by rate limiter with 429-style alert | PASS |
| STU-04 | Honeypot trigger (`website` field populated) | Silently rejected with "Spam detected" | PASS |
| STU-05 | Payment submission with 12-digit UTR & screenshot | Stored in `uploads/payments/`, status set to PENDING | PASS |
| STU-06 | Duplicate UTR submitted by different student | Blocked: "This UTR number was already submitted" | PASS |
| STU-07 | Status lookup with PNR + Mobile | Displays accurate timeline and current status | PASS |
| STU-08 | Pass download on approved registration | Generates PNG pass with valid QR and student details | PASS |

### 4.2 Gate Scanner (Volunteers)
| Test ID | Scenario | Expected Result | Pass/Fail |
|---|---|---|---|
| SCN-01 | Volunteer login with valid code + password | Authenticated, redirects to `scanner/scan.php` | PASS |
| SCN-02 | Brute force lock (5 failed logins) | 60-second lockout timer enforced via session | PASS |
| SCN-03 | Camera scan of active, approved pass | Overlays green "ENTRY APPROVED", chime plays | PASS |
| SCN-04 | Duplicate scan of already-entered pass | Overlays red "ENTRY DENIED: PASS ALREADY USED" with first entry details | PASS |
| SCN-05 | Scan of pending / rejected payment pass | Overlays warning "PAYMENT NOT VERIFIED", denies entry | PASS |
| SCN-06 | Manual PNR entry fallback | Correctly verifies and enters student without camera | PASS |
| SCN-07 | Mid-shift CSRF token rotation | Token refreshed on every API response; no 419 errors | PASS |
| SCN-08 | Volunteer session expiration | Displays "SESSION EXPIRED" overlay and redirects to login | PASS |

### 4.3 Admin Console
| Test ID | Scenario | Expected Result | Pass/Fail |
|---|---|---|---|
| ADM-01 | Admin login brute-force protection | 5 failed attempts → 60s lockout | PASS |
| ADM-02 | Payment approval with transaction | Atomically updates payment to APPROVED and student to APPROVED | PASS |
| ADM-03 | Payment rejection with reason | Rejection reason recorded; student status updated to REJECTED | PASS |
| ADM-04 | Manual entry mark and entry reset | Logged to `entry_logs` and `audit_logs` with admin ID | PASS |
| ADM-05 | CSV export of participants and payments | Generates clean CSV with sanitized headers | PASS |
| ADM-06 | Role-based permission enforcement | Restricted roles blocked from unauthorized actions | PASS |

---

## 5. Security & Penetration Testing

* **SQL Injection:** All queries use PDO parameterized statements.
* **Stored & Reflected XSS:** All user output is wrapped in `e()` (`htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`).
* **CSRF:** All mutating forms use `csrf_field()` / `csrf_require()` and `X-CSRF-Token` headers.
* **Upload Safety:**
  - Files are validated using `mime_content_type()` and `getimagesize()`.
  - Stored with random 32-character hexadecimal names.
  - `/uploads/.htaccess` blocks script execution and forces download headers.
* **Directory Traversal:** Config and includes are blocked by root `.htaccess`.
