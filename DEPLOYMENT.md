# Fresher Party System — Production Deployment Guide

## 1. Production Requirements
* **Web Server:** Apache 2.4+ (with `mod_rewrite`, `mod_headers`) or Nginx.
* **PHP Version:** PHP 8.0, 8.1, 8.2, or 8.3.
* **PHP Extensions:** `pdo_mysql`, `session`, `fileinfo`, `gd` (or `getimagesize`), `mbstring`, `json`.
* **Database:** MySQL 5.7+ or MariaDB 10.3+ (InnoDB engine is mandatory for row-level locking).
* **SSL / HTTPS:** **Mandatory** for production. Modern mobile browsers block camera access (`getUserMedia`) on insecure HTTP origins.

---

## 2. Pre-Deployment Configuration

### Step 1: Set Up MySQL Database
1. In your hosting control panel (InfinityFree, cPanel, etc.), create a new MySQL database (e.g. `freshers_db`).
2. Create a database user with full privileges (`SELECT`, `INSERT`, `UPDATE`, `DELETE`, `CREATE`, `ALTER`, `INDEX`).
3. Note your database host (e.g. `sql100.epizy.com` or `localhost`), database name, username, and password.

### Step 2: Configure database credentials
Copy `config/secrets.example.php` to `config/secrets.php` on the server and enter the
database host, name, user, password and port. The secrets file is excluded from Git.
Alternatively, set `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` and `DB_PORT` as server
environment variables. Never commit real credentials.

---

## 3. Database Migration & Initialization

### Option A: Via phpMyAdmin (Recommended for Shared Hosting)
1. Open phpMyAdmin in your hosting control panel.
2. Select your newly created database.
3. Click the **Import** tab.
4. Choose the file `database.sql` from your project root.
5. Click **Import / Go**.

### Option B: Via `install.php` Web Wizard
1. Upload all files to your server.
2. Navigate to `https://your-domain.com/install.php` in your browser.
3. Complete the 4-step setup wizard:
   - Step 1: System requirements verification.
   - Step 2: Database connectivity and table creation.
   - Step 3: Creation of the primary Super Admin account.
   - Step 4: Installation lock creation (`config/installed.lock`).

---

## 4. HTTPS & SSL Enforcement

Modern smartphones require HTTPS for gate scanner camera access.
After installing your free SSL certificate (via InfinityFree SSL, Let's Encrypt, or Cloudflare):
1. Open `.htaccess` in your project root.
2. Uncomment the HTTPS redirect block:
```apache
<IfModule mod_rewrite.c>
  RewriteEngine On
  RewriteCond %{HTTPS} !=on
  RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
</IfModule>
```

---

## 5. Security Checklist (Post-Deployment)

- [ ] **Delete or Lock `install.php`:**
  Ensure `config/installed.lock` exists, or delete `install.php` entirely:
  ```bash
  rm install.php
  ```
- [ ] **Verify Directory Protection:**
  Try visiting `https://your-domain.com/config/` and `https://your-domain.com/includes/` in your browser.
  Both should return a `404 Not Found` or `403 Forbidden`.
- [ ] **Verify Script Execution Block in Uploads:**
  Try requesting `https://your-domain.com/uploads/.htaccess`. It should be denied.
- [ ] **Run Automated Test Suite:**
  Run regression tests to verify environment readiness:
  ```bash
  php tests/regression_test.php
  php tests/automated_test.php
  ```
- [ ] **Change Default Admin Password:**
  Log in at `/admin/login.php` and update the super admin password.
- [ ] **Keep private data out of Git:**
  Do not commit production SQL exports, student/payment uploads, credentials or generated
  installation locks. Rotate credentials immediately if they were ever committed or shared.
- [ ] **Configure Event Settings:**
  Go to `/admin/settings.php` and set:
  - Event Name & College Name
  - Registration Fee
  - Multiple UPI IDs for payment rotation
  - Event Date & Gate Entry Status

---

## 6. Event Day Operations & Monitoring

1. **Gate Volunteer Accounts:**
   Pre-create volunteer accounts in `/admin/volunteers.php` for each entry gate (e.g. `V001` for Gate 1, `V002` for Gate 2).
2. **Scanner App Deployment:**
   Gate volunteers open `https://your-domain.com/scanner/` on their mobile smartphones and log in.
3. **Manual Verification Fallback:**
   If a student's phone screen is cracked or camera cannot focus, volunteers tap **"Enter PNR number"** on the scanner interface to mark attendance instantly.
4. **Live Attendance Monitoring:**
   Admins can monitor live scan counts and gate traffic in real time from `/admin/dashboard.php` and `/admin/entries.php`.
