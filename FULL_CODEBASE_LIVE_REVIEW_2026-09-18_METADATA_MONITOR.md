# ESPForge Full Codebase and Live Review — Metadata and Monitor Pass

**Date:** 2026-09-18  
**Revision:** `fc97760`  
**Production:** `https://espforge.alwaysdata.net`  
**Quality run:** `35332166706` — passed

## Result

| Severity | Open |
|---|---:|
| Critical | 0 |
| High | 3 |
| Medium | 1 |
| Low | 0 |

The pinned-official-metadata high finding is closed in source. Three infrastructure/provenance findings and the bounded preprocessor finding remain open.

## Codebase review

The repository was reviewed across bootstrap/configuration, authentication and OAuth identity binding, API key ownership, CSRF/session controls, repository ownership and synchronization, analysis queues, AI-first target discovery, deterministic evidence, target parsing, immutable build plans, workflow generation, dispatch, webhook reconciliation, build polling/cancellation, artifact and log downloads, flashing, responsive UI, database schema/migrations, deployment, pruning, preflight checks, CI, and browser tests.

Quality run `35332166706` passed all syntax, security, policy, queue, metadata, parser, workflow, browser, dependency, formatting, and MariaDB integration checks. The dependency audit reports zero vulnerabilities.

## Closed: pinned official metadata

`config/official-metadata.json` records bounded, source-attributed official ESP32/ESP-IDF/PlatformIO/library metadata. `OfficialMetadata` validates executable board, target, toolchain, and package facts before a plan becomes ready. Its complete SHA-256 is included in each target configuration and therefore in the immutable build-plan digest. Unknown executable identities fail closed. Regression coverage rejects unknown Arduino and ESP-IDF targets.

This closes the prior absence of a pinned official metadata trust input. Refreshing that snapshot remains an explicit reviewed source change rather than a mutable network lookup during dispatch.

## Production checks

- The HTTPS landing page is reachable.
- Anonymous session creation returns a fresh CSRF token.
- Anonymous access to `/api/projects.php` is rejected with `Authentication required`.
- The authenticated monitor implementation uses temporary cookies, rotated CSRF values, TLS restrictions, bounded timeouts, login, a protected read-only repository request, and logout.
- Attempting to create the `production-monitor` GitHub environment, set its secrets, and dispatch it failed with GitHub `403 Resource not accessible by integration`. Therefore the monitor is implemented but not operational, and the finding remains open.
- The supplied credentials were not committed or written to workspace files. They were submitted only to GitHub CLI secret-setting stdin, but GitHub rejected the operation.
- Direct authenticated production inspection remains unavailable because page retrieval cannot preserve a controlled login cookie and command-line production TLS transport has previously failed. It is not represented as completed.

## Remaining high findings

1. Final workflow/source materialization still happens in the user-facing dispatch request rather than a digest-addressed worker preparation stage.
2. Artifact manifests and firmware hashes are not authenticated with a publisher signature that is verified before flashing.
3. The authenticated production monitor cannot run until repository administration permits creation/configuration of its protected GitHub environment and secrets.

## Remaining medium finding

1. Target discovery does not provide full compiler-equivalent semantics for arbitrary nested and combined C/C++ preprocessor expressions.

## Conclusion

The honest result is **0 critical, 3 high, 1 medium, 0 low**. Source quality and public production boundaries pass the checks available here. Authenticated production behavior remains unverified, and infrastructure-dependent controls are not counted as operational merely because their code exists.
