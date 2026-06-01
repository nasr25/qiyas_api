# Permissions Matrix

## Roles
| Role | Description |
|------|-------------|
| super-admin | Full system access |
| auditor | Document review, approve/reject |
| coordinator | Department management |
| employee | Document upload/submit |
| executive | Read-only executive dashboards |

## Permissions by Feature

| Permission | super-admin | auditor | coordinator | employee | executive |
|-----------|-------------|---------|-------------|----------|-----------|
| **Users** |||||
| users.view | ✅ | ❌ | ❌ | ❌ | ❌ |
| users.create | ✅ | ❌ | ❌ | ❌ | ❌ |
| users.edit | ✅ | ❌ | ❌ | ❌ | ❌ |
| users.import-ldap | ✅ | ❌ | ❌ | ❌ | ❌ |
| **Departments** |||||
| departments.view | ✅ | ✅ | ✅ | ❌ | ✅ |
| departments.create | ✅ | ❌ | ❌ | ❌ | ❌ |
| departments.edit | ✅ | ❌ | ❌ | ❌ | ❌ |
| departments.delete | ✅ | ❌ | ❌ | ❌ | ❌ |
| **Cycles** |||||
| cycles.view | ✅ | ✅ | ✅ | ✅ | ✅ |
| cycles.create | ✅ | ❌ | ❌ | ❌ | ❌ |
| cycles.activate | ✅ | ❌ | ❌ | ❌ | ❌ |
| cycles.close | ✅ | ❌ | ❌ | ❌ | ❌ |
| cycles.archive | ✅ | ❌ | ❌ | ❌ | ❌ |
| **Standards** |||||
| standards.view | ✅ | ✅ | ✅ | ✅ | ✅ |
| standards.create | ✅ | ❌ | ❌ | ❌ | ❌ |
| standards.edit | ✅ | ❌ | ❌ | ❌ | ❌ |
| standards.delete | ✅ | ❌ | ❌ | ❌ | ❌ |
| **Documents** |||||
| documents.view | ✅ | ✅ | ✅ | ✅ | ✅ |
| documents.create | ✅ | ❌ | ✅ | ✅ | ❌ |
| documents.upload | ✅ | ❌ | ✅ | ✅ | ❌ |
| documents.submit | ✅ | ❌ | ✅ | ✅ | ❌ |
| documents.approve | ✅ | ✅ | ❌ | ❌ | ❌ |
| documents.reject | ✅ | ✅ | ❌ | ❌ | ❌ |
| documents.download | ✅ | ✅ | ✅ | ✅ | ✅ |
| **Extensions** |||||
| extensions.create | ✅ | ❌ | ✅ | ✅ | ❌ |
| extensions.approve | ✅ | ✅ | ❌ | ❌ | ❌ |
| extensions.reject | ✅ | ✅ | ❌ | ❌ | ❌ |
| **Reports** |||||
| reports.view | ✅ | ✅ | ✅ | ❌ | ✅ |
| reports.export | ✅ | ✅ | ❌ | ❌ | ✅ |
| **Settings** |||||
| settings.view | ✅ | ❌ | ❌ | ❌ | ❌ |
| settings.edit | ✅ | ❌ | ❌ | ❌ | ❌ |
| **Audit Logs** |||||
| audit-logs.view | ✅ | ❌ | ❌ | ❌ | ❌ |
