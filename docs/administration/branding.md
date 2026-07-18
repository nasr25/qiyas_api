# Branding Management

**Author:** Nasser

Super Admin → Settings → Branding (`/admin/settings`).

## Asset types

| Type | Used for |
|---|---|
| `logo_primary` | Default logo across the app; public-branding fallback for header/login when a specific variant isn't set |
| `logo_header` | Sidebar/header (dark background) |
| `logo_login` | Login page |
| `logo_dark` | A light-on-dark variant, where a consumer specifically needs it |
| `logo_compact` | Small-space variant (e.g. a future collapsed sidebar) |
| `favicon` | Browser tab icon |
| `logo_report` | Generated report headers |
| `logo_email` | Outgoing email templates |

Each is versioned and managed independently — uploading a new
`logo_header` never touches `logo_login`'s current active version.

## Upload → Preview → Save → Cancel → Restore

1. **Upload**: choosing a file immediately uploads and validates/
   sanitizes it server-side (see `docs/security/file-security.md`),
   creating a new version with `status=inactive`. It does **not** go
   live yet.
2. **Preview**: the uploaded (not-yet-active) version renders as a
   pending draft card with a thumbnail, filename, and version number.
3. **Save** (labeled "Save" in the pending-draft card) calls the
   activate endpoint, promoting this version to `status=active` and
   superseding whatever was previously active for that type
   (`status=superseded` — never deleted).
4. **Cancel**: there is no delete endpoint for an uploaded draft (it's
   already validated, stored history) — "Cancel" simply means never
   activating it; it stays as an inert `inactive` row.
5. **Version history**: every version (active/inactive/superseded) is
   listed per asset type, with uploader and timestamp.
6. **Restore previous version**: any `superseded` version can be
   re-activated via "Restore" — this runs the same `activate()` path
   (a fresh activation event), so restoring is itself recorded in
   history rather than being a special, unaudited operation.

## Live propagation, no mixed state

`BrandingService::activeUrls()` reads the current `active` row per
type directly — the public `GET /branding` endpoint (used by the
login page and the app header) always reflects exactly the current
active version, never a mix of an old and new logo. Every asset URL
carries a `?v={version}` query string, so an activation is
automatically cache-busted in the browser without requiring a manual
cache clear or a server restart. Verified in this phase by uploading
and activating a test logo in a live browser session and observing
the header logo update immediately, with no page reload.

## Versioning and legacy compatibility

Every upload creates a **new row**; nothing is ever overwritten in
place. A legacy-compatibility bridge additionally writes the resolved
path into the older flat `Setting` key-value store on activation, so
any pre-existing code path still reading that old key continues to
work — though the public branding endpoint itself no longer depends
on it (it reads `BrandingAsset` directly).

## Authorization

Every branding endpoint is gated by `role:super-admin`. Verified with
Playwright tests confirming auditor/employee/program-manager accounts
get a 403 from the API and are redirected away from the settings page
in the UI.

## Metadata recorded per version

Asset type, version number, status, original filename, MIME type
(real, `finfo`-derived), file size, width/height (where decodable),
SHA-256 hash, uploader, uploaded-at, activated-at, and a link to the
previous version.

## What is not built

Program-specific logos (separate from this global branding) are not
part of this phase's scope — the brief explicitly required keeping
program-specific logos, if any exist in the future, separate from
global branding, but no program currently has its own logo mechanism
to keep separate from. Automated contrast-safety validation on
uploaded logos (ensuring a logo remains legible against its target
background) is not implemented — a Super Admin should visually confirm
a new logo reads correctly across light/dark mode before relying on
it in a pilot environment.
