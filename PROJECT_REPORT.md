# Residex Manager — Project Report

## 1. Abstract
**Residex Manager** is a hostel/resident management web application built in **PHP (server-side)** with **MySQL** for data storage. The system provides two main portals:
- **Resident Portal**: account login, dashboard, profile management, complaints, announcements, and secure payment flow.
- **Admin Portal**: analytics dashboard, manage residents, rooms, payments, complaints, and announcements.

The application’s objective is to centralize hostel operations (room occupancy, complaint tracking, and payment handling) with role-based access and a simple user interface.

---

## 2. Technologies Used
- **Backend**: PHP (mysqli + prepared statements in multiple places)
- **Database**: MySQL
- **Frontend**: HTML + CSS (single stylesheet: `assets/css/style.css`)
- **Authentication**: `password_hash()` / `password_verify()`
- **Architecture**: Monolithic PHP pages (server-rendered), shared helpers in `includes/config.php`

---

## 3. System Modules (High-Level)

### 3.1 Shared Configuration & Utilities (`includes/config.php`)
Responsibilities include:
- Session start (if not started)
- Database connection helper (`getDB()`)
- Input sanitization (`sanitize()`)
- Redirect helper (`redirect()`)
- Login checks (`isLoggedIn()`, `requireLogin()`)

### 3.2 Resident Portal
Main pages:
- `user/login.php` — Resident login with session handling.
- `user/register.php` — Resident registration with validation and room assignment.
- `user/dashboard.php` — Resident dashboard (room info, recent complaints, announcements, complaint counters).
- `user/complaints.php` — File and manage complaints (resident view).
- `user/announcements.php` — View announcements.
- `user/payments.php` — View payment demands/payments.
- `user/pay.php` — Secure payment verification and receipt flow.
- `user/profile.php` — Resident profile.
- `user/receipt.php` — Receipt display/print for paid transactions.

Resident features observed:
- Pending/inactive account states shown on dashboard.
- Complaint status badges and counts.

### 3.3 Admin Portal
Main pages:
- `admin/login.php` — Admin login.
- `admin/dashboard.php` — Analytics (members, room availability, complaint trends, most reported items).
- `admin/residents.php` — Manage resident accounts.
- `admin/rooms.php` — Manage rooms and occupancy/status.
- `admin/payments.php` — Manage payment demands and payment status.
- `admin/complaints.php` — Manage complaint lifecycle (pending → in progress → resolved/rejected).
- `admin/announcements.php` — Create/view announcements.
- `admin/receipt.php` — Receipt management/lookup.

Admin features observed:
- Monthly complaint trend chart (last 6 months).
- Room occupancy grid with color-coded statuses.
- Analytics metrics computed from DB.

---

## 4. Database Design (ERD Summary)
Entities/tables from `Database/database.sql`:

### 4.1 `rooms`
- `room_number`, `room_type` (double/triple), `floor`, `capacity`
- `occupied` and `status` (available/full/maintenance)

### 4.2 `users`
- Resident identity: `full_name`, `email`, `phone`, `student_id`
- Security: `password`
- Room assignment: `room_id`, `bed_number`
- Personal info: `gender`, `address`, `emergency_contact`
- Status workflow: `status` (active/inactive/pending)
- Optional preferences fields (diet columns appear conditionally)

### 4.3 `complaints`
- Links: `user_id` → `users`, `room_id` → `rooms`
- Complaint content: `complaint_items` (JSON), `description`, `priority`
- Workflow: `status` (pending/in_progress/resolved/rejected), admin notes

### 4.4 `announcements`
- `title`, `content`, `type` (info/warning/urgent)
- `admin_id` (optional association)

### 4.5 `admins`
- `username`, `email`, `password`, `full_name`

### 4.6 `payments`
- Tracks payment records by `user_id`, `amount`, `month`, `year`
- `status` (paid/pending/overdue)
- `paid_at`

> Notes: The payment pages (`user/pay.php`) also reference additional tables such as:
- `payment_demands`
- `payment_transactions`
These may be defined in `demand.sql` and `Database/payment.sql` / `Database/demand.sql`.

