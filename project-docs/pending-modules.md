# Pending / Future Modules

## Phase 2 — Enhancements (Recommended)

### Backend
- [ ] Email queue retry logic and dead-letter handling
- [ ] Rate limiting per-user on file uploads
- [ ] Document virus scanning integration (ClamAV or Windows Defender)
- [ ] Full-text search across document titles and comments
- [ ] Bulk standard import via Excel template
- [ ] Two-factor authentication for Super Admin accounts
- [ ] Password complexity policy enforcement
- [ ] Session timeout configuration
- [ ] API response caching for reports

### Frontend
- [ ] Dark mode full implementation (toggle exists, color scheme needs dark variants on all components)
- [ ] Mobile app or PWA manifest
- [ ] Offline capability for viewing approved documents
- [ ] Print-friendly views for reports
- [ ] Drag-and-drop file upload
- [ ] Real-time notifications via WebSocket/SSE (currently polls every 30s)
- [ ] Advanced filtering with saved filter presets
- [ ] Bulk actions (e.g., approve multiple documents)

## Phase 3 — Future Platform Modules

All designed to plug into the existing modular architecture:

- [ ] **Risk Management** — Risk register, risk assessment, treatment plans
- [ ] **Internal Audit** — Audit planning, findings, corrective actions
- [ ] **Compliance Management** — Regulatory compliance tracking
- [ ] **Policy Management** — Policy lifecycle, approval, publication
- [ ] **Strategic Objectives** — KPIs, targets, performance tracking
- [ ] **Digital Transformation Initiatives** — Initiative tracking, milestones

## Known Items for Production Hardening

- [ ] Enable `APP_DEBUG=false` in production
- [ ] Configure PHP OPcache
- [ ] Set up SSL/TLS certificates
- [ ] Configure LDAP over TLS (`LDAP_USE_TLS=true`)
- [ ] Configure backup automation
- [ ] Set up monitoring/alerting
- [ ] Performance test with realistic data volumes
- [ ] Security penetration testing
