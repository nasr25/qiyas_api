# Architecture Assumptions

## Authentication
- **JWT via tymon/jwt-auth** chosen over Laravel Sanctum for stateless API compatibility with IIS deployment
- LDAP users must be **imported by Super Admin** before they can log in — self-service AD login not implemented (security requirement for controlled access)
- LDAP extension (php_ldap) must be enabled on the server for AD authentication to work

## File Storage
- Files stored in `storage/app/private/` — never publicly accessible
- Temporary download URLs generated per-request (5-minute expiry)
- File integrity verified via SHA-256 hash on upload
- Maximum file size: 20 MB (configurable in settings)
- Allowed types: pdf, docx, xlsx, pptx, zip (configurable in settings)

## Assessment Cycles
- Only **one active cycle** at any time — business rule enforced at service layer
- Closed cycles are **fully read-only** — no uploads, submissions, or edits
- "Copy from previous cycle" copies standards, requirements, and department assignments but **resets due dates and document records**

## Database
- MySQL 8+ required for production (utf8mb4 charset for full Arabic support)
- SQLite used for local development/testing
- All timestamps stored in UTC; displayed in `Asia/Riyadh` timezone

## Notifications
- Email requires SMTP configuration in `.env` or Settings panel
- In-app notifications polled every 30 seconds
- Queue workers required for async email delivery (`queue:work`)

## Deployment
- IIS deployment assumed with URL Rewrite module installed
- Frontend served as static SPA from `dist/` folder
- Backend served from `public/` folder
- Separate IIS sites or URL paths for API vs Frontend

## Security
- CORS configured to allow only the frontend origin (`FRONTEND_URL` in .env)
- File paths never exposed in API responses
- JWT tokens expire after 24 hours (configurable via `JWT_TTL`)
- All sensitive actions logged in `audit_logs` table
- Super Admin bypasses all role/permission checks

## Localization
- Default locale: Arabic (RTL)
- English fully supported
- Date/number formatting follows locale setting
- Government Arabic font: IBM Plex Sans Arabic

## Future Modules
- Database designed with modular architecture in mind
- `model_type`/`model_id` polymorphic relationships allow any future module to use comments, audit logs, notifications
- Permission table pre-populated with extensible naming convention (e.g., `risk.view`, `audit.approve`)
