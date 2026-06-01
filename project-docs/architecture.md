# Qiyas Platform - Architecture

## System Architecture

```
┌─────────────────────────────────────────────────────┐
│                    IIS Web Server                    │
├──────────────────────┬──────────────────────────────┤
│   Vue.js 3 SPA       │      Laravel 12 API          │
│   /frontend/dist     │      /backend/public         │
│   Port: 80/443       │      Port: 8080 (or same)    │
└──────────────────────┴──────────────────────────────┘
                              │
                    ┌─────────┴──────────┐
                    │    MySQL 8+        │
                    │    Database        │
                    └─────────┬──────────┘
                              │
              ┌───────────────┴───────────────┐
              │         Active Directory       │
              │         LDAP Server            │
              └───────────────────────────────┘
```

## Backend Architecture (Laravel 12)

```
backend/
├── app/
│   ├── Console/
│   ├── Events/
│   ├── Exceptions/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Api/
│   │   │   │   ├── Auth/
│   │   │   │   ├── Admin/
│   │   │   │   ├── Cycles/
│   │   │   │   ├── Departments/
│   │   │   │   ├── Standards/
│   │   │   │   ├── Documents/
│   │   │   │   ├── Auditor/
│   │   │   │   ├── Reports/
│   │   │   │   └── Notifications/
│   │   ├── Middleware/
│   │   ├── Requests/
│   │   └── Resources/
│   ├── Jobs/
│   ├── Listeners/
│   ├── Models/
│   ├── Notifications/
│   ├── Policies/
│   ├── Repositories/
│   └── Services/
│       ├── AuthService.php
│       ├── LdapService.php
│       ├── DocumentService.php
│       ├── CycleService.php
│       ├── NotificationService.php
│       └── ReportService.php
├── database/
│   ├── migrations/
│   └── seeders/
└── routes/
    └── api.php
```

## Frontend Architecture (Vue.js 3)

```
frontend/
├── src/
│   ├── assets/
│   ├── components/
│   │   ├── common/
│   │   ├── charts/
│   │   ├── forms/
│   │   └── layout/
│   ├── composables/
│   ├── layouts/
│   ├── locales/
│   │   ├── ar.json
│   │   └── en.json
│   ├── plugins/
│   ├── router/
│   ├── services/
│   ├── stores/
│   │   ├── auth.js
│   │   ├── app.js
│   │   ├── notifications.js
│   │   └── ui.js
│   └── views/
│       ├── auth/
│       ├── admin/
│       ├── cycles/
│       ├── departments/
│       ├── standards/
│       ├── documents/
│       ├── auditor/
│       ├── dashboards/
│       └── reports/
```

## API Design Pattern

- RESTful API
- JSON responses
- JWT Bearer token authentication
- API versioning: `/api/v1/`
- Standard response format:
```json
{
  "success": true,
  "data": {},
  "message": "...",
  "errors": {}
}
```

## Security Architecture

1. **Authentication**: JWT via Laravel Sanctum
2. **Authorization**: Spatie Permission RBAC
3. **File Security**: Private storage, signed URLs
4. **Input Validation**: Form Request classes
5. **SQL Injection**: Eloquent ORM parameterized queries
6. **XSS**: Vue.js escaping + CSP headers
7. **CSRF**: Sanctum SPA protection
8. **Rate Limiting**: API throttle middleware
9. **Audit Logging**: All sensitive actions logged

## Modular Design for Future Expansion

The platform is designed as a modular system. Future modules:
- Risk Management
- Internal Audit
- Compliance Management
- Policy Management
- Strategic Objectives
- KPI Management
- Digital Transformation Initiatives
