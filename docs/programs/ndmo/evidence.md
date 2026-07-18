# NDMO — Evidence Engine

Uses the exact same generic Evidence Engine every other program uses —
zero code change, since an NDMO Requirement's evidence submission is a
normal `EvidenceSubmission` row tied to the bridged `Standard`.

An NDMO evidence file is scoped (through the existing chain) to: NDMO
program, cycle, content version (via the mirrored `Standard`), assessable
node, assignment, submission version, department, uploading user — no
new relationship was required.

## File policy

Independent per-program configuration (`evidence` category) — same
mechanism proven independent for Sumoud (Phase 5) and ECC (Phase 6).
NDMO's current values are organizational defaults, not an official file
policy.

## Cross-program isolation

Unchanged mechanism: a user's access to another program's evidence never
grants NDMO evidence access — proven generically by the existing cross-
program isolation suite, exercised again for NDMO in
`tests/e2e/cross-program/ndmo-isolation.spec.ts`.
