# API Design

## Base URL
```
/api/v1/
```

## Authentication
All protected routes require:
```
Authorization: Bearer <jwt_token>
Accept-Language: ar|en
```

## Response Format
```json
{
  "success": true,
  "data": {},
  "message": "...",
  "meta": { "current_page": 1, "last_page": 5, "total": 48 },
  "errors": {}
}
```

## Endpoints

### Auth
| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/auth/login` | Login (username + password) |
| POST | `/auth/logout` | Logout (invalidate JWT) |
| POST | `/auth/refresh` | Refresh JWT token |
| GET | `/auth/me` | Current user profile |
| POST | `/auth/change-password` | Change password |

### Dashboard
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/dashboard` | Role-based dashboard metrics |

### Departments
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/departments` | List all departments |
| POST | `/departments` | Create department |
| GET | `/departments/{id}` | Get department |
| PUT | `/departments/{id}` | Update department |
| DELETE | `/departments/{id}` | Delete department |

### Assessment Cycles
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/cycles` | List cycles |
| POST | `/cycles` | Create cycle |
| GET | `/cycles/{id}` | Get cycle |
| PUT | `/cycles/{id}` | Update draft cycle |
| POST | `/cycles/{id}/activate` | Activate cycle |
| POST | `/cycles/{id}/close` | Close cycle |
| POST | `/cycles/{id}/archive` | Archive cycle |

### Standards
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/cycles/{cycle}/standards` | List standards |
| POST | `/cycles/{cycle}/standards` | Create standard |
| GET | `/cycles/{cycle}/standards/{id}` | Get standard |
| PUT | `/cycles/{cycle}/standards/{id}` | Update standard |
| DELETE | `/cycles/{cycle}/standards/{id}` | Delete standard |

### Evidence Requirements
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/standards/{standard}/requirements` | List requirements |
| POST | `/standards/{standard}/requirements` | Create requirement |
| GET | `/standards/{standard}/requirements/{id}` | Get requirement |
| PUT | `/standards/{standard}/requirements/{id}` | Update requirement |
| DELETE | `/standards/{standard}/requirements/{id}` | Delete requirement |

### Documents
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/documents` | List documents (scoped to role) |
| POST | `/documents` | Create document record |
| GET | `/documents/{id}` | Get document |
| POST | `/documents/{id}/upload` | Upload file version |
| POST | `/documents/{id}/submit` | Submit for review |
| GET | `/documents/{id}/versions/{ver}/download` | Download file |

### Comments
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/documents/{id}/comments` | List comments |
| POST | `/documents/{id}/comments` | Add comment/reply |
| DELETE | `/documents/{id}/comments/{comment}` | Delete comment |

### Extension Requests
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/documents/{id}/extension-requests` | List requests |
| POST | `/documents/{id}/extension-requests` | Request extension |

### Auditor
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/auditor/pending-reviews` | Documents pending review |
| POST | `/auditor/documents/{id}/approve` | Approve document |
| POST | `/auditor/documents/{id}/reject` | Reject with reason |
| GET | `/auditor/extension-requests` | List extension requests |
| POST | `/auditor/extension-requests/{id}/approve` | Approve extension |
| POST | `/auditor/extension-requests/{id}/reject` | Reject extension |

### Reports
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/reports/by-department` | Department progress |
| GET | `/reports/by-standard` | Standard completion |
| GET | `/reports/by-status` | Filter by status |
| GET | `/reports/cycle-summary` | Cycle summary |
| GET | `/reports/export/department-excel` | Export Excel |
| GET | `/reports/export/department-pdf` | Export PDF |
| GET | `/reports/export/cycle-summary-pdf` | Cycle summary PDF |

### Notifications
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/notifications` | List notifications |
| GET | `/notifications/count` | Unread count |
| POST | `/notifications/mark-all-read` | Mark all read |
| POST | `/notifications/{id}/read` | Mark one read |
| DELETE | `/notifications/{id}` | Delete notification |

### Admin — Users
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/admin/users` | List users |
| POST | `/admin/users` | Create local user |
| GET | `/admin/users/ldap-search` | Search Active Directory |
| POST | `/admin/users/import-ldap` | Import AD user |
| GET | `/admin/users/{id}` | Get user |
| PUT | `/admin/users/{id}` | Update user |
| POST | `/admin/users/{id}/reset-password` | Reset password |
| POST | `/admin/users/{id}/toggle-active` | Toggle active |

### Admin — Settings & Audit
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/admin/settings` | All settings |
| POST | `/admin/settings` | Update settings |
| POST | `/admin/settings/branding/upload` | Upload logo/favicon |
| GET | `/admin/audit-logs` | Audit log viewer |
