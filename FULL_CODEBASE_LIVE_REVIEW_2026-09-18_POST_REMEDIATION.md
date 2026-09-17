# ESPForge Full Codebase and Production Review — Post-remediation

**Date:** 2026-09-18  
**Reviewed revision:** `7d68a08`  
**Production:** `https://espforge.alwaysdata.net`  
**CI:** GitHub Actions run `35257423579` passed all checks

## Integrity of this review

The repository was re-reviewed across authentication, authorization, secrets, repositories, target analysis, build generation, artifacts, flashing, database migrations, frontend behavior, deployment, and tests. Public production routes and deployed assets were inspected.

The supplied credential was not persisted in a file, command, report, or commit. This environment cannot carry cookies between page-fetch requests, and its command-line TLS route previously failed before login after three retries. Consequently, no authenticated production session was completed in this pass. Authenticated settings, repositories, builds, downloads, and flashes are not represented as live-verified. The password should be rotated because it was disclosed in chat.

## Result

| Severity | Open |
|---|---:|
| Critical | 0 |
| High | 5 |
| Medium | 9 |
| Low | 6 |

The previous CSP high finding and plaintext analysis-cache low finding are resolved in source and CI. Production deployment of `7d68a08` is still required before the CSP correction is live.

## Live observations

- Landing page is reachable over HTTPS and renders the intended product, authentication, workflow, and iOS/Web Serial limitation content.
- Direct dashboard navigation redirects an unauthenticated client to the landing page.
- `api/auth.php?action=session` returns an anonymous session and CSRF token.
- `api/projects.php` rejects an unauthenticated request with `Authentication required`.
- Deployed dashboard JavaScript contains the one-popup-per-attempt OAuth suppression, mobile logout, custom dialogs, resilient parallel dashboard loading, build expansion, and safe text escaping.
- The live asset proves deployment through the earlier OAuth/mobile batch, but not deployment of revision `7d68a08`.

## Resolved findings

### R1. Conflicting CSP configuration

PHP no longer emits a second policy. `public/.htaccess` owns one strict, self-only policy for static and PHP responses. `tests/SecurityHeadersTest.php` enforces one reviewed definition and rejects obsolete jsDelivr and Google Fonts origins. CI passed this test.

### R2. Plaintext persistent analysis cache

Persistent target/library analysis caches are now AES-256-GCM encrypted. Invalid ciphertext is deleted. The pruning job deletes legacy plaintext `.json` caches and manages only encrypted `.cache` entries thereafter.

## Open high findings

1. **Request-bound first analysis.** Initial AI/source analysis and Arduino source-context construction can occupy a PHP request for up to the provider timeout. A durable queue and worker are still required.
2. **Official pre-selection metadata validation.** Board menus, packages, and Arduino libraries are not resolved against pinned official registries before target presentation.
3. **Incomplete persisted build-plan lifecycle.** There is no independent analyzing/ready/approved/superseded plan record with approver, timestamp, and digest-bound dispatch.
4. **Unsigned artifact provenance.** Hashes detect accidental inconsistency but do not authenticate publisher/workflow identity; replacement of binary and manifest together remains possible.
5. **No authenticated external production synthetic monitor.** CI does not exercise production login/session/settings/projects/targets/builds/logout from an independent controlled runner.

## Open medium findings

1. AI evidence identifies repository paths but does not persist verified line ranges, excerpts, and blob hashes.
2. Analyzer, prompt, model, package-map, profile, and workflow policy versions are scattered.
3. Marauder-specific dependencies and patches remain embedded in the generic workflow engine.
4. YAML anchors/block matrices and complex preprocessor selectors are only partially parsed.
5. GitHub blob batching lacks detailed retry-after/rate-limit/fallback metrics.
6. Artifact ZIP handling can buffer up to 100 MB in browser memory.
7. Build reconciliation polls GitHub rather than consuming verified signed webhooks.
8. Database preflight does not exhaustively compare every foreign key, default, index order, engine, and charset property.
9. GitHub status verification can block on a cold provider request and does not show a persisted last-verified timestamp.

## Open low findings

1. AI model identifiers remain hard-coded.
2. Target identifiers have no explicit API schema/version compatibility contract.
3. Flash manifests do not always provide authoritative offsets.
4. Frontend application JavaScript remains densely formatted and costly to maintain.
5. Automated browser coverage for mobile, desktop, OAuth restoration, dialogs, and Web Serial is absent.
6. Operational latency, provider-error, queue, and cache metrics remain limited.

## Area-by-area review

### Authentication and authorization

Sessions use strict cookie mode, HttpOnly, SameSite, secure production cookies, absolute/inactivity expiry, regeneration on authentication, and server-side session-version invalidation. Mutating actions require CSRF. Sensitive actions use recent-auth checks where appropriate. OAuth identities cannot silently replace another account. Resource operations consistently scope repositories/builds to the authenticated owner. Distributed database-backed rate limits fail closed.

### Secrets and account binding

GitHub and AI credentials are encrypted. AI key fingerprints enforce exclusive account ownership and survive OAuth sign-in independently of GitHub. Key removal is explicit. Cache encryption now prevents future source-derived cache expansion from becoming plaintext-at-rest exposure.

### Repository and target analysis

Remove, delete-fork, per-repository sync, sync-all, duplicate prevention, and normalized up-to-date messaging exist. AI remains primary while deterministic parsing constrains executable facts. PlatformIO, Arduino, and ESP-IDF discovery includes disabled/commented definitions. Remaining concerns are durable asynchronous analysis, official metadata, stronger evidence records, and parser completeness.

### Workflows, builds, and provenance

Generated workflows use pinned actions, read-only permissions, disabled credential persistence, immutable source commits, bounded inputs, and rejected write/publish/token behaviors. Build records preserve source commit, analyzer/model, selected target, config digest, and workflow digest. Live and historical builds are separated, refresh rapidly, expose expandable steps, cancellation, elapsed/total timing, artifact ZIP download, and error-log download. Cryptographic publisher identity and a persisted approval lifecycle remain absent.

### Downloads and flashing

Artifact retrieval enforces ownership, CSRF, bounded redirects/content, ZIP traversal/symlink/count/ratio protections, chip checks, hashes, and address checks. Browser esptool is vendored and CSP-compatible. Terminal output is scrollable and iOS limitations are explicit. Browser memory buffering and incomplete authoritative offsets remain.

### Database and operations

Migrations are checksummed and lock-protected; semantic duplicate recovery rejects conflicting partial DDL. Fresh-schema baseline and the complete migration chain run against MariaDB 11.4 in CI. Production procedure remains `php bin/migrate.php` followed by `php bin/preflight.php`; migrations 001–007 must not be re-baselined. Schema preflight depth and external observability still need expansion.

### UI and tests

Responsive layout is viewport-driven and supports safe areas, keyboard focus, reduced motion, custom dismissible dialogs, mobile logout, remembered active tab, and resilient partial dashboard loads. CI passed PHP syntax, security-header, parser, workflow-safety, workflow-generation, JavaScript syntax, and MariaDB integration checks. True browser E2E and authenticated production synthetic testing remain absent.

## Conclusion

The truthful post-remediation count is **0 critical, 5 high, 9 medium, and 6 low**. It is not zero because the remaining items are substantive architecture, authenticity, metadata, monitoring, streaming, parser, and browser-test work. They must not be closed by documentation changes alone. Deploy `7d68a08` to activate the CSP and encrypted-cache corrections in production, then verify response headers and run an authenticated synthetic check from a cookie-capable controlled monitor.
