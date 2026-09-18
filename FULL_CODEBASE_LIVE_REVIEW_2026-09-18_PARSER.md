# ESPForge Full Codebase and Live Review — Parser Pass

**Date:** 2026-09-18  
**Reviewed revision:** `76c3984`  
**Production:** `https://espforge.alwaysdata.net`  
**Quality run:** `35329360846` — passed

## Scope and integrity

The complete repository was reviewed across configuration, authentication/authorization, account and key ownership, repositories, AI analysis, deterministic parsing, queues, plans, profiles, workflows, builds, webhooks, downloads, flashing, responsive UI, migrations, deployment, dependencies, and tests. Public production routes were inspected again.

The supplied password was not written to source, files, shell commands, logs, reports, or commits. Authenticated production testing could not complete because the available page transport cannot preserve a caller-controlled login cookie and direct TLS/SSH transports are blocked. Protected behavior is not represented as live-tested. Rotate the password because it was disclosed in chat.

## Result

| Severity | Open |
|---|---:|
| Critical | 0 |
| High | 4 |
| Medium | 1 |
| Low | 0 |

## Public production checks

- HTTPS landing page is reachable.
- Anonymous session creation returns a CSRF token.
- Anonymous target access for repository 17 is rejected with `Authentication required`.
- Production public availability remains restored.
- These anonymous checks do not prove authenticated target/build/download/flash behavior.

## Parser remediation verified

Workflow target discovery now supports bounded inline and block-style `matrix.include` rows, arbitrary supported field order, quoted/unquoted scalar values, YAML anchors, safe merge aliases, Arduino matrix metadata, and ESP-IDF matrix metadata. Parsed values retain strict FQBN, macro, version, path, length, and row-count validation. YAML is treated as bounded metadata and is not executed or deserialized into arbitrary objects.

Regression coverage includes inherited anchor values, overridden target identity/name, block ESP-IDF targets, and existing inline matrices. The full suite passed in quality run `35329360846`.

The remaining parser finding is now limited to full semantic interpretation of arbitrarily nested C/C++ preprocessor expressions. ESPForge intentionally discovers eligible macros without pretending to be a complete compiler preprocessor; executable configuration remains validated by generated build checks.

## Open high findings

1. Final workflow/source materialization remains in the dispatch HTTP request instead of a digest-addressed worker-prepared payload.
2. Board/package/library facts are not validated against pinned official metadata snapshots before plans become ready.
3. Artifact provenance hashes lack an authenticated publisher/workflow signature and pre-flash signature verification.
4. No independent authenticated production synthetic monitor continuously exercises protected workflows.

## Open medium finding

1. Arbitrarily nested/combined C preprocessor expressions are not semantically evaluated as a complete compiler would evaluate them.

## Security and functional review

Strict sessions, secure/HttpOnly/SameSite cookies, CSRF, expiry/version invalidation, recent-auth checks, distributed limits, and owner-scoped operations remain present. OAuth identities cannot replace another account. AI keys remain encrypted, fingerprint-exclusive, independent of GitHub, and owner-removable. Persistent caches and queue results are encrypted. CSP remains strict and single-source.

AI remains primary while deterministic evidence is the executable trust boundary. Evidence includes path, complete-content SHA-256, excerpt, and line range. Jobs use locking, retries/backoff, encryption, metrics, and retention. Plans retain immutable commit/config digest, approval identity/time, supersession, and dispatch state.

Project compatibility is profile-isolated. Workflows use immutable actions, read-only permissions, disabled credentials, pinned dependencies, unsafe-operation rejection, provenance hashes, and authoritative toolchain offsets. Signed webhook reconciliation is replay-protected and repository/workflow/UUID bound. Downloads use native browser POST and bounded server streams. Browser flashing validates hash, chip, address, and size.

Frontend formatting is enforced. Node audit reports zero vulnerabilities. Desktop and Android Playwright coverage passes. MariaDB migration, schema baseline, checksum, partial-DDL, and exhaustive preflight integration passes.

## Conclusion

The truthful current result remains **0 critical, 4 high, 1 medium, and 0 low**. Workflow matrix and YAML anchor parsing are resolved; only full compiler-level preprocessor semantics remain in the parser finding. Authenticated production verification remains unavailable from this environment and is not assumed successful.
