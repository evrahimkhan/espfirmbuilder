# ESPForge Full Codebase and Live Review — Policy Remediation Pass

**Date:** 2026-09-18  
**Source revision:** `bc68c07`  
**Production:** `https://espforge.alwaysdata.net`  
**Quality run:** `35257803934` — passed

## Scope and testing integrity

The complete application was reviewed across configuration, authentication, authorization, account/identity binding, secret handling, repository lifecycle, target analysis, workflow construction, builds, downloads, browser flashing, frontend behavior, migrations, deployment, and CI.

Public production endpoints and the deployed dashboard bundle were inspected. The supplied credentials were not placed in source, shell commands, logs, reports, or commits. Authenticated production testing could not be completed: the page-fetch transport does not retain a caller-controlled cookie jar, and the sandbox command-line HTTPS route previously failed TLS negotiation three times before login. This report does not claim authenticated settings, projects, targets, builds, downloads, or flashes were exercised live. Rotate the password because it was disclosed in chat.

## Result

| Severity | Open findings |
|---|---:|
| Critical | 0 |
| High | 5 |
| Medium | 7 |
| Low | 4 |

The policy-remediation batch resolved two medium and two low findings. Remaining findings are not documentation issues; they require queue/worker, registry snapshot, approval, signing, webhook, streaming, schema-verification, and browser-monitoring implementation.

## Production observations

- HTTPS landing page remains reachable and presents email, GitHub, and Google authentication entry points.
- Anonymous session creation returns a CSRF token.
- Protected project access rejects anonymous callers.
- Direct anonymous dashboard navigation redirects to the landing page.
- Deployed dashboard code includes mobile logout, custom modal handling, OAuth replay suppression, safe text rendering, live-build expansion/cancellation, two-second active-build refresh, manifest/hash/chip validation, vendored esptool, and iOS Web Serial messaging.
- Production demonstrably contains the earlier dashboard remediation. Deployment of `7d68a08` and `bc68c07` cannot be established from the public JavaScript bundle alone.

## Newly verified remediations

1. AI evidence records now bind an exact repository path to full-content SHA-256, bounded excerpt, and line range.
2. Analyzer, prompt, library-prompt, workflow, profile, package-map, and target-schema versions are centralized in `AppPolicy`.
3. AI model selection is configurable and used consistently for analysis, recorded provenance, and key validation.
4. Target API responses declare schema version `1.0` and expose the complete policy-version set.
5. Quality run `35257803934` passed PHP syntax, security-header, policy-version, parser, workflow safety/generation, JavaScript syntax, and MariaDB migration integration.

## Open high findings

1. **First analysis is request-bound.** A durable analysis queue and independently run cron worker are absent.
2. **Official metadata is not resolved before presentation.** Board menus/packages and library compatibility need pinned, digest-addressed official registry snapshots.
3. **Build-plan approval is incomplete.** There is no independent plan entity with analyzing, ready, approved, superseded, dispatched, and failed states plus approver/time and digest-bound dispatch.
4. **Artifact provenance is unsigned.** Existing hashes do not authenticate publisher or workflow identity.
5. **No external authenticated production synthetic test.** Production identity/session/settings/projects/targets/builds/logout are not continuously verified from an independent controlled monitor.

## Open medium findings

1. Marauder-specific dependencies and compatibility transforms remain embedded in the generic workflow engine instead of a versioned project profile.
2. YAML block matrices/anchors and complex preprocessor selectors remain incompletely parsed.
3. GitHub blob batching lacks detailed retry-after, rate-limit, and fallback telemetry.
4. Artifact ZIP handling can buffer up to 100 MB in browser memory.
5. Build reconciliation polls GitHub instead of accepting verified signed webhooks.
6. Database preflight does not exhaustively compare foreign keys, defaults, index order, storage engine, and charset.
7. GitHub status verification can block on a cold provider request and does not expose a persisted last-verification time.

## Open low findings

1. Flash manifests do not always provide authoritative binary offsets.
2. Frontend application JavaScript remains densely formatted and difficult to maintain.
3. Automated browser coverage for responsive layouts, OAuth restoration, dialogs, and Web Serial is absent.
4. Operational latency, provider-error, queue, and cache metrics remain limited.

## Security and ownership review

Session strict mode, cookie-only sessions, HttpOnly, SameSite, secure production cookies, inactivity/absolute expiry, authentication regeneration, and server-side session invalidation remain present. Mutations use CSRF and rate limits. Repository, build, download, cancellation, and flash operations are owner-scoped. OAuth identities cannot silently replace or merge accounts. AI keys are encrypted, fingerprint-exclusive, retained independently of GitHub identity, and removable only by their owner.

The strict self-only CSP has one Apache source of truth. Persistent analysis caches are AES-256-GCM encrypted and legacy plaintext cache files are removed by pruning. Generated workflows use immutable action references, read-only permissions, and disabled checkout credential persistence. Unsafe publishing/write/token workflows are rejected.

## Analysis, build, and artifact review

AI remains primary while deterministic evidence forms the executable trust boundary. PlatformIO, Arduino, and ESP-IDF targets include active and disabled/commented definitions. Build provenance records immutable source commit, analyzer/model, selected configuration, workflow hash, and config digest. Generated workflows pin key dependencies, validate target configuration, and package manifests. ZIP retrieval enforces ownership and traversal, symlink, size, count, and expansion-ratio limits. Browser flashing validates manifest hashes, chip family, address, and firmware size.

## Database, deployment, and tests

Checksummed migrations run under a database advisory lock and reject conflicting partial DDL. Fresh-schema baseline and the complete migration chain pass against MariaDB 11.4. Production deployment still requires `php bin/migrate.php` followed by `php bin/preflight.php`; existing migrations 001–007 must not be re-baselined.

The branch and CI are clean at `bc68c07`. Source changes do not alter Alwaysdata until deployed.

## Conclusion

The honest result is **0 critical, 5 high, 7 medium, and 4 low**. Four findings were closed by revision `bc68c07`; the remaining sixteen require substantive runtime and infrastructure work. Authenticated live verification remains incomplete due the testing transport limitation, not because it was assumed successful.
