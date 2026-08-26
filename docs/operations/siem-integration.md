# SIEM Integration

**Author:** Nasser

## Current state: not integrated

No SIEM/log-shipping integration exists in this platform. There is no
outbound log forwarder, no Syslog/CEF/LEEF export, and no SIEM
connector configured anywhere in either repository. This is a real,
honestly-documented gap, not a disabled-by-default feature.

## What would be required

1. **A structured event-logging layer** (see the gap described in
   `docs/operations/monitoring.md`) — a SIEM needs consistently
   shaped, machine-parseable events; the current `audit_logs` table
   and free-text `laravel.log` are not that today.
2. **A log-shipping mechanism** appropriate to the deployment
   environment — e.g. a Windows Event Log channel + an on-host
   forwarder (Winlogbeat/NXLog-class tool), or a direct Syslog/HTTPS
   export from the application. None is implemented; the choice
   depends on which SIEM product the deploying organization actually
   uses, which this session has no visibility into.
3. **Explicit exclusion of secrets from anything shipped** — the same
   "never log passwords/tokens/SMTP secrets/session identifiers/full
   evidence contents/AD credentials/encryption keys" rule that already
   governs `laravel.log` and `audit_logs` must extend to whatever new
   structured/shipped log format is built.

## What this platform already provides that a future SIEM integration can build on

- The audit log's `action` field is already a stable, enumerable
  string per event type (e.g. `branding.uploaded`, `smtp_settings.
  password_configured`, `standard.assigned`) — a reasonable basis for
  a future `event_type` field.
- Every audit entry already carries `user_id`, `role`,
  `department_id`, `compliance_program_id`, `ip_address`, and
  `user_agent` — most of the correlation fields a SIEM would want,
  just not in the specific shape/field-name set an off-the-shelf SIEM
  parser might expect.

## Recommendation

Do not claim SIEM readiness for a production rollout until the
structured event-logging layer in `docs/operations/monitoring.md` is
built and a real log-shipping mechanism is chosen and implemented for
the target organization's actual SIEM product. This is listed as an
open item in the final readiness report.
