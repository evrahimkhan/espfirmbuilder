# ESPForge Full Codebase and Live-Site Review

**Date:** 2026-09-18  
**Repository revision:** `65f84d5`  
**Live URL:** `https://espforge.alwaysdata.net`  
**Scope:** complete source tree plus externally reachable production pages. Authentication, authorization, OAuth, secrets, database/migrations, repository operations, AI-primary target planning, workflow generation, builds, cache, downloads, browser flashing, responsive UI, deployment, and tests were reviewed.

## Testing note

The public production landing page and authentication redirect behavior were reachable and inspected. The unauthenticated dashboard correctly redirected to the landing page. A sandbox TLS connection failure prevented the command-line authenticated session from completing, so no claim is made that authenticated production APIs were exercised successfully in this run. The supplied credential was not written to the repository or any persisted workspace file.

Because a real password was shared for testing, rotate it after testing is complete.

## Executive summary

No critical vulnerability was identified in the reviewed source. The strongest areas remain account isolation, encrypted credentials, CSRF and rate limiting, server-side resource ownership, generated-workflow restrictions, archive validation, and AI output confinement.

Five high findings remain. Initial source/AI analysis is still request-bound; official Arduino metadata is not resolved before target presentation; build-plan approval is not a complete persisted lifecycle; artifact provenance is unsigned; and migration correctness lacks real database integration coverage despite improved semantic checks. Several medium issues affect correctness, reliability, and production operations.

**Findings:** 0 critical, 5 high, 9 medium, 7 low.

## Live-site observations

- HTTPS public landing page responded successfully.
- `/dashboard.html` without an authenticated session redirected to the public landing page.
- Landing content, auth dialog content, feature links, and security claims rendered in fetched output.
- Public copy accurately describes GitHub Actions and browser flashing at a high level.
- The landing page still says users can flash “directly from the browser” without placing the iOS limitation in the primary marketing copy; the limitation is communicated inside the authenticated flasher.
- Authenticated API behavior could not be independently confirmed because the sandbox command-line TLS handshake to the host failed, even though the page-fetch route succeeded.

## High findings

### H1. First-time repository and AI analysis remains request-bound

Parallel blob batches significantly reduce GitHub source retrieval latency, and caches improve repeated requests. However, the first target request still performs repository inspection and can wait up to 60 seconds for the AI provider. Build dispatch still constructs Arduino source context in the request path.

**Impact:** slow first build, PHP worker exhaustion on shared hosting, proxy timeout risk, and user-visible “lag” tied to GitHub/AI availability.

**Required fix:** create background analysis jobs at connect/sync time, persist progress and immutable plans, and make Build dispatch a ready approved plan. Shared hosting can use a cron-driven database queue if no resident worker is available.

### H2. Official board and library metadata is still not resolved before target presentation

AI-primary reconciliation rejects unproven executable facts and standalone AI targets are narrowly constrained. Exact Arduino board/menu validation still occurs later on the Actions runner. Library acceptance relies on an internal curated map rather than a pinned official index with architecture metadata.

**Impact:** failures are discovered after dispatch; package availability/compatibility can drift; “AI verified” may be interpreted more strongly than warranted.

**Required fix:** ingest pinned ESP32 package/`boards.txt` and Arduino Library Manager snapshots, validate before returning targets, and store registry digests in the build plan.

### H3. Build-plan review and approval is not a complete server-side lifecycle

Build rows store source SHA, analyzer version, AI model, target JSON, and workflow digest at dispatch. AI-only generic targets receive a browser confirmation. There is no independent plan with `analyzing`, `ready`, `approved`, `superseded`, and `failed` states; no persisted approval actor/time; and no dispatch-by-plan-ID/digest contract.

**Impact:** plans cannot be reviewed asynchronously, approval cannot be audited, and changed source/configuration is not modeled as plan supersession.

**Required fix:** add a `build_plans` entity, persist evidence/dependencies/warnings/config patches, approve server-side, supersede on source or policy change, and dispatch only by immutable plan ID plus digest.

### H4. Artifact provenance remains unsigned

The manifest includes source/configuration/analyzer identity and firmware hashes, but no trusted signature. Hash matching proves only that the selected binary matches the selected manifest.

**Impact:** an attacker controlling artifact delivery can replace both files consistently.

**Required fix:** use GitHub OIDC/Sigstore or a protected signing service and verify repository, workflow, commit, certificate identity, and transparency record before flashing.

### H5. Migration safety lacks real MySQL/MariaDB integration tests

Semantic fresh-schema checks now avoid MariaDB integer display-width mismatches, advisory locking/checksums exist, and partial objects are inspected. These paths are not executed in CI against a real database. The migration splitter and recovery logic are central deployment code validated only by syntax checks.

**Impact:** production remains the first place where DDL, JSON aliases, implicit commits, duplicate recovery, grants, and information-schema differences are exercised.

**Required fix:** add MySQL 8 and supported MariaDB CI services covering fresh schema baseline, legacy baseline, normal upgrade, every partial migration-008 state, conflicting objects, checksum drift, and preflight.

## Medium findings