---

## 5. Key Workflows (End-to-End)

### 5.1 Registration Flow (`user/register.php`)
1. User submits: personal details, Gmail-only email, student ID format validation.
2. System checks uniqueness: email and student_id.
3. Room availability check: ensures room exists and is not full/maintenance.
4. Assigns bed number using current occupancy.
5. Inserts user with hashed password and sets resident status to **pending**.
6. Updates room occupancy and room status (full if capacity reached).

### 5.2 Resident Login (`user/login.php`)
1. Validate email/password.
2. Reject inactive users.
3. Set session variables and redirect:
   - Dashboard by default
   - Or a stored redirect target if coming from a payment link.

### 5.3 Complaints Workflow
- Resident files complaints with items stored as JSON in `complaint_items`.
- Dashboard shows recent complaints and status counters.
- Admin manages statuses (pending/in_progress/resolved/rejected).

### 5.4 Secure Payment Flow (`user/pay.php`)
- Uses a token-based link (QR scan style).
- Validates token and ensures demand is not cancelled/paid.
- On POST confirmation:
  - Verifies token and a SHA-256 integrity hash signature (token + user_id + amount + admin_id + created_at).
  - Inserts a record into `payment_transactions`.
  - Updates the demand status to `paid`.
- After success, user sees receipt data and a button to print/download via `user/receipt.php`.

---

## 6. Screens / Pages Description

### Resident Screens
- **Login**: `user/login.php`
- **Register**: `user/register.php`
- **Dashboard**: `user/dashboard.php` (room info, stats, recent complaints)
- **Complaints**: `user/complaints.php`
- **Announcements**: `user/announcements.php`
- **Payments List**: `user/payments.php`
- **Pay Now**: `user/pay.php` (token validation + confirm)
- **Receipt**: `user/receipt.php`
- **Profile**: `user/profile.php`

### Admin Screens
- **Admin Login**: `admin/login.php`
- **Admin Dashboard**: `admin/dashboard.php` (analytics + charts)
- **Residents Management**: `admin/residents.php`
- **Rooms Management**: `admin/rooms.php`
- **Complaints Management**: `admin/complaints.php`
- **Announcements Management**: `admin/announcements.php`
- **Payments Management**: `admin/payments.php`
- **Receipt**: `admin/receipt.php`

---

## 7. Security & Validation
Observed security measures:
- Passwords are hashed with `password_hash()` and verified with `password_verify()`.
- Token-based payment link uses SHA-256 integrity checks.
- Admin/user session-based authorization with `requireLogin()`.
- Input sanitization via `sanitize()` and prepared statements in key login flows.

---

## 8. Testing & Verification Plan
Suggested test cases:

### 8.1 Authentication
- Register → login with correct credentials.
- Login with wrong password.
- Login for inactive user (should show deactivated message).
- Session protection: direct access to protected pages should redirect to login.

### 8.2 Registration & Room Allocation
- Register with existing email/student ID.
- Register with invalid student ID format.
- Register when selected room is maintenance/full.
- Verify room `occupied` increment and status transition to `full`.

### 8.3 Complaints
- File complaint and verify it appears on dashboard.
- Verify complaint item JSON is rendered correctly.
- Admin updates status and resident dashboard counters update.

### 8.4 Payments
- Open `user/pay.php` with invalid/expired token.
- Open with already paid demand → receipt shown.
- Confirm payment with correct hash → transaction inserted + demand updated.
- Attempt payment with mismatched token/hash → rejected with security error.

---

## 9. Conclusion
Residex Manager provides a complete hostel management solution with role-based dashboards, complaint tracking, room occupancy management, announcements, and a secure payment confirmation mechanism. The system uses a practical MySQL schema with workflow-driven statuses (pending/active for residents, and multi-stage statuses for complaints and payments).

---

## References
- `Database/database.sql` (schema)
- Project source pages under `admin/` and `user/`
- Shared helpers in `includes/config.php`

