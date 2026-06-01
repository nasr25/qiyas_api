# Qiyas Platform - Database Design

## Core Tables

### users
| Column | Type | Description |
|--------|------|-------------|
| id | bigint PK | Primary key |
| name | varchar(255) | Full name |
| username | varchar(100) unique | Login username |
| email | varchar(255) unique | Email address |
| password | varchar(255) nullable | Local users only |
| auth_type | enum(ldap,local) | Authentication type |
| department_id | bigint FK nullable | Department |
| avatar | varchar(255) nullable | Profile picture |
| is_active | boolean | Active status |
| must_change_password | boolean | Force password change |
| last_login_at | timestamp nullable | Last login |
| last_login_ip | varchar(45) nullable | Last IP |
| locale | varchar(10) | Preferred language |
| created_at | timestamp | |
| updated_at | timestamp | |

### departments
| Column | Type | Description |
|--------|------|-------------|
| id | bigint PK | |
| name_ar | varchar(255) | Arabic name |
| name_en | varchar(255) | English name |
| description | text nullable | Description |
| is_active | boolean | Active status |
| created_at | timestamp | |
| updated_at | timestamp | |

### assessment_cycles
| Column | Type | Description |
|--------|------|-------------|
| id | bigint PK | |
| name | varchar(255) | Cycle name |
| year | year | Assessment year |
| start_date | date | Start date |
| end_date | date | End date |
| status | enum(draft,active,closed,archived) | Current status |
| final_score | decimal(5,2) nullable | Final score |
| closing_notes | text nullable | Notes on close |
| activated_at | timestamp nullable | When activated |
| closed_at | timestamp nullable | When closed |
| created_by | bigint FK | Creator |
| created_at | timestamp | |
| updated_at | timestamp | |

### standards
| Column | Type | Description |
|--------|------|-------------|
| id | bigint PK | |
| cycle_id | bigint FK | Assessment cycle |
| standard_number | varchar(50) | Standard identifier |
| name_ar | varchar(500) | Arabic name |
| name_en | varchar(500) | English name |
| description | text nullable | Description |
| version | varchar(20) nullable | Version |
| weight | decimal(5,2) nullable | Weight/score |
| due_date | date nullable | Due date |
| is_active | boolean | Active flag |
| created_at | timestamp | |
| updated_at | timestamp | |

### department_standard (pivot)
| Column | Type | Description |
|--------|------|-------------|
| id | bigint PK | |
| department_id | bigint FK | Department |
| standard_id | bigint FK | Standard |
| assigned_at | timestamp | Assignment date |
| assigned_by | bigint FK | Who assigned |

### evidence_requirements
| Column | Type | Description |
|--------|------|-------------|
| id | bigint PK | |
| standard_id | bigint FK | Parent standard |
| title_ar | varchar(500) | Arabic title |
| title_en | varchar(500) | English title |
| description | text nullable | Description |
| is_mandatory | boolean | Mandatory flag |
| sort_order | integer | Display order |
| created_at | timestamp | |
| updated_at | timestamp | |

### documents
| Column | Type | Description |
|--------|------|-------------|
| id | bigint PK | |
| requirement_id | bigint FK | Evidence requirement |
| department_id | bigint FK | Department |
| cycle_id | bigint FK | Assessment cycle |
| title | varchar(500) | Document title |
| status | enum(draft,under_review,approved,rejected,overdue) | Status |
| current_version | integer | Current version number |
| submitted_by | bigint FK nullable | Who submitted |
| submitted_at | timestamp nullable | Submission time |
| reviewed_by | bigint FK nullable | Who reviewed |
| reviewed_at | timestamp nullable | Review time |
| rejection_reason | text nullable | Rejection reason |
| created_at | timestamp | |
| updated_at | timestamp | |

### document_versions
| Column | Type | Description |
|--------|------|-------------|
| id | bigint PK | |
| document_id | bigint FK | Parent document |
| version_number | integer | Version number |
| file_path | varchar(1000) | Stored file path |
| original_filename | varchar(500) | Original file name |
| file_size | bigint | File size bytes |
| file_type | varchar(50) | MIME type |
| file_hash | varchar(64) | SHA-256 hash |
| change_reason | text nullable | Why new version |
| uploaded_by | bigint FK | Uploader |
| uploaded_at | timestamp | Upload time |
| created_at | timestamp | |

### extension_requests
| Column | Type | Description |
|--------|------|-------------|
| id | bigint PK | |
| document_id | bigint FK | Related document |
| requested_by | bigint FK | Requester |
| requested_date | date | Requested new date |
| reason | text | Request reason |
| status | enum(pending,approved,rejected) | Status |
| reviewed_by | bigint FK nullable | Auditor |
| reviewed_at | timestamp nullable | Review time |
| reviewer_notes | text nullable | Auditor notes |
| created_at | timestamp | |
| updated_at | timestamp | |

### comments
| Column | Type | Description |
|--------|------|-------------|
| id | bigint PK | |
| commentable_type | varchar(255) | Polymorphic type |
| commentable_id | bigint | Polymorphic ID |
| user_id | bigint FK | Author |
| body | text | Comment content |
| parent_id | bigint FK nullable | Thread reply |
| created_at | timestamp | |
| updated_at | timestamp | |

### comment_attachments
| Column | Type | Description |
|--------|------|-------------|
| id | bigint PK | |
| comment_id | bigint FK | Parent comment |
| file_path | varchar(1000) | Stored path |
| original_filename | varchar(500) | Original name |
| file_size | bigint | File size |
| created_at | timestamp | |

### notifications (Laravel default + custom)
| Column | Type | Description |
|--------|------|-------------|
| id | uuid PK | |
| type | varchar(255) | Notification class |
| notifiable_type | varchar(255) | Polymorphic |
| notifiable_id | bigint | User ID |
| data | json | Notification data |
| read_at | timestamp nullable | When read |
| created_at | timestamp | |
| updated_at | timestamp | |

### audit_logs
| Column | Type | Description |
|--------|------|-------------|
| id | bigint PK | |
| user_id | bigint FK nullable | Actor |
| action | varchar(100) | Action type |
| model_type | varchar(255) nullable | Affected model |
| model_id | bigint nullable | Affected ID |
| old_values | json nullable | Before state |
| new_values | json nullable | After state |
| description | text nullable | Human description |
| ip_address | varchar(45) | Client IP |
| user_agent | varchar(500) nullable | Browser info |
| created_at | timestamp | |

### settings
| Column | Type | Description |
|--------|------|-------------|
| id | bigint PK | |
| group | varchar(100) | Setting group |
| key | varchar(100) | Setting key |
| value | text nullable | Setting value |
| type | varchar(50) | Data type |
| created_at | timestamp | |
| updated_at | timestamp | |

### Spatie Permission Tables
- roles
- permissions  
- model_has_roles
- model_has_permissions
- role_has_permissions
