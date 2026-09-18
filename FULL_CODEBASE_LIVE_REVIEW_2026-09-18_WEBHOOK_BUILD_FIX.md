# ESPForge Full Codebase and Live Review — Webhook/Build-Fix Pass

**Date:** 2026-09-18  
**Reviewed revision:** `4287797`  
**Production:** `https://espforge.alwaysdata.net`  
**Quality run:** `35280192173` — passed

## Scope and testing integrity

The complete repository was reviewed across configuration, authentication/authorization, account and API-key ownership, repository operations, durable analysis, immutable plans, project profiles, workflow generation, builds, signed webhook reconciliation, downloads, flashing, frontend behavior, migrations, deployment, and tests. Public production endpoints were inspected again.

The supplied password was not written to source, files, shell commands, logs, reports, or commits. Authenticated HTTP testing remains unavailable because the page-fetch transport cannot retain a caller-controlled cookie jar and the command-line HTTPS route previously failed TLS negotiation. SSH from the sandbox was also closed before an SSH banner. This report does not claim protected production behavior was tested. Rotate the password because it was disclosed in chat.

## Result

| Severity | Open |
|---|---:|
| Critical | 0 |
| High | 4 |
| Medium | 1 |
| Low | 2 |

Signed webhook reconciliation is now closed. The production build-dispatch regression caused by a missing `AppPolicy` include was identified from request reference `723ae5756272`, fixed, regression-tested, and passed in CI.

## Production observations

- Anonymous session creation returns a CSRF token.
- Anonymous build access is rejected with `Authentication required`.
- The GitHub webhook endpoint is deployed and rejects GET with `Method not allowed`, confirming the endpoint is present and method-restricted.
- Public endpoint behavior establishes deployment through the webhook batch, but does not independently prove that `4287797` is deployed because that correction changes authenticated PHP behavior only.

## Closed findings and regression

### Signed webhook reconciliation

The webhook validates GitHub `X-Hub-Signature-256` using HMAC-SHA256 and constant-time comparison, bounds request size, validates event/delivery metadata, deduplicates deliveries, accepts only the generated ESPForge workflow, validates statuses/conclusions, and binds reconciliation to repository plus UUID or an existing run ID. Delivery and outcome telemetry has bounded retention. Polling remains a resilience fallback rather than the primary design.

### Build dispatch regression

`builds.php` referenced `AppPolicy::ANALYZER_VERSION` without requiring `AppPolicy.php`. The resulting production fatal occurred before plan approval, matching the observed ready plans. Revision `4287797` adds the dependency and a regression assertion. Quality run `35280192173` passed.

## Open high findings

1. Final workflow/source materialization still runs during the dispatch HTTP request rather than being prepared by the queue worker as a digest-addressed payload.
2. Board/package/library facts are not validated against pinned official metadata snapshots before plans become ready.
3. Artifact provenance includes hashes but lacks an authenticated publisher/workflow signature and verification before flashing.
4. No independent authenticated production synthetic monitor continuously checks protected workflows.

## Open medium finding

1. YAML anchors/block matrices and complex preprocessor selector combinations remain only partially parsed.

## Open low findings

1. Frontend JavaScript remains densely formatted and costly to maintain.
2. Browser E2E coverage for responsive layouts, OAuth restoration, dialogs, queued analysis, build dispatch, downloads, webhooks, and Web Serial is absent.

## Security and functional review

Strict sessions, secure/HttpOnly/SameSite cookies, CSRF, expiry/version invalidation, recent-auth checks, distributed limits, and owner-scoped operations remain present. OAuth identities cannot replace another account. AI keys remain encrypted, fingerprint-exclusive, independently retained, and owner-removable. Persistent caches and queued results are encrypted. CSP remains strict and single-source.

AI remains primary while deterministic evidence is the executable trust boundary. Evidence includes path, full-content SHA-256, excerpt, and line range. Policy/model/schema versions are centralized. PlatformIO, Arduino, and ESP-IDF active/disabled targets remain supported. Analysis runs through durable jobs with locking, retries, backoff, metrics, and retention. Plans retain immutable revision/config digest, approval identity/time, supersession, and dispatch state.

Marauder behavior is isolated in a versioned project profile. Workflows retain immutable actions, read-only permissions, disabled credential persistence, pinned dependencies, unsafe-operation rejection, and provenance hashes. Toolchain-emitted offsets are preserved without guessing. Downloads stream through bounded temporary storage and ZIP safety checks. Browser flashing validates hash, chip, address, and size.

Migration checksums/locking, semantic partial-DDL rejection, fresh-schema baseline, exhaustive preflight, queue/plans, webhook schema, profile/offset behavior, PHP/JavaScript syntax, and MariaDB 11.4 integration all pass in run `35280192173`.

## Deployment

Production should be at revision `4287797` or later. No migration is required specifically for the AppPolicy include fix. The webhook migration and queue migration must remain applied, preflight must pass, and the analysis worker must run every minute.

## Conclusion

The truthful current count is **0 critical, 4 high, 1 medium, and 2 low**. Signed event-driven reconciliation is resolved, and the observed build fatal is fixed and covered by CI. Authenticated production verification remains constrained by the available transports and is not assumed successful.
