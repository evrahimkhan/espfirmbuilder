# ESPForge Full Codebase and Live Review — Telemetry/Streaming Pass

**Date:** 2026-09-18  
**Reviewed revision:** `ef65c47`  
**Production:** `https://espforge.alwaysdata.net`  
**Quality run:** `35266201783` — passed

## Scope and integrity

The complete repository was reviewed across configuration, authentication, authorization, identity and API-key ownership, repository operations, AI/deterministic target analysis, workflow generation, builds, status reconciliation, downloads, flashing, frontend behavior, database migrations, production preflight, deployment, and CI.

Public production routes were inspected again. The supplied password was not written to source, files, shell commands, logs, reports, or commits. Authenticated live testing could not complete because page retrieval cannot retain a caller-controlled cookie jar and the command-line HTTPS route previously failed TLS negotiation before login after three retries. This report does not claim protected production features were exercised. Rotate the disclosed password.

## Result

| Severity | Open |
|---|---:|
| Critical | 0 |
| High | 5 |
| Medium | 3 |
| Low | 3 |

This pass closes GitHub batching telemetry, artifact browser-memory buffering, and the general operational-metrics finding.

## Public production checks

- Anonymous session creation succeeds and returns a CSRF token.
- Direct anonymous build API access is rejected with `Authentication required`.
- Anonymous dashboard navigation redirects to the landing page.
- HTTPS landing and authentication content remain available.
- These checks verify the public access boundary only. They do not prove deployment of `ef65c47`.

## Findings closed in this pass

### GitHub retrieval telemetry

GitHub API calls now record duration, outcome, HTTP status, rate-limit remaining, and Retry-After. Repository paths are represented only by a short route hash. Parallel immutable-blob batches record requested, parallel-success, fallback count, duration, and outcome. AI-provider requests record provider, status, duration, and outcome.

### Artifact buffering

The earlier finding was revalidated against the actual implementation and is closed. GitHub artifacts are streamed into a bounded temporary file, inspected as ZIP archives, and streamed to the browser in 1 MB chunks. The dashboard uses a normal POST download rather than reading the archive into JavaScript memory. Nested archives are copied through bounded streams. Neither PHP nor browser application code buffers a 100 MB artifact as one in-memory object.

### Operational metrics

Metrics now cover GitHub connection checks, general GitHub API calls, blob batches/fallbacks, AI providers, and downloads. Data has bounded 90-day retention, privacy-preserving route identifiers, and regression coverage. Queue/build lifecycle metrics remain part of the not-yet-implemented queue architecture rather than an independent low finding.

## Open high findings

1. First-time analysis remains request-bound instead of using a durable queue/cron worker.
2. Board/package/library choices are not prevalidated against pinned official metadata snapshots.
3. There is no independent persisted build-plan state/approval/supersession lifecycle and digest-bound dispatch.
4. Artifact provenance contains hashes but no authenticated publisher/workflow signature.
5. No independent authenticated production synthetic monitor exercises protected production behavior.

## Open medium findings

1. Marauder-specific dependencies and compatibility patches remain embedded in the generic workflow engine.
2. YAML anchors/block matrices and complex preprocessor selectors remain incompletely parsed.
3. GitHub build reconciliation polls rather than accepting verified signed webhooks.

## Open low findings

1. Flash manifests do not always receive authoritative offsets from each project toolchain.
2. Frontend JavaScript remains densely formatted and expensive to maintain.
3. Browser E2E coverage for responsive behavior, OAuth restoration, custom dialogs, downloads, and Web Serial is absent.

## Security and functional review

Strict sessions, secure/HttpOnly/SameSite cookies, CSRF, expiry/version invalidation, recent authentication, distributed limits, and owner-scoped repository/build/download/flash operations remain present. OAuth identities cannot replace or merge another account. AI keys remain encrypted, fingerprint-exclusive, independent of GitHub identity, and owner-removable. Persistent analysis caches are encrypted. CSP remains strict and single-source.

AI remains primary while deterministic repository evidence forms the executable trust boundary. Evidence includes path, complete-content SHA-256, excerpt, and line range. Policy/model/schema versions are centralized. PlatformIO, Arduino, and ESP-IDF active and disabled targets remain supported.

Generated workflows retain immutable actions, read-only permissions, disabled credential persistence, bounded/pinned tools, unsafe write/publish/token rejection, commit/config/workflow provenance, and artifact safety checks. Browser flashing validates hash, chip, address, and size. Signing, durable plans, official metadata snapshots, and authoritative offsets remain open.

Database migration checksums, advisory locking, conflicting partial-DDL rejection, full-schema baseline, MariaDB 11.4 integration, and expanded schema preflight all pass. CI diagnostics now surface preflight details in annotations. The successful run is `35266201783`.

## Deployment

Source changes are not live until deployed. Apply the branch and run:

```bash
php bin/migrate.php
php bin/preflight.php
```

Do not re-baseline migrations 001–007.

## Conclusion

The truthful count is **0 critical, 5 high, 3 medium, and 3 low**. Three findings were closed in this pass. The remaining eleven require queue/worker, official metadata, plan lifecycle, signing, synthetic monitoring, profile/parser, webhook, offset, source-maintainability, and browser-E2E implementation.
