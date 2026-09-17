# ESPForge Full Codebase and Live Review — Provider/Schema Pass

**Date:** 2026-09-18  
**Reviewed revision:** `71fd817`  
**Production:** `https://espforge.alwaysdata.net`  
**Quality run:** `35258584776` — passed

## Scope and integrity

The repository was reviewed across authentication and authorization, identity/key binding, repositories, target analysis, workflows, builds, downloads, flashing, frontend behavior, database/migrations, deployment, and tests. Public production routes were inspected again.

The supplied credential was not written to source, files, shell commands, logs, reports, or commits. The page-fetch route cannot retain a caller-controlled session cookie and the command-line HTTPS route previously failed TLS negotiation before login after three retries. Authenticated production testing therefore did not complete. This report does not claim authenticated settings, repositories, targets, builds, downloads, or flashes passed live testing. Rotate the disclosed password.

## Result

| Severity | Open |
|---|---:|
| Critical | 0 |
| High | 5 |
| Medium | 5 |
| Low | 4 |

Two additional medium findings are resolved at revision `71fd817`: comprehensive schema preflight and persisted GitHub verification status/time.

## Public production checks

- The HTTPS landing page is available and renders authentication and product content.
- Anonymous session creation returns a CSRF token.
- Anonymous settings access is rejected with `Authentication required`.
- Direct anonymous dashboard navigation redirects to the landing page.
- These checks establish the public access boundary only; they do not establish deployment of `71fd817` or authenticated behavior.

## Resolved in the latest batch

1. Production preflight now checks required columns, ordered compound indexes, foreign-key destinations and deletion rules, InnoDB, utf8mb4, important defaults, applied migrations, and migration checksums.
2. The complete preflight runs in the MariaDB 11.4 integration test.
3. GitHub verification result and timestamp are persisted and exposed to the authenticated UI.
4. Settings displays the last provider verification time.
5. Provider verification duration, outcome, and HTTP status are recorded in bounded operational metrics.
6. Operational metrics are pruned after 90 days.

## Open high findings

1. First-time target/source/AI analysis remains request-bound instead of running through a durable queue worker.
2. Targets and libraries are not prevalidated against pinned official metadata snapshots.
3. There is no independent persisted build-plan approval/supersession lifecycle with digest-bound dispatch.
4. Artifact provenance is hashed but not signed by an authenticated workflow identity.
5. There is no independent authenticated production synthetic monitor.

## Open medium findings

1. Project-specific Marauder dependency/compatibility behavior remains embedded in the generic workflow engine.
2. YAML anchors/block matrices and complex preprocessor selectors remain partially parsed.
3. GitHub blob batching lacks complete rate-limit, Retry-After, and fallback telemetry.
4. Artifact ZIP handling may buffer up to 100 MB in browser memory.
5. Build reconciliation remains polling-based rather than signed-webhook driven.

## Open low findings

1. Flash manifests do not always contain authoritative offsets.
2. Frontend JavaScript remains densely formatted and costly to maintain.
3. Browser E2E coverage for responsive behavior, OAuth restoration, dialogs, and Web Serial is absent.
4. Metrics now cover GitHub provider checks, but queue, cache, AI, download, and build lifecycle telemetry is still incomplete.

## Security and functional review

Strict sessions, secure cookies, CSRF, expiry/version invalidation, distributed rate limits, recent-auth checks, and owner-scoped repository/build/download/flash operations remain present. OAuth identities and AI keys retain exclusive account binding. Secrets and persistent analysis caches are encrypted. The self-only CSP has one source of truth.

AI remains primary while deterministic evidence constrains executable facts. Evidence now includes source path, SHA-256, excerpt, and line range. Target API and policy versions are explicit. PlatformIO, Arduino, and ESP-IDF active/disabled targets remain supported.

Generated workflows retain immutable actions, read-only permissions, disabled checkout credentials, pinned tools/dependencies, unsafe workflow rejection, immutable source provenance, configuration/workflow hashes, ZIP validation, and browser-side hash/chip/address verification. Signing and a durable approved-plan lifecycle remain absent.

Migration locking, checksums, semantic partial-DDL rejection, fresh-schema baseline, MariaDB integration, cache pruning, and the expanded production preflight all pass CI. Production must deploy the branch, run `php bin/migrate.php`, and then run `php bin/preflight.php` before the latest database/UI changes are live.

## Conclusion

The truthful current result is **0 critical, 5 high, 5 medium, and 4 low**. The latest revision closes two medium findings. The remaining findings require substantive queue, metadata, lifecycle, signing, webhook, streaming, parser/profile, browser-test, and monitoring work and cannot honestly be closed by changing a report.
