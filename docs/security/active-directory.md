# Active Directory Integration — Production Readiness Review

**Author:** Nasser

`app/Services/LdapService.php` (233 lines) is the sole AD integration
point, used by `AuthService` for login and by the admin user-import
flow for directory search. **This review is a code-level read of the
implementation — it has not been exercised against a real Active
Directory domain controller in this environment**, which has no
Windows/AD infrastructure available. Treat every "supported" item
below as "implemented in code, not field-verified," and every "gap"
item as a real, confirmed gap in the current implementation.

## What is implemented

| Capability | Status |
|---|---|
| LDAPS (`ldaps://`) | Supported — `use_ssl` config |
| StartTLS on a plain connection | Supported — `use_tls` config, `ldap_start_tls()` |
| LDAP protocol v3, no referral chasing | `LDAP_OPT_PROTOCOL_VERSION=3`, `LDAP_OPT_REFERRALS=0` |
| UPN or sAMAccountName login | `authenticate()` accepts either, builds a UPN bind DN when a bare username is given |
| Empty-password bind rejection | Explicit `trim($password) === ''` guard, checked first and unconditionally (defense in depth against the classic "unauthenticated bind treated as anonymous success" AD behavior) — added in the Phase 3 security review |
| LDAP filter injection prevention | `ldap_escape($query, '', LDAP_ESCAPE_FILTER)` on every user-supplied search value |
| Read-only directory search | `searchUsers()` requires an explicit service (bind) account (`LDAP_USERNAME`/`LDAP_PASSWORD`); returns an empty result (not an error) when unconfigured, so the platform degrades gracefully without a bind account |
| No credentials logged | `Log::error()`/`Log::warning()` calls in this file log messages only, never the bind password |
| Local fallback | AD is one authentication path among others in `AuthService`; local/database-backed authentication (Super Admin and any local account) is independent of AD availability |

## Confirmed gaps (not yet implemented)

1. **No account-status validation.** `userAccountControl` (disabled/
   locked-out flags), `accountExpires`, and `pwdLastSet` are not read
   or checked anywhere in `LdapService`. A disabled or expired AD
   account whose password still validates against the directory would
   currently authenticate successfully. **This must be fixed before AD
   is used in production** — see the final readiness report.
2. **No connection/query timeout configured.** No
   `LDAP_OPT_NETWORK_TIMEOUT` (or equivalent) is set; a slow or
   unreachable DC could hang a login request for the underlying
   library's default timeout rather than a short, deliberate one.
3. **No multi-DC failover.** `LdapService` connects to a single
   configured `LDAP_HOST`. There is no fallback list of domain
   controllers and no retry-against-a-second-DC logic.
4. **No group-to-role mapping.** The service returns raw user
   attributes (`sAMAccountName`, `displayName`, `mail`, `department`,
   `title`); it does not read AD group membership, and there is no
   mapping from an AD group to a platform role or program membership.
   Program/role assignment for an AD-authenticated user happens
   entirely through the platform's own `program_user_roles` table —
   which is the correct model per this platform's authorization design
   (an AD group alone must never grant platform access without an
   explicit program-role row), but it means AD group sync is not
   automated; a Super Admin must provision program roles manually for
   each imported AD user.
5. **No credential-rotation procedure documented** for the LDAP
   service (bind) account itself, beyond "update `LDAP_PASSWORD` and
   restart."

## Never implemented, by design

- LDAP write operations (the platform never writes back to AD).
- Password changes/resets via AD (local accounts only — see
  `docs/security/secrets-management.md`).

## Local Super Admin as protected emergency access

Because AD availability and account-status enforcement are the gaps
above, the **local Super Admin account is the protected emergency
access path** and must never depend on AD. Local authentication
requirements (strong password policy, no default/seeded production
password, rate limiting, no Quick Login/dev shortcut in production)
are covered in `docs/security/secrets-management.md`.

## Configuration reference

Environment variables (all optional — `isConfigured()` returns `false`
and the platform falls back to local-only auth when `LDAP_HOST` is
unset): `LDAP_HOST`, `LDAP_PORT` (default 389), `LDAP_BASE_DN`,
`LDAP_USERNAME`/`LDAP_PASSWORD` (service/bind account, required only
for directory *search*, not for user login), `LDAP_USE_SSL`,
`LDAP_USE_TLS`.

## Recommendation before enabling AD in a pilot/production environment

Implement gap #1 (account-status validation) at minimum before any
pilot environment authenticates real users through AD — an AD-side
account disable/lockout must actually deny platform login. Gaps #2–#4
should be addressed before a general-availability production rollout
but do not block a controlled pilot where AD is used by a small,
known set of accounts. See the final readiness report's
unresolved-issues section for severity/owner/target-date tracking.
