# Fresher Party 2026 — Data Flow & Architecture Documentation

This document describes the end-to-end data flow, system architecture, security boundaries, and lifecycle states for the Fresher Party Event Management System.

---

## 1. High-Level Data Flow Diagram

The browser never decides whether a payment is approved or an entry is valid. Every state transition is verified server-side with atomic locks in MySQL.

```mermaid
flowchart TD
    subgraph Clients["Client Layer (Mobile & Desktop)"]
        Student["📱 Student Mobile Browser"]
        Admin["💻 Admin Console Browser"]
        Volunteer["📷 Gate Volunteer Scanner"]
    end

    subgraph Server["Backend Layer (Apache & PHP 8)"]
        Router["App Core & Routing"]
        Auth["Session & CSRF Guard"]
        UploadHandler["Secure File Processor (MIME / Rename)"]
        ValidationEngine["Validation & Business Logic"]
        AtomicLock["Atomic Database Transaction Lock"]
    end

    subgraph Storage["Persistence Layer"]
        MySQL[("🗄️ MySQL Database<br/>(Students, Payments, Passes, Audit, Entry Logs)")]
        Uploads[("📁 Uploads Storage<br/>(Execution Blocked via .htaccess)")]
    end

    %% Student flow
    Student -->|"1. Submit Profile + PNR + Photo"| Router
    Router --> Auth --> ValidationEngine
    ValidationEngine -->|"Store Student (PENDING)"| MySQL
    ValidationEngine -->|"Store Student Photo"| Uploads
    Router -->|"2. Display Configured UPI QR & Amount"| Student

    Student -->|"3. Submit UTR & Payment Screenshot"| UploadHandler
    UploadHandler -->|"Generate Random Name & Store Image"| Uploads
    UploadHandler -->|"Record Payment (PENDING)"| MySQL

    %% Admin flow
    Admin -->|"4. Review Screenshot & UTR"| Router
    Router --> Auth --> ValidationEngine
    ValidationEngine -->|"5. Update Payment (APPROVED/REJECTED) + Log Audit"| MySQL

    %% Student Pass flow
    Student -->|"6. Query Status / Pass via PNR"| Router
    Router --> ValidationEngine
    ValidationEngine -->|"Fetch Approved Pass & QR Token"| MySQL
    Router -->|"7. Render Secure Digital QR Pass"| Student

    %% Volunteer Entry flow
    Volunteer -->|"8. Scan QR Token or Enter PNR"| Router
    Router --> Auth --> AtomicLock
    AtomicLock -->|"9. Atomic Guard: UPDATE ... WHERE entry_status='NOT_ENTERED'"| MySQL
    MySQL -->|"Return affected rows (1 = Success, 0 = Duplicate)"| AtomicLock
    AtomicLock -->|"10. Result: ENTRY APPROVED / ALREADY ENTERED"| Volunteer

    %% Reporting & Monitoring
    Admin -->|"11. Real-time Dashboard, Entry Monitor & CSV Exports"| Router
    Router --> MySQL
```

---

## 2. End-to-End Sequence Diagram

This sequence diagram illustrates the chronological steps from the student's initial registration through to the final gate entry verification.

