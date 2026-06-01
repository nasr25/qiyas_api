# Qiyas Platform — Deployment Guide

## Prerequisites

### Server Requirements
- Windows Server 2019/2022 or Ubuntu 20.04+
- IIS 10+ (Windows) or Nginx/Apache (Linux)
- PHP 8.3+ with extensions: pdo_mysql, mbstring, openssl, tokenizer, xml, ctype, json, bcmath, fileinfo, zip, gd
- MySQL 8.0+
- Node.js 18+ (for building frontend)
- Composer 2.x

### PHP Extensions (Windows/IIS)
```
extension=pdo_mysql
extension=mbstring
extension=openssl
extension=xml
extension=zip
extension=gd
extension=ldap      ; For AD authentication
```

---

## Database Setup

```sql
CREATE DATABASE qiyas_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'qiyas_user'@'localhost' IDENTIFIED BY 'StrongPassword123!';
GRANT ALL PRIVILEGES ON qiyas_db.* TO 'qiyas_user'@'localhost';
FLUSH PRIVILEGES;
```

---

## Backend Installation

### 1. Clone / Copy Files
```
Copy the /backend folder to: C:\inetpub\wwwroot\qiyas-api\
```

### 2. Install PHP Dependencies
```bash
cd C:\inetpub\wwwroot\qiyas-api
composer install --no-dev --optimize-autoloader
```

### 3. Configure Environment
```bash
copy .env.example .env
```
Edit `.env` and set:
- `APP_URL` = your API URL
- `DB_*` = MySQL credentials
- `JWT_SECRET` = run `php artisan jwt:secret`
- `MAIL_*` = SMTP settings
- `LDAP_*` = Active Directory settings (if using AD auth)

### 4. Run Migrations & Seeders
```bash
php artisan key:generate
php artisan migrate --force
php artisan db:seed --force
php artisan storage:link
```

### 5. Set Permissions
```
Grant IIS_IUSRS write permission to:
  - storage\
  - bootstrap\cache\
```

### 6. Optimize for Production
```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan optimize
```

---

## Frontend Build

### 1. Install Dependencies
```bash
cd /path/to/frontend
npm ci
```

### 2. Configure Environment
```bash
copy .env.production .env.local
```
Set `VITE_API_URL` to your backend API URL.

### 3. Build
```bash
npm run build
```
Output is in `frontend/dist/`

---

## IIS Configuration

### Backend (PHP API) — web.config
```xml
<?xml version="1.0" encoding="UTF-8"?>
<configuration>
    <system.webServer>
        <rewrite>
            <rules>
                <rule name="Laravel API" stopProcessing="true">
                    <match url="^(.*)$" />
                    <conditions>
                        <add input="{REQUEST_FILENAME}" matchType="IsFile" negate="true" />
                        <add input="{REQUEST_FILENAME}" matchType="IsDirectory" negate="true" />
                    </conditions>
                    <action type="Rewrite" url="index.php" />
                </rule>
            </rules>
        </rewrite>
        <httpProtocol>
            <customHeaders>
                <add name="Access-Control-Allow-Origin" value="https://qiyas.yourdomain.local" />
                <add name="Access-Control-Allow-Methods" value="GET, POST, PUT, DELETE, OPTIONS" />
                <add name="Access-Control-Allow-Headers" value="Content-Type, Authorization, Accept-Language" />
            </customHeaders>
        </httpProtocol>
    </system.webServer>
</configuration>
```

### Frontend (Vue SPA) — web.config
```xml
<?xml version="1.0" encoding="UTF-8"?>
<configuration>
    <system.webServer>
        <rewrite>
            <rules>
                <rule name="SPA Routes" stopProcessing="true">
                    <match url="^(?!assets/)(.*)$" />
                    <conditions>
                        <add input="{REQUEST_FILENAME}" matchType="IsFile" negate="true" />
                    </conditions>
                    <action type="Rewrite" url="index.html" />
                </rule>
            </rules>
        </rewrite>
        <staticContent>
            <mimeMap fileExtension=".js" mimeType="application/javascript" />
            <mimeMap fileExtension=".css" mimeType="text/css" />
            <mimeMap fileExtension=".woff2" mimeType="font/woff2" />
        </staticContent>
    </system.webServer>
</configuration>
```

### IIS Sites Setup
1. **API Site**: Point to `backend/public/` — Port 8080 or `/api` path
2. **Frontend Site**: Point to `frontend/dist/` — Port 80/443

---

## Queue Worker (Windows Service)

Install as a Windows service using NSSM:
```bash
nssm install QiyasQueue "C:\php\php.exe"
nssm set QiyasQueue AppParameters "C:\inetpub\wwwroot\qiyas-api\artisan queue:work --sleep=3 --tries=3 --timeout=90"
nssm set QiyasQueue AppDirectory "C:\inetpub\wwwroot\qiyas-api"
nssm start QiyasQueue
```

---

## Task Scheduler (Cron)

Windows Task Scheduler or Linux cron:

**Windows** (every minute):
```
C:\php\php.exe C:\inetpub\wwwroot\qiyas-api\artisan schedule:run
```

**Linux** (crontab -e):
```
* * * * * php /var/www/qiyas-api/artisan schedule:run >> /dev/null 2>&1
```

---

## Backup Guide

### Database Backup
```bash
# Daily backup
mysqldump -u qiyas_user -p qiyas_db > backup_$(date +%Y%m%d).sql

# Windows Task Scheduler
mysqldump -u qiyas_user -p qiyas_db > C:\backups\qiyas_%DATE%.sql
```

### File Storage Backup
```
Backup the entire: backend/storage/app/private/
This contains all uploaded documents.
```

---

## Restore Guide

```bash
# Restore database
mysql -u qiyas_user -p qiyas_db < backup_20260601.sql

# Restore files
# Copy backup of storage/app/private/ back to same location

# Re-run migrations (if schema changed)
php artisan migrate --force

# Clear caches
php artisan cache:clear
php artisan config:clear
php artisan route:clear
```

---

## Default Credentials

| User | Username | Password | Note |
|------|----------|----------|------|
| Super Admin | `superadmin` | `ChangeMe123!` | **Change immediately** |

---

## Health Check

- API: `GET /up` → returns 200
- Frontend: Access the root URL, should show login page

---

## Troubleshooting

| Issue | Solution |
|-------|----------|
| 500 errors | Check `storage/logs/laravel.log` |
| Permission denied | Grant IIS_IUSRS write to `storage/` and `bootstrap/cache/` |
| LDAP not connecting | Verify LDAP_HOST, test with `php artisan tinker` |
| Queue not processing | Restart QiyasQueue service |
| JWT issues | Run `php artisan jwt:secret` and update `.env` |
