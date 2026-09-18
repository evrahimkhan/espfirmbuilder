# ESPForge Full Codebase and Live Review — E2E/Production Pass

**Date:** 2026-09-18  
**Reviewed revision:** `e9d3467`  
**Production:** `https://espforge.alwaysdata.net`  
**Quality run:** `35327018784` — passed

## Scope and test integrity

The complete source was reviewed across configuration, authentication/authorization, identity/key ownership, repositories, analysis queue, immutable plans, profiles, workflows, builds, webhook reconciliation, downloads, flashing, frontend behavior, migrations, deployment, dependency security, and browser tests.

The supplied password was not written to source, files, shell commands, logs, reports, or commits. Authenticated production testing did not complete. In this pass, the public production root, anonymous session API, protected build API, and deployed dashboard asset all returned HTTP 500 through the previously working page-fetch route. Therefore no live behavior is claimed successful. The password should be rotated because it was disclosed in chat.

## Result

| Severity | Open |
|---|---:|
| Critical | 0 |
| High | 5 |
| Medium | 1 |
| Low | 0 |

Four architectural high findings and one parser medium finding remain. A new production-availability high finding is open because all attempted public routes returned HTTP 500 at review time.

## New live high finding: production currently returns HTTP 500

The following independent resources failed with HTTP 500 through the page-fetch route:

- `/`
- `/api/auth.php?action=session`
- `/api/builds.php`
- `/assets/dashboard.js?v=formatted-e2e-7`

Failure of a static JavaScript asset as well as PHP routes points toward a site/web-server/deployment-level problem rather than an authenticated application action. Alwaysdata site/error logs and site status must be checked before deeper authentication testing. Production availability should be rechecked from an ordinary browser/network to distinguish an Alwaysdata incident or access rule from an application deployment problem.

## Findings closed in source

### Frontend maintainability

Frontend JavaScript is formatted with pinned Prettier and formatting is enforced in CI. Node dependencies are lockfile-pinned. `npm audit --audit-level=high` reports zero vulnerabilities.

### Browser E2E coverage

Pinned Playwright tests execute on desktop Chromium and Pixel 7 Android emulation. They cover authentication dialogs, responsive/mobile navigation, mobile logout, Web Serial messaging, OAuth-restoration one-display behavior, dialog dismissal, viewport behavior, and mocked authenticated dashboard loading. Final CI result was five passed with one intentional device-specific skip.

### Browser artifact memory

The previous browser-memory closure was corrected after a deeper rereview found `response.blob()`. Downloads now use a native CSRF-protected POST form and browser `Content-Disposition`; JavaScript no longer buffers complete ZIP files. PHP still streams GitHub data through bounded temporary storage, archive validation, and 1 MB output chunks.

## Open high findings

1. Final workflow/source materialization still runs during the dispatch HTTP request rather than being prepared as a digest-addressed worker payload.
2. Board/package/library facts are not validated against pinned official metadata snapshots before plans become ready.
3. Artifact provenance has hashes but lacks an authenticated publisher/workflow signature and pre-flash signature verification.
4. No independent authenticated production synthetic monitor continuously exercises protected workflows.
5. Production public routes returned HTTP 500 during this review and require operational diagnosis/recovery.

## Open medium finding

1. YAML anchors/block matrices and complex preprocessor selector combinations remain only partially parsed.

## Security and source review

Strict sessions, secure/HttpOnly/SameSite cookies, CSRF, expiry/version invalidation, recent-auth checks, distributed limits, and owner scoping remain present. OAuth identities cannot replace another account. AI keys remain encrypted, fingerprint-exclusive, independent of GitHub identity, and owner-removable. Persistent caches and queued results are encrypted. CSP remains strict and single-source.

AI remains primary while deterministic evidence is the executable trust boundary. Evidence includes path, full-content SHA-256, excerpt, and line range. Analysis jobs use locking, retries/backoff, encryption, metrics, and retention. Plans retain immutable revision/config digests, approval identity/time, supersession, and dispatch state. The missing `AppPolicy` build include is fixed and regression-tested.

Marauder behavior is isolated in a versioned profile. Workflows retain immutable actions, read-only permissions, disabled credential persistence, pinned dependencies, unsafe-operation rejection, provenance hashes, and toolchain-provided offsets. Signed webhook reconciliation is repository/workflow/UUID bound with replay protection. Artifact ZIP and browser flashing checks remain bounded and ownership-aware.

CI run `35327018784` passed PHP syntax, CSP, policy, telemetry, queue/plan, profile, webhook, parser, workflow safety/generation, dependency audit, formatting, browser E2E, and MariaDB 11.4 migration/preflight checks.

## Immediate production diagnostics

From Alwaysdata SSH or its browser terminal:

```bash
cd ~/espforge
git log -1 --oneline
php bin/preflight.php
tail -100 ~/admin/logs/php/php.log
tail -100 ~/admin/logs/apache/error.log 2>/dev/null || true
```

Also inspect **Web → Sites** for site state, document root, PHP version, and recent errors. The root must remain `/home/espforge/espforge/public`. No database migration is introduced by the frontend/E2E batch.

## Conclusion

The source-level result is **0 critical, 4 high, 1 medium, and 0 low**. Including the currently observed production HTTP 500 condition, the live combined result is **0 critical, 5 high, 1 medium, and 0 low**. Authenticated verification cannot proceed until public production availability is restored.
