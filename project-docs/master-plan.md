# Qiyas Platform - Master Plan

## Project Overview
Enterprise platform for managing and tracking Digital Government Authority (DGA) Qiyas requirements.

## Technology Stack
- **Backend**: Laravel 12 (REST API)
- **Frontend**: Vue.js 3 (SPA)
- **Database**: MySQL 8+
- **Authentication**: Active Directory (LDAP) + Local Users
- **Authorization**: Spatie Permission (RBAC)
- **Deployment**: On-Premises / IIS

## Project Structure
```
/qiyas
  /backend          → Laravel 12 API
  /frontend         → Vue.js 3 SPA
  /project-docs     → Architecture & documentation
```

## Implementation Phases

### Phase 1: Foundation ✅
- Project documentation
- Backend Laravel setup
- Frontend Vue.js setup

### Phase 2: Database & Models ✅
- Migrations
- Models
- Relationships
- Seeders

### Phase 3: Authentication ✅
- AD/LDAP authentication
- Local user authentication
- JWT tokens

### Phase 4: Authorization ✅
- RBAC with Spatie Permission
- Policies
- Middleware

### Phase 5: Core Features ✅
- Assessment Cycles
- Departments
- Standards
- Evidence Requirements
- Document Management
- Document Versioning

### Phase 6: Workflows ✅
- Document submission workflow
- Review/Approval workflow
- Extension requests
- Comments & discussions

### Phase 7: Notifications ✅
- In-app notifications
- Email notifications
- Scheduled reminders

### Phase 8: Dashboards & Reports ✅
- Employee dashboard
- Coordinator dashboard
- Auditor dashboard
- Executive dashboard
- Export (Excel/PDF)

### Phase 9: Administration ✅
- User management
- System settings
- Branding
- Audit logs

### Phase 10: Frontend SPA ✅
- All Vue.js pages
- Components
- RTL/LTR support
- Dark mode ready

## User Roles
1. **Super Admin** - Full system control
2. **Auditor** - Document review, approval/rejection
3. **Department Coordinator** - Department management
4. **Employee** - Document upload and management
5. **Executive Viewer** - Read-only executive dashboards

## Key Business Rules
1. Only one Active assessment cycle at a time
2. New cycle cannot activate until current is closed
3. Closed cycles are read-only
4. Files are never publicly accessible
5. Documents support versioning (never overwrite)
6. Extension requests require auditor approval
