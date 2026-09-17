# ESPForge Full Codebase and Live Review — Profile/Offsets Pass

**Date:** 2026-09-18  
**Reviewed revision:** `dc3d5cc`  
**Production:** `https://espforge.alwaysdata.net`  
**Quality run:** `35269154950` — passed

## Scope and credential limitation

The complete repository was reviewed across configuration, authentication and authorization, identity/key ownership, repository lifecycle, queued target analysis, persisted build plans, project profiles, workflow generation, builds, downloads, flashing, frontend behavior, database/migrations, deployment, and CI. Public production routes were inspected again.

The supplied value is a bcrypt password hash, not a login password. ESPForge correctly expects the original password and verifies it against the stored hash; a bcrypt hash cannot be submitted as the password to authenticate. The hash was not written to files, commands, reports, or commits. Authenticated testing therefore could not use it. In addition, page fetch cannot retain a caller-controlled cookie jar and the command-line HTTPS route previously failed TLS negotiation before login. No protected live behavior is represented as tested. Because the hash and an earlier weak password were disclosed in chat, change the account password.

## Result

| Severity | Open |
|---|---:|
| Critical | 0 |
| High | 4 |
| Medium | 2 |
| Low | 2 |

This pass closes the generic-engine project-profile finding and the missing authoritative-offset finding.

## Public production observations

- Anonymous session creation returns a CSRF token.
- Anonymous flash API access is rejected with `Authentication required`.
- Anonymous dashboard navigation redirects to the landing page.
- HTTPS landing and authentication content remain available.
- Public responses do not prove deployment of the queue/profile/offset revisions or scheduled worker.

## Findings closed

### Project-specific compatibility isolation

ESP32 Marauder behavior is now owned by the versioned `MarauderProfile`, including repository detection, compatibility patches, d32 partition correction, and supplemental profile dependencies. `WorkflowEngine` delegates rather than containing project patch bodies. Regression tests fail if Marauder implementation details return to the generic engine.

### Authoritative flash offsets

Manifest generation consumes addresses emitted by supported toolchains from `flasher_args.json`, `flash_args`, and `flash_project_args`. Addresses are associated with exact binary basenames and parsed as numeric values. ESPForge does not infer addresses from filenames or chip families. Binaries without an authoritative toolchain address retain `null` and the manifest warns the user to verify them manually. Regression tests require offset extraction to remain present.

## Open high findings

1. Final workflow/source preparation remains request-bound after plan approval, although target and AI analysis are queued.
2. Board/package/library facts are not validated against pinned official metadata snapshots before plans become ready.
3. Artifact provenance has hashes but no authenticated publisher/workflow signature.
4. There is no independent authenticated production synthetic monitor.

## Open medium findings

1. YAML anchors/block matrices and complex preprocessor selectors remain partially parsed.
2. Build reconciliation polls GitHub instead of accepting verified signed webhooks.

## Open low findings

1. Frontend JavaScript remains densely formatted and costly to maintain.
2. Browser E2E coverage for responsive layouts, OAuth restoration, dialogs, queue polling, downloads, and Web Serial is absent.

## Architecture, security, and functional review

Target/source/AI analysis runs through durable jobs with row-lock claiming, bounded retries, encrypted results, metrics, and retention. Target selection creates immutable commit/analyzer/config-bound plans with SHA-256, approval identity/time, supersession, and dispatch state. Final workflow materialization remains in the request and is the remaining asynchronous-planning gap.

Session strict mode, secure/HttpOnly/SameSite cookies, CSRF, session expiry/versioning, recent-auth checks, distributed rate limits, and owner-scoped repository/build/download/flash operations remain present. OAuth identities cannot replace another account. AI keys remain encrypted, fingerprint-exclusive, independent of GitHub identity, and owner-removable. Persistent caches and queued analysis results are encrypted. CSP remains strict and single-source.

AI remains primary while deterministic evidence is the executable trust boundary. Evidence has path, full-content SHA-256, excerpt, and line range. Policy/model/schema versions are centralized. Active and disabled PlatformIO, Arduino, and ESP-IDF targets remain supported.

Generated workflows retain pinned immutable actions, read-only permissions, disabled credentials, bounded dependencies, unsafe write/publish/token rejection, and commit/config/workflow provenance. Artifact ZIPs stream through bounded temporary files and traversal/symlink/count/ratio validation. Browser flashing verifies manifest hash, chip, address, and size.

Migration locking/checksums, partial-DDL conflict rejection, fresh-schema baseline, exhaustive preflight, queue/plan schema, telemetry, project-profile isolation, offset extraction, PHP/JavaScript syntax, and MariaDB 11.4 integration pass in quality run `35269154950`.

## Deployment requirements

Deploy the current branch, run:

```bash
php bin/migrate.php
php bin/preflight.php
```

and schedule every minute:

```bash
cd /home/ACCOUNT/espforge && php bin/analysis-worker.php 3
```

Do not re-baseline migrations 001–007.

## Conclusion

The truthful count is **0 critical, 4 high, 2 medium, and 2 low**. The profile and authoritative-offset findings are resolved. Authenticated production testing was not possible with a password hash and the available network/session transports, so no protected behavior has been assumed successful.
