# ESPForge Full Codebase and Live Review — Production Recovery Pass

**Date:** 2026-09-18  
**Reviewed source:** `8230d06` (functional revision `e9d3467`)  
**Production:** `https://espforge.alwaysdata.net`  
**Quality run:** `35327018784` — passed

## Scope and integrity

The complete repository was reviewed across configuration, authentication/authorization, identity and key ownership, repositories, analysis queue, plans, profiles, workflow/build generation, webhooks, downloads, flashing, responsive UI, migrations, deployment, dependencies, and tests. Public production routes and the deployed frontend were inspected again.

The supplied password was not written to source, files, shell commands, logs, reports, or commits. Authenticated production testing could not complete because this environment lacks a cookie-preserving browser/page transport; its command-line TLS and SSH transports are blocked. Protected behavior is not represented as live-tested. Rotate the password because it was disclosed in chat.

## Result

| Severity | Open |
|---|---:|
| Critical | 0 |
| High | 4 |
| Medium | 1 |
| Low | 0 |

The temporary production HTTP 500 availability finding from the preceding pass is closed: the landing page, anonymous session endpoint, and formatted dashboard asset are reachable again.

## Live observations

- The HTTPS landing page is reachable and renders the complete authentication/product content.
- Anonymous session creation succeeds and returns a CSRF token.
- The deployed dashboard bundle is formatted and includes the server-reference error handling, responsive behavior, queue polling, OAuth replay suppression, mobile logout, dialog controls, native streaming download form, and Web Serial safeguards.
- Production has deployed the formatted/E2E asset batch.
- Public checks cannot prove authenticated repository/build/download/flash behavior.

## Closed production availability finding

All resources that returned HTTP 500 in the immediately preceding review are reachable again through the same page-fetch route. No source defect was identified as the cause of that transient condition. Alwaysdata error logs should remain the authority for determining whether it was a hosting restart, temporary deployment state, or upstream access failure.

## Open high findings

1. Final workflow/source materialization still runs in the dispatch HTTP request rather than being prepared as a digest-addressed worker payload.
2. Board/package/library facts are not validated against pinned official metadata snapshots before build plans become ready.
3. Artifact provenance has hashes but lacks an authenticated publisher/workflow signature and verification before flashing.
4. No independent authenticated production synthetic monitor continuously exercises protected workflows.

## Open medium finding

1. YAML anchors/block matrices and complex combined preprocessor selectors remain only partially parsed.

## Security and functional review

Strict sessions, secure/HttpOnly/SameSite cookies, CSRF, session expiry/versioning, recent-auth checks, distributed limits, and owner-scoped operations remain present. OAuth identities cannot replace another account. AI keys are encrypted, fingerprint-exclusive, independent of GitHub identity, and owner-removable. Persistent caches and queue results are encrypted. CSP remains strict and single-source.

AI remains primary while deterministic evidence is the executable trust boundary. Evidence contains path, complete-content SHA-256, excerpt, and line range. Policy/model/schema versions are centralized. Analysis jobs use locking, retries/backoff, encryption, metrics, and retention. Plans retain immutable commit/config digest, approval identity/time, supersession, and dispatch state.

Marauder-specific behavior is isolated in a versioned profile. Generated workflows use pinned actions, read-only permissions, disabled credential persistence, bounded dependencies, unsafe-operation rejection, provenance hashes, and toolchain-provided offsets. Signed webhook reconciliation is replay-protected and repository/workflow/UUID bound. Downloads use native browser POST plus bounded server streaming and ZIP validation. Browser flashing validates hashes, chip family, address, and size.

Frontend code is formatted and formatting-enforced. Node dependencies are lockfile-pinned, and the current high-severity audit reports zero vulnerabilities. Desktop Chromium and Pixel 7 E2E tests cover authentication dialogs, mobile navigation/logout, Web Serial guidance, OAuth restoration, one-display-per-tab behavior, viewport behavior, and mocked dashboard loading.

Quality run `35327018784` passed all PHP, policy, telemetry, queue, profile, webhook, parser, workflow, JavaScript dependency/formatting, browser E2E, and MariaDB 11.4 migration/preflight checks.

## Conclusion

The truthful current result is **0 critical, 4 high, 1 medium, and 0 low**. Production public availability has recovered and the latest frontend is deployed. Authenticated live verification remains unavailable from the current transport and is not assumed successful.
