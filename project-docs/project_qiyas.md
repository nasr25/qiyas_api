---
name: Qiyas Platform Project
description: Enterprise DGA Qiyas compliance platform — Laravel 12 backend + Vue.js 3 frontend
type: project
---
Full enterprise platform at /home/nasr25/Desktop/qiyas/

**Why:** Managing DGA Qiyas annual compliance requirements for government departments.

**Structure:**
- /backend — Laravel 12 API (PHP 8.3, JWT, Spatie RBAC, MySQL 8)
- /frontend — Vue.js 3 SPA (Tailwind CSS, Pinia, vue-i18n AR/EN)
- /project-docs — Architecture documentation

**Current Status:** Phase 1 complete — full foundation, all models, all controllers, all views, migrations, seeders, deployable.

**Key Decisions:**
- JWT via tymon/jwt-auth (not Sanctum) for stateless IIS deployment
- 75 API endpoints across 17 controllers
- 23 Vue.js views + 4 reusable components
- Production build verified (1.84s)
- 5 roles: super-admin, auditor, coordinator, employee, executive
- Default creds: superadmin / ChangeMe123! (force change on login)
- Private file storage (never publicly accessible)
- Full audit logging, document versioning, notifications (email + in-app)
- Full Arabic/English RTL/LTR support

**How to apply:** When continuing development, read completed-modules.md and pending-modules.md first. Backend runs on port 8000, frontend dev on 5173.
