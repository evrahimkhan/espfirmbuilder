# ESPForge Full Codebase and Live Review

**Date:** 2026-09-18  
**Reviewed revision:** `113aef8`  
**Live URL:** `https://espforge.alwaysdata.net`  

## Test scope and limitation

The entire repository was reviewed again. The public production page was reachable through the page-fetch route and unauthenticated dashboard access redirected to the landing page. Three attempts to establish an authenticated session from the sandbox failed during the TLS handshake before any credentials were transmitted successfully. Therefore, authenticated production APIs and UI actions were **not** verified in this pass, and this report does not claim otherwise.

The supplied password was not written to the repository or a persisted workspace file. Because it was disclosed in chat, it should be rotated after testing.

## Executive summary

No critical vulnerability was identified. Security controls around identity binding, encrypted secrets, CSRF, rate limiting, resource ownership, workflow generation, dependency pins, and archive handling remain strong.

A new configuration inconsistency was identified: PHP API responses still emit an obsolete CSP allowing jsDelivr and Google Fonts even though those runtime dependencies were removed and `.htaccess` was tightened. Depending on server header-merging behavior, PHP and Apache can send conflicting CSP policies. Five previously documented architectural high findings also remain.

**Findings:** 0 critical, 6 high, 9 medium, 7 low.

## High findings

### H1. PHP and Apache emit inconsistent Content Security Policies

`public/.htaccess` now restricts scripts, styles, and fonts to the application origin. `src/bootstrap.php` still emits the old policy allowing:

- `cdn.jsdelivr.net` esptool/pako/atob modules;
- `fonts.googleapis.com`;
- `fonts.gstatic.com`.

The application no longer needs these origins. Depending on Apache/PHP header behavior, responses may contain two CSP headers or PHP may weaken/contradict the intended deployment policy.

**Fix:** define one CSP source of truth, remove obsolete origins from PHP, and add HTTP integration tests asserting exactly one expected CSP on HTML and API responses.

### H2. First-time source and AI analysis remains request-bound

Parallel Git blob retrieval and caches reduce latency, but first analysis still occupies a PHP request and can wait up to 60 seconds for AI. Build dispatch still constructs Arduino source context synchronously.

**Fix:** create cron/queue-backed analysis jobs at connect/sync time, persist immutable plans and progress, and make dispatch consume a ready approved plan.

### H3. Official Arduino metadata is not resolved before target presentation

AI executable facts are constrained, but exact board/menu validation occurs later on the Actions runner. Library acceptance uses a curated map instead of a pinned official index with architecture compatibility.

**Fix:** ingest pinned ESP32 package/boards and Arduino Library Manager metadata, validate before returning targets, and include metadata digests in plans.

### H4. Build-plan approval is not a complete persisted lifecycle

Build rows store provenance at dispatch and AI-only generic targets receive browser confirmation. There is no independent plan with analyzing/ready/approved/superseded states, persisted approver/time, or dispatch-by-plan digest.

### H5. Artifact provenance remains unsigned

Manifest hashes prove consistency but not publisher/workflow authenticity. A binary and manifest can be replaced together.

**Fix:** use GitHub OIDC/Sigstore or a protected signer and verify signature identity before flashing.

### H6. Authenticated production behavior lacks an automated synthetic test

CI validates code and MariaDB migrations, but there is no external synthetic test of login, session, settings, GitHub status, repository list, targets, builds, logout, and OAuth error cleanup.

**Fix:** use a dedicated least-privilege test account and repository, run non-destructive production checks from a controlled monitor, and rotate credentials automatically.

## Medium findings

1. AI evidence is path-level, not verified line/excerpt/hash evidence.
2. Analyzer, prompt, model, package-map, profile, and workflow versions remain scattered.
3. Marauder-specific dependencies and compatibility patches remain in the generic workflow engine.
4. YAML matrix and preprocessor parsing remain incomplete for block syntax/anchors/complex selectors.
5. Blob batching lacks retry-after/rate-limit metrics and detailed fallback observability.
6. Blob artifact downloads can buffer up to 100 MB in mobile browser memory.
7. GitHub build reconciliation remains polling-based rather than signed-webhook driven.
8. Database preflight does not comprehensively verify all foreign keys/defaults/index order/engine/charset.
9. GitHub connection verification can wait on a cold provider request and does not expose a last-verified timestamp.

## Low findings

1. AI model identifiers are hard-coded.
2. Cache metadata is plaintext and must not be expanded to private source excerpts without encryption.
3. Target ID API compatibility/versioning is undefined.
4. Flash manifests usually lack authoritative image offsets.
5. Frontend application source remains densely formatted.
6. End-to-end mobile/desktop/OAuth/Web Serial tests are absent.
7. Operational latency/error/cache metrics are limited.

## Confirmed strengths

- OAuth identities cannot silently replace or merge account data.
- AI keys are encrypted and fingerprint-exclusive to one account.
- Session cookies, CSRF, session expiry/versioning, and distributed limits are present.
- Repository/build/download/cancel operations enforce user ownership.
- GitHub connection and delete permission are separately verified/displayed.
- OAuth callback replay handling is idempotent for consumed states.
- Repeated browser-restored OAuth errors are suppressed per new connection attempt.
- AI is primary while deterministic evidence constrains executable configuration.
- Generated workflows use immutable actions, read-only permissions, and disabled checkout credentials.
- Git dependencies and key tool versions are pinned.
- Repository publishing/write/token workflows are rejected.
- Source/API/archive sizes and timeouts are bounded.
- ZIP traversal, symlink, expansion ratio, and count protections are present.
- Browser flasher code is vendored and performs chip/hash/address checks.
- Mobile UI, safe areas, custom dialogs, focus states, reduced motion, and iOS limitation messaging exist.
- Migration checksums, advisory locking, semantic duplicate recovery, fresh-schema baseline, cache pruning, and MariaDB integration tests exist.

## Production test-account recommendation

Rotate the shared password. For future deep testing, create a synthetic account with a unique generated credential, a dedicated test repository, a low-limit AI key, no personal data, and automated cleanup. Do not reuse personal/provider credentials in chat.

## Remediation order

1. Unify CSP immediately.
2. Add authenticated external synthetic tests.
3. Move analysis to asynchronous immutable plans.
4. Add official board/library metadata resolution.
5. Persist plan approval/supersession and dispatch by digest.
6. Add signed artifact provenance.
7. Extract project profiles and centralize policy versions.
8. Add webhooks, streaming download tickets, and broader end-to-end tests.

## Conclusion

The codebase has no critical finding in this pass and retains a strong security baseline. It is not yet fully production-verifiable because authenticated live behavior was not reachable from the current sandbox and no independent synthetic monitor exists. The immediate code correction is the stale PHP CSP; the major remaining architectural work is asynchronous planning, official metadata, persisted approval, and signed artifacts.
