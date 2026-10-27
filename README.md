# UTHM Secure Course Portal

Final setup guide for first-time users.

This project is a **PHP + MySQL web security system** for BIS20303 Web Security. It is hosted using **Laravel Herd** with a `.test` local domain.

> Important: This is **not a Laravel framework project**. Laravel Herd is only used as the local PHP web server.

---

## 1. Final Hosting Method

The final website must be opened using Laravel Herd:

```text
https://fsktm-course-system.test
```

---

## 2. Required Software

Install these first:

```text
1. Laravel Herd for Windows
2. XAMPP
3. Google Chrome or Microsoft Edge
4. Visual Studio Code
```

Usage:

```text
Laravel Herd → runs the website
XAMPP MySQL → runs the database
```

In XAMPP Control Panel:

```text
Apache: STOP
MySQL: START
```

Apache should be stopped because Laravel Herd already serves the website.

---

## 3. Project Folder Location

Copy the project folder to your Herd directory.

Example:

```text
C:\Users\<YOUR_WINDOWS_USERNAME>\Herd\fsktm-course-system
```

Example:

```text
C:\Users\annsi\Herd\fsktm-course-system
```

The folder should contain:

```text
fsktm-course-system
├── api
├── assets
├── config
├── includes
├── pages
├── uploads
├── index.php
├── fsktm_courses.sql
└── README.md
```

---

## 4. Start the Website with Laravel Herd

Open Laravel Herd.

Make sure the project appears as:

```text
fsktm-course-system.test
```

Open:

```text
http://fsktm-course-system.test
```

Then enable HTTPS in Herd (Lock Button).

You can also secure it using PowerShell:

```powershell
cd $env:USERPROFILE\Herd\fsktm-course-system
herd secure
```

After securing, open:

```text
https://fsktm-course-system.test
```

Expected result:

```text
The website opens using HTTPS.
```

---

## 5. Database Setup

Start MySQL from XAMPP:

```text
XAMPP Control Panel → MySQL → Start
```

The database name must be:

```text
fsktm_courses
```

### Option A: Import using phpMyAdmin

If you want to use phpMyAdmin, you may need to temporarily start XAMPP Apache. If port conflict happens, stop Herd temporarily or change XAMPP Apache to another port.

Open:

```text
http://localhost/phpmyadmin
```

Then:

```text
1. Create database: fsktm_courses
2. Import fsktm_courses.sql
```

### Option B: Import using Command Prompt

Open Command Prompt and run:

```cmd
C:\xampp\mysql\bin\mysql.exe -u root -e "CREATE DATABASE IF NOT EXISTS fsktm_courses CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

Then import the SQL file:

```cmd
C:\xampp\mysql\bin\mysql.exe -u root fsktm_courses < "%USERPROFILE%\Herd\fsktm-course-system\fsktm_courses.sql"
```

If your MySQL root account has a password, use:

```cmd
C:\xampp\mysql\bin\mysql.exe -u root -p fsktm_courses < "%USERPROFILE%\Herd\fsktm-course-system\fsktm_courses.sql"
```

After import, confirm these tables exist:

```text
users
courses
registrations
login_attempt_locks
audit_logs
```

---

## 6. Configure config.php

Open:

```text
config\config.php
```

Make sure the base URL uses Herd:

```php
define('BASE_URL', 'https://fsktm-course-system.test');
```

Make sure session timeout is normal:

```php
define('SESSION_TIMEOUT_SECONDS', 30 * 60);
```

Database settings normally use XAMPP MySQL:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'fsktm_courses');
define('DB_USER', 'root');
define('DB_PASS', '');
```

If database connection fails, try:

```php
define('DB_HOST', '127.0.0.1');
```

---

## 7. Google reCAPTCHA Setup

The login page uses Google reCAPTCHA v2.

Do not show the secret key in screenshots or reports.

If CAPTCHA shows:

```text
ERROR for site owner: Invalid domain for site key
```

fix the domain setting and press:

```text
Ctrl + F5
```

---

## 8. Main Website URLs

Use these Herd URLs:

```text
Home:
https://fsktm-course-system.test

Login:
https://fsktm-course-system.test/pages/auth/login.php

Register:
https://fsktm-course-system.test/pages/auth/register.php

Profile:
https://fsktm-course-system.test/pages/profile.php

Admin Dashboard:
https://fsktm-course-system.test/pages/admin/dashboard.php

Manage Users:
https://fsktm-course-system.test/pages/admin/manage-users.php

Manage Courses:
https://fsktm-course-system.test/pages/admin/manage-courses.php
```

---

## 9. Security Features Included

The system includes:

