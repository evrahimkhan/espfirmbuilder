# ESPForge Full Codebase and Live Review — Queue/Plans Pass

**Date:** 2026-09-18  
**Reviewed revision:** `d1c0036`  
**Production:** `https://espforge.alwaysdata.net`  
**Quality run:** `35267404255` — passed

## Scope and review integrity

The complete repository was reviewed across configuration, sessions, authentication/authorization, OAuth and AI-key ownership, repositories, target analysis, queue processing, build-plan lifecycle, workflow generation, builds, downloads, flashing, UI, database/migrations, deployment, and CI. Public production routes were inspected again.

The supplied password was not written to source, files, shell commands, logs, reports, or commits. The page-fetch transport cannot retain a caller-controlled session cookie, and the sandbox command-line HTTPS transport previously failed TLS negotiation before login after three retries. Authenticated production testing did not complete. This report does not claim protected settings, repositories, targets, builds, downloads, or flashing were tested live. Rotate the disclosed password.

## Result

| Severity | Open |
|---|---:|
| Critical | 0 |
| High | 4 |
| Medium | 3 |
| Low | 3 |

The persisted build-plan lifecycle finding is closed. Request-bound analysis is substantially remediated, but remains open in narrower form because final workflow/source preparation still occurs during dispatch.

## Public production checks

- Anonymous session creation returns a CSRF token.
- Anonymous target access is rejected.
- Anonymous dashboard navigation redirects to the landing page.
- HTTPS landing and authentication content remain available.
- Public responses do not establish deployment of the queue migration, worker schedule, or revision `d1c0036`.

## Queue and plan review

### Completed

- Target/source/AI analysis is no longer executed by `targets.php`.
- Target requests enqueue a durable revision-bound job and return HTTP 202 with polling guidance.
- The browser polls without blocking navigation and displays an AI-analysis progress state.
- Repository connection pre-enqueues analysis.
- A CLI worker claims jobs using row locking and `SKIP LOCKED`.
- Jobs have queued, processing, completed, and failed states, bounded retries/backoff, attempts, timing, and metrics.
- Analysis results are encrypted at rest.
- Each target produces a persisted immutable build plan bound to repository, commit, analyzer version, target configuration, and SHA-256.
- Plans support ready, approved, superseded, dispatched, and failed states.
- Approver identity/time and dispatch time are persisted.
- New revisions supersede stale ready/approved plans.
- Dispatch requires completed analysis and a non-superseded plan; plan ID/digest enter the audit record.
- Old completed jobs and superseded plans have bounded retention.

### Remaining request-bound portion

Final workflow assembly, Arduino source-bundle retrieval, library resolution, compatibility transformation, workflow publication, and dispatch still occur in `builds.php`. Therefore the broad request-bound analysis finding cannot yet be marked fully resolved. The next architectural step is to have the worker materialize encrypted, digest-addressed workflow payloads per plan and make dispatch publish exactly an approved prepared payload.

## Open high findings

1. Final workflow/source preparation remains request-bound even though target and AI analysis are queued.
2. Board/package/library facts are not validated against pinned official metadata snapshots before plan readiness.
3. Artifact provenance hashes are not backed by an authenticated publisher/workflow signature.
4. No independent authenticated production synthetic monitor exercises protected production behavior.

## Open medium findings

1. Marauder-specific dependencies and compatibility transforms remain embedded in the generic workflow engine.
2. YAML anchors/block matrices and complex preprocessor selectors remain partially parsed.
3. Build reconciliation polls GitHub rather than accepting verified signed webhooks.

## Open low findings

1. Flash manifests do not always obtain authoritative offsets from each toolchain.
2. Frontend JavaScript remains densely formatted and expensive to maintain.
3. Browser E2E coverage for responsive layouts, OAuth restoration, dialogs, downloads, queue polling, and Web Serial is absent.

## Security and correctness review

Strict cookie sessions, CSRF, session expiry/versioning, recent-auth checks, distributed limits, and owner-scoped operations remain present. OAuth identities cannot replace another account. AI keys remain encrypted, fingerprint-exclusive, independent of GitHub identity, and owner-removable. Persistent cache and queued-analysis results are encrypted. CSP remains strict and single-source.

AI remains primary while deterministic evidence is the executable trust boundary. Evidence includes repository path, content SHA-256, excerpt, and line range. Policy/model/schema versions are centralized. PlatformIO, Arduino, and ESP-IDF active and disabled targets remain supported.

Generated workflows retain immutable actions, read-only permissions, disabled credential persistence, bounded/pinned dependencies, unsafe write/publish/token rejection, and commit/config/workflow provenance. Artifact downloads stream through bounded temporary files and ZIP safety checks. Browser flashing validates hashes, chip family, address, and size.

Migration checksums, advisory locking, semantic partial-DDL rejection, fresh-schema baseline, exhaustive preflight, MariaDB 11.4 integration, queue schema checks, and frontend/PHP tests all pass in run `35267404255`.

## Deployment requirements

Deploy the branch, then run:

```bash
php bin/migrate.php
php bin/preflight.php
```

Configure an Alwaysdata scheduled task every minute:

```bash
cd /home/ACCOUNT/espforge && php bin/analysis-worker.php 3
```

Do not re-baseline migrations 001–007.

## Conclusion

The truthful result is **0 critical, 4 high, 3 medium, and 3 low**. Persisted plan approval/supersession is resolved; request-bound analysis is narrowed to final workflow preparation. Authenticated live verification remains incomplete because of the available transport, not because success was assumed.