### M1. Parallel blob retrieval has limited observability and retry policy

`blobBatch()` bounds each body and falls back to serial Contents API retrieval, but does not expose per-batch timing, curl error details, rate-limit headers, retry-after behavior, or aggregate fallback counts.

### M2. Analyzer/cache/profile versions are scattered

Target, dependency, prompt, model, workflow, registry, and project patch versions do not derive one canonical build-plan policy digest.

### M3. AI evidence is path-level rather than claim-level

Evidence paths must exist, but line ranges, excerpt hashes, and claim types are not verified. Confidence is bounded but not policy-calibrated for repository-matched AI results.

### M4. Marauder-specific behavior remains in the generic workflow engine

Dependencies, ODR fixes, UART ownership, battery object ownership, and partition overrides should be a versioned fingerprinted project profile.

### M5. YAML and preprocessor parsing remain incomplete

Block-style matrix YAML, anchors/aliases, expressions, `#if 0`, block comments, and richer target selectors are not fully modeled.

### M6. Download success buffers the complete archive in browser memory

Blob-based errors are reliable, but successful downloads can consume up to the 100 MB server limit on mobile.

### M7. Build status and GitHub connection remain polling/provider-call dependent

No signed webhook path provides immediate authoritative updates. Settings scope status can wait for GitHub when cache is cold.

### M8. Schema preflight is still selective

It does not comprehensively assert all foreign keys, cascade rules, defaults, collations, engines, unique constraints, JSON validity checks, and ordered indexes.

### M9. Production authenticated flows lack automated synthetic checks

There is no safe test account monitor exercising login, session, settings, repositories, targets, build-plan readiness, and logout without dispatching a real build.

## Low findings

1. Landing-page primary flashing copy does not immediately mention iOS limitations.
2. Cache metadata is plaintext and must not expand to private source excerpts without encryption.
3. Expired/corrupt cache entries are removed only by scheduled pruning/quota pressure.
4. AI model identifiers remain hard-coded.
5. Flash manifests usually lack tool-derived offsets.
6. Frontend source is densely formatted and difficult to maintain.
7. Namespaced target ID compatibility/versioning for older clients is undefined.

## Confirmed security strengths

- OAuth identities cannot silently replace or merge accounts.
- AI API keys are encrypted and exclusively fingerprint-bound to one account.
- GitHub and AI tokens are not returned to browsers.
- Sessions use secure production cookies, strict mode, HTTP-only, SameSite, inactivity/absolute expiry, and session-version invalidation.
- State-changing operations use CSRF protection.
- Distributed rate limits cover sensitive endpoints.
- Repository, build, cancel, and download operations enforce user ownership.
- GitHub status is validated rather than inferred from token presence.
- Delete-fork capability is separately requested and displayed.
- Generated workflows use immutable action references and read-only permissions.
- Checkout credentials are disabled and Git dependencies are commit-pinned.
- Repository workflow publishing/token/write behavior is rejected.
- External responses, source bundles, downloads, and archives are bounded.
- ZIP traversal, symlink, entry count, expansion ratio, and total-size checks are present.
- Web flasher dependencies are vendored and CSP is same-origin for executable code.
- Binary chip, offset, size, and manifest hash checks precede flashing.
- iOS Web Serial absence is communicated in the flasher.

## Confirmed functional strengths

- AI is primary for configured accounts while deterministic evidence controls executable configuration.
- Invalid standalone AI board-specific options are rejected.
- PlatformIO default and section targets are merged.
- ESP-IDF targets use explicit chip/config pairs and ambiguous shared configs are omitted.
- Target cards expose source/evidence/warnings.
- Build provenance fields and manifest configuration digests exist.
- Active/history builds, cancellation, downloads, elapsed time, and persistent navigation are implemented.
- Responsive desktop/mobile navigation, safe areas, custom dialogs, focus styles, reduced motion, and mobile logout exist.
- Migration checksums, locking, CLI errors, semantic bootstrap checks, and cache pruning exist.

## Recommended remediation order

1. Add real MySQL/MariaDB migration CI and expand schema assertions.
2. Move target/source/AI work into asynchronous immutable plans.
3. Add pinned official board/library metadata resolution.
4. Add persisted plan approval/supersession and dispatch by digest.
5. Add signed artifact provenance.
6. Extract project profiles and centralize version policy.
7. Add signed GitHub webhooks and streaming download tickets.
8. Add authenticated production synthetic tests and operational metrics.
9. Replace fragile parser paths and add browser/device automation.

## Test-account recommendation

After this review, rotate the supplied password. For ongoing synthetic testing, create a dedicated least-privilege account with:

- no reusable personal password;
- no personal repositories;
- no production AI key with broad spending limits;
- a dedicated test GitHub organization/repository;
- provider-side spending/rate limits;
- automatic credential rotation and cleanup.

## Conclusion

ESPForge has a strong security baseline and no critical finding in this revision. It is not yet fully deterministic, asynchronously scalable, or cryptographically supply-chain-verifiable. The next engineering milestone should combine real database integration tests, background immutable build planning, official package metadata, and signed artifact provenance.