```text
1. User registration with required fields.
2. Input sanitization.
3. Server-side data validation.
4. Matric number validation.
5. Blocking matric year 2020 or earlier.
6. Strong password policy.
7. Password hashing using password_hash().
8. Password verification using password_verify().
9. Google reCAPTCHA v2.
10. Generic login error message.
11. Account lockout.
12. Browser/client login attempt lockout.
13. Prepared statements for SQL injection prevention.
14. Output encoding for XSS prevention.
15. Session regeneration.
16. Session timeout.
17. Secure logout and session destruction.
18. Role-based access control.
19. Admin-only page protection.
20. Admin self-delete/self-disable protection.
21. HTTPS hosting through Laravel Herd.
22. Audit logging.
23. Admin user management.
24. Admin course management.
```

---

## 10. First-Time Test Checklist

After setup, test these:

```text
[ ] Website opens at https://fsktm-course-system.test
[ ] Login page loads correctly
[ ] Google reCAPTCHA appears
[ ] Register page loads correctly
[ ] Database connection works
[ ] User can register
[ ] User can log in
[ ] User can access profile page
[ ] Admin can access dashboard
[ ] Student cannot access admin dashboard
[ ] Manage Users page works
[ ] Manage Courses page works
[ ] Logout redirects back to login
```

---

## 11. Reset Lockout and Testing Data

Use this before demo or before sending a clean copy to another teammate.

Run in MySQL:

```sql
TRUNCATE TABLE login_attempt_locks;

UPDATE users
SET failed_login_attempts = 0,
    locked_until = NULL;
```

This clears:

```text
1. Browser/client lockout records.
2. Failed login counters.
3. Account lock timestamps.
```

Optional, only after report screenshots are done:

```sql
TRUNCATE TABLE audit_logs;
```

---

## 12. Remove Test XSS Payload

If an XSS payload was inserted during testing, remove it before demo.

Run:

```sql
UPDATE courses
SET prerequisites = NULL
WHERE prerequisites LIKE '%script%';

UPDATE courses
SET description = REPLACE(description, '<SCRIPT>ALERT(''XSS ATTACK'');</SCRIPT>', '')
WHERE description LIKE '%SCRIPT%';
```

You can also remove it manually from the Manage Courses page.

---

## 13. Clear Browser Cookies and Cache

For Chrome or Edge:

```text
1. Open the website.
2. Press F12.
3. Go to Application.
4. Go to Storage.
5. Click Clear site data.
```

Or:

```text
Settings → Privacy and security → Clear browsing data
```

Select:

```text
Cookies and other site data
Cached images and files
```

Then press:

```text
Ctrl + F5
```

---

## 14. Clear PHP Sessions Used by Herd

Open PowerShell and run:

```powershell
herd php -i | findstr /I "session.save_path"
```

Open the folder shown in the result.

Delete files that start with:

```text
sess_
```

Then restart Herd.

---

## 15. Troubleshooting

### Website does not open

Check:

```text
1. Laravel Herd is running.
2. Project folder is inside the Herd directory.
3. URL is https://fsktm-course-system.test.
4. XAMPP Apache is stopped.
5. No other application is using port 80 or 443.
```

---

### Database connection failed

Check:

```text
1. XAMPP MySQL is running.
2. Database fsktm_courses exists.
3. fsktm_courses.sql was imported.
4. config.php database name, user and password are correct.
5. Try DB_HOST = 127.0.0.1.
```

---

### CAPTCHA invalid domain

Fix Google reCAPTCHA allowed domains:

```text
localhost
fsktm-course-system.test
```

Do not use:

```text
https://fsktm-course-system.test
```

---

### CSS or JavaScript looks old

Do:

```text
F12 → Application → Storage → Clear site data
Ctrl + F5
```

---

### User is still locked out

Run:

```sql
TRUNCATE TABLE login_attempt_locks;

UPDATE users
SET failed_login_attempts = 0,
    locked_until = NULL;
```

Then clear browser site data.

---

## 16. Demo Flow

Suggested final demo order:

```text
1. Show HTTPS Herd URL.
2. Show registration fields.
3. Show weak password rejection.
4. Show matric year 2020 blocking.
5. Show valid registration.
6. Show Google reCAPTCHA on login.
7. Show SQL injection blocked.
8. Show XSS payload displayed as plain text.
9. Show account lockout.
10. Show browser/client lockout database proof.
11. Show session timeout.
12. Show logout session destruction.
13. Show student blocked from admin page.
14. Show admin dashboard.
15. Show Manage Users.
16. Show Manage Courses.
17. Show audit log.
```

---

## 17. Final Reminder

Before submitting or sharing:

```text
1. Use https://fsktm-course-system.test.
2. Make sure config.php uses the Herd BASE_URL.
3. Add fsktm-course-system.test to Google reCAPTCHA.
4. Reset lockout records.
5. Remove XSS testing payload.
6. Restore SESSION_TIMEOUT_SECONDS to 30 * 60.
7. Clear browser site data.
8. Export a clean fsktm_courses.sql if sharing the database.
```