```mermaid
sequenceDiagram
    autonumber
    actor Student as 📱 Student
    participant Web as ⚙️ Web Application (PHP)
    participant DB as 🗄️ MySQL Database
    actor Admin as 👨‍💼 Admin
    actor Volunteer as 👮 Gate Volunteer

    Note over Student,Web: Step 1: Registration
    Student->>Web: Submits Registration (PNR, Name, Course, Contact, Photo)
    Web->>DB: Validates unique PNR, stores student (Payment Status: PENDING)
    Web-->>Student: Displays UPI Payment details (QR Code, UPI ID, Amount)

    Note over Student,Web: Step 2: Payment Verification Submission
    Student->>Web: Uploads Payment Proof Screenshot & UTR Reference Number
    Web->>Web: Validates image MIME type, generates sanitized random filename
    Web->>DB: Saves payment record (Status: PENDING)
    Web-->>Student: Status confirmation: "Payment Under Verification"

    Note over Admin,Web: Step 3: Admin Review & Approval
    Admin->>Web: Opens Admin Dashboard > Pending Payments
    Web->>DB: Fetches pending payments with student details & UTR
    Web-->>Admin: Displays screenshot preview & UTR details
    Admin->>Web: Clicks "Approve Payment"
    Web->>DB: Updates Payment = APPROVED, Pass = ACTIVE, writes Audit Log
    Web-->>Admin: Success alert & live counter update

    Note over Student,Web: Step 4: Digital Pass Issuance
    Student->>Web: Accesses pass.php?pnr=XXXXX (or status tracker)
    Web->>DB: Verifies payment approval & generates/fetches unique QR token
    Web-->>Student: Renders Digital Pass with dynamic QR code & download button

    Note over Volunteer,Web: Step 5: Gate Entry Verification
    Student->>Volunteer: Presents Digital Pass at College Entrance Gate
    Volunteer->>Web: Scans Pass QR Code (or inputs PNR manually)
    Web->>DB: BEGIN TRANSACTION -> Checks entry_status = 'NOT_ENTERED'
    alt Valid & First Entry
        Web->>DB: UPDATE students SET entry_status='ENTERED', entry_time=NOW()
        Web->>DB: INSERT INTO entry_logs (gate, method, volunteer_id) -> COMMIT
        Web-->>Volunteer: 🟢 GREEN SCREEN: ENTRY APPROVED (Student Details Shown)
    else Already Scanned
        Web->>DB: ROLLBACK
        Web-->>Volunteer: 🔴 RED SCREEN: ENTRY DENIED - PASS ALREADY USED (Shows first entry time & gate)
    end
```

---

## 3. Student & Pass Lifecycle State Machine

```mermaid
stateDiagram-v2
    [*] --> REGISTERED: Student submits form with PNR
    REGISTERED --> PAYMENT_SUBMITTED: Uploads UTR & Screenshot
    PAYMENT_SUBMITTED --> PAYMENT_REJECTED: Admin rejects (Reason logged)
    PAYMENT_REJECTED --> PAYMENT_SUBMITTED: Student re-submits corrected proof
    PAYMENT_SUBMITTED --> PASS_ACTIVE: Admin approves payment
    PASS_ACTIVE --> PASS_DISABLED: Admin temporarily disables pass
    PASS_DISABLED --> PASS_ACTIVE: Admin re-enables pass
    PASS_ACTIVE --> ENTERED: First gate scan (Atomic lock succeeds)
    ENTERED --> [*]: Event entry completed
```

---

## 4. Security & Data Boundaries

| Boundary | Storage / Target | Security Enforcements |
|---|---|---|
| **Student Credentials & Identity** | MySQL (`students` table) | The College PNR is the universal identifier. Validated with strict regular expressions (`^[0-9]{10,15}$`) to prevent injection. |
| **Pass Tokens** | MySQL (`students.qr_token`) | Cryptographically secure 128-bit random tokens (`random_bytes(16)`). The QR code holds only this opaque token; no personal student data is leaked into the QR payload. |
| **Payment & Profile Uploads** | File System (`/uploads/`) | Files are validated by MIME sniffing (`finfo`), size capped (4 MB), assigned pseudo-random UUID filenames, and executed-script protection is enforced by `.htaccess` (`SetHandler default-handler`, `php_flag engine off`). |
| **Admin & Volunteer Authentication** | Session (`$_SESSION['FPSESS']`) | Bcrypt (`password_hash`), CSRF token verification per request, session hijacking protection (`SameSite=Lax`, `HttpOnly`, User-Agent validation). |
| **Gate Entry Concurrency** | MySQL Row-Level Transaction | Guaranteed single entry under high concurrency using atomic SQL queries (`UPDATE students SET entry_status='ENTERED' WHERE id=? AND entry_status='NOT_ENTERED'`). Simultaneous gate scans cannot produce duplicate entries. |
