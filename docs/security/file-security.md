# File Security — Uploads, Storage, and Downloads

**Author:** Nasser

## Storage layout

| Disk | Root | Web-accessible | Contents |
|---|---|---|---|
| `private` | `storage/app/private` | **No** | Evidence files, XLSX imports and their error reports |
| `public` | `storage/app/public` | Yes, via `/storage` symlink | Branding assets (logos/favicons only) |

Evidence and import files are never served from a public path or a
predictable URL — every download goes through an authorized
controller action, which re-checks program/department/role scope
before streaming the file (see `DocumentService::download()` /
`downloadUrl()`). There is no direct filesystem URL for evidence.

## Evidence file uploads

Validated by extension against an allow-list (documents + common
office formats + images), size-limited, stored under a randomized
generated name (never the client-supplied original filename) with the
original filename kept only as metadata (`EvidenceFile.original_name`
or equivalent) for display and download `Content-Disposition`.
Submitted evidence versions are immutable — a resubmission creates a
new `EvidenceSubmission`/file version rather than overwriting the
previous one, matching the platform-wide append-only pattern.

## Branding asset uploads (Phase 8 — `BrandingService`)

The most rigorously validated upload path in the platform, in order:

1. **Extension allow-list**: PNG, JPG, JPEG, ICO, SVG only.
2. **Real MIME type** via `UploadedFile::getMimeType()` (Symfony/
   `finfo`-derived) — never the client-supplied `Content-Type` header.
3. **Decode verification** for raster formats:
   `getimagesizefromstring()` both confirms the file is a genuinely
   decodable image (rejects corrupted/truncated/polyglot files) and
   returns true pixel dimensions, cross-checked against the claimed
   extension's expected `IMAGETYPE_*` constant.
4. **Signature verification** for `.ico` (GD cannot decode it): the
   4-byte header `\x00\x00\x01\x00` is checked directly.
5. **Size and pixel-count limits**: 2 MB max file size, 4000×4000 max
   pixel count.
6. **SVG defense-in-depth** (three independent layers, any one of
   which rejects an unsafe file):
   - A pre-sanitizer regex rejects any `<!ENTITY ... SYSTEM|PUBLIC>`
     or DOCTYPE-internal entity declaration outright (XXE), rather
     than relying solely on libxml's modern default of not resolving
     external entities.
   - The reviewed third-party sanitizer `enshrined/svg-sanitize`
     (pinned to `^0.22`, above CVE-2025-55166), with
     `removeRemoteReferences(true)` — strips external image/font/CSS
     `url()` references, closing an offline-first/SSRF-adjacent gap a
     default sanitizer configuration would leave open.
   - A post-sanitizer belt-and-braces regex rejects the file if
     anything matching `<script`, an `on\w+=` event handler,
     `javascript:`, or `<foreignObject` survived sanitization.
7. **Content-addressed storage**: every upload is hashed (`sha256`)
   and stored under a randomized filename
   (`{type}-{timestamp}-{random}.{ext}`) — never the client-supplied
   name, and never overwriting a previous version.

Verified with real PHPUnit tests using an XXE payload (rejected), an
embedded-`<script>`+`onload=` payload (either rejected or, if
sanitized-and-accepted, the stored file is asserted to contain neither
`<script` nor `onload=`), a corrupted-PNG payload (rejected — fails
`getimagesizefromstring()`), and a non-image file (rejected by
extension). See `tests/Feature/Admin/PlatformAdministrationTest.php`.

## Import files (XLSX)

`maatwebsite/excel` reads the workbook; `QiyasImportValidator`
additionally inspects the ZIP container for `xl/vbaProject.bin`
(detects a `.xlsm` macro workbook renamed to `.xlsx`) on both the
preview and confirm steps — a real defect found and fixed in the Phase
3 security review. Row count is capped (`MAX_ROWS=5000`) and upload
size limited, judged sufficient against decompression/zip-bomb risk at
the current cap (not independently fuzz-tested against a crafted zip
bomb).

## Downloads

Every evidence/import/report download goes through a controller that:
performs its own program/department/role authorization check
(independent of whatever the frontend already hid), uses the stored
random filename to read from disk, and sets `Content-Disposition`
from the stored *original* filename metadata — never trusting a
client-supplied path. Path traversal is not possible because no
download endpoint accepts a raw filesystem path from the client; every
lookup goes through a database record id.

## Temp files

Upload processing uses PHP's own managed temp-upload handling (never a
manually created temp file left behind); the platform does not create
additional ad hoc temp files outside of that framework-managed
lifecycle in the upload/import paths reviewed.

## Malware scanning

**Not integrated.** No antivirus/malware-scan step exists in the
upload pipeline for evidence, imports, or branding assets. This is a
documented gap, not a false claim of coverage — see the final
readiness report for whether it blocks pilot deployment.
