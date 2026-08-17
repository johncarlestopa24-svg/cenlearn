
# CenLearn — Deployment Guide
## Live URL: https://cenlearn.bccbsis.com/

---

## Step 1 — Upload Files via FileZilla

Connect with:
- Host: ftp.bccbsis.com
- Port: 21
- Username: (your FTP username)
- Password: (your FTP password)

Upload the entire contents of the `system/` folder into `public_html/`

Your server structure should look like:
```
public_html/
  index.php
  proxy.php
  teacher_dashboard.php
  student_dashboard.php
  class_view.php
  ... (all other .php files)
  includes/
    conn.php
    session.php
    db_config.php   ← CREATE THIS MANUALLY (see Step 2)
  dist/
  bower_components/
  uploads/          ← must be writable (chmod 755)
```

---

## Step 2 — Create db_config.php on the Server

In FileZilla or Hostinger File Manager, create:
`public_html/includes/db_config.php`

Content:
```php
<?php
define('DB_HOST', 'localhost');
define('DB_USER', 'u520834156_usrCenLrn');
define('DB_PASS', 'YOUR_DB_PASSWORD_HERE');
define('DB_NAME', 'u520834156_dbCenLearn26');
```

DO NOT upload this file from your local machine.
DO NOT commit this file to Git.

---

## Step 3 — Import the Database

1. Go to: https://auth-db1322.hstgr.io
2. Select database: u520834156_dbCenLearn26
3. Click Import tab
4. Upload: database/cenlearn_db.sql
5. Click Go / Import

---

## Step 4 — Set uploads folder permissions

In FileZilla, right-click `public_html/uploads/` → File Permissions → 755

---

## Step 5 — Test

Visit https://cenlearn.bccbsis.com/
Login with: TEMP-TEACHER-001 / teacher123
