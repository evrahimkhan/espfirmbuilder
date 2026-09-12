# ESPForge Extreme Code, Security, and Flow Review

**Review date:** 2026-09-12  
**Reviewed branch:** `arena/01a07f43-espfirmbuilder` at `cbcf764`  
**Scope:** PHP APIs, session/authentication flows, OAuth, database schema/migrations, GitHub integration, generated workflows, artifact downloads, AI-key handling, browser Web Serial flashing, frontend DOM handling, operational controls, and CI.

## Executive summary

ESPForge is unusually well hardened for a shared-hosting MVP. It consistently checks resource ownership, encrypts credentials, applies CSRF defenses, bounds most remote responses, rejects unsafe workflows and archives, pins generated-build dependencies, and has meaningful CI regression coverage. I found **no confirmed unauthenticated remote-code execution, SQL injection, cross-account IDOR, plaintext-secret disclosure, or arbitrary-file-write vulnerability**.

The most important remaining issue is in the Web Flasher trust model: the so-called verified manifest proves only that a selected binary matches a user-selected JSON file. It does **not** prove that the manifest came from ESPForge/GitHub, and manifest-provided flash offsets are not validated before use. This can create misleading provenance and device-safety risk. OAuth initiation via authenticated GET, unbounded AI-key test responses, distributed rate-limit limitations, and frontend CDN dependency trust are the next priorities.

### Risk count

| Severity | Count | Summary |
|---|---:|---|
| Critical | 0 | No confirmed critical exploit found |
| High | 2 | Manifest authenticity semantics; unvalidated flash offsets |
| Medium | 5 | OAuth initiation CSRF/flow forcing; unbounded provider validation response; single-node rate limiting; third-party browser module trust; OAuth state concurrency/session growth |
| Low / hardening | 8 | Download-error UX, broad GitHub token scope, weak context separation for encrypted fields, no DB migration ledger, absolute-URL GitHub client capability, limited audit visibility, manual file memory use, missing method allowlists on some endpoints |

---

## High-priority findings

### H-1 — Flash manifests are hash manifests, not authenticated/signed manifests

**Location:** `public/assets/dashboard.js:54-55`, `src/WorkflowEngine.php:58-90`

The browser accepts any local JSON object with `version`, `files`, and `chip`, then checks that the chosen binary SHA-256 equals the digest in that same local JSON. An attacker who can replace the firmware can also replace the JSON and recalculate the hash. There is no signature, trusted public key, server-issued MAC, GitHub artifact attestation verification, or authenticated retrieval binding the manifest to the build record.

**Impact:** The UI can report `manifest_verified=true` for a malicious or unrelated binary/manifest pair. Hash consistency is useful for corruption detection but is not provenance or authenticity. This is particularly important because firmware executes below the application layer and can persist on a physical device.

**Recommended remediation:**

1. Rename the current result to **“hash matched”** until authenticity exists.
2. Generate an asymmetric signature over a canonical manifest in the workflow using GitHub OIDC-backed signing (for example Sigstore/cosign attestations) or sign server-side with a dedicated offline/managed signing key.
3. Bundle signature and certificate/provenance, and verify them in-browser against a pinned trust root.
4. Bind the manifest to repository, full commit SHA, GitHub run ID, artifact ID, target chip, partition/offset map, and every flashed file.
5. Do not accept `manifest_verified=true` from the browser as an authoritative security event; call it `hash_matched` unless the server can verify evidence.

### H-2 — Flash offsets are not validated before writing

**Location:** `public/assets/dashboard.js:55,64`; `public/dashboard.html:7`

The manifest can set `entry.offset` to an arbitrary number/string. It is copied into the offset input and later processed with `parseInt(value, 16)`. The input’s HTML `pattern` is not enforced because flashing is initiated by a button rather than form validation. There is no explicit check for `NaN`, negative values, alignment, integer range, overlap, chip flash bounds, or dangerous bootloader/partition regions.

**Impact:** A malformed or malicious manifest can cause an invalid write request, flash the wrong address, overwrite a bootloader/partition table, or brick/recovery-loop a device. esptool may reject some values, but device safety should not depend on undocumented downstream behavior.

**Recommended remediation:**

- Parse only `/^0x[0-9a-fA-F]{1,8}$/` or a bounded safe integer.
- Require `Number.isSafeInteger(address)` and `0 <= address < chipFlashLimit`.
- Validate `address + firmwareSize <= chipFlashLimit` and required alignment.
- For signed multi-file manifests, validate non-overlap and use a framework/chip-specific allowed map.
- For a manually selected single application binary, default to a known application offset and clearly warn before writes to bootloader/partition-table ranges.
- Refuse flashing when the detected chip from `loader.main()` differs from the selected/manifest chip; currently the selected chip is checked against the manifest but not clearly against the physically detected chip string.

---

## Medium-priority findings

### M-1 — OAuth initiation is a state-changing authenticated GET

**Location:** `public/api/auth.php:84-100`, OAuth links in `public/index.html` and `public/dashboard.html`

Starting GitHub/Google authorization mutates session OAuth state and, while signed in, records account-link ownership. It is initiated by GET without a CSRF token. SameSite=Lax cookies are intentionally sent on top-level cross-site navigation, so another site can force the browser into an OAuth/linking flow. Provider consent and callback state checks prevent silent arbitrary callback injection, but forced navigation, state replacement, and login/linking confusion remain possible.

**Remediation:** Start OAuth with a CSRF-protected POST. Have that endpoint create state and return the provider URL, then assign `location.href` in first-party JavaScript. Keep callback state validation. Add PKCE where supported and an explicit `intent=login|link|delete-capability` bound into server-side state.

### M-2 — AI-key test endpoint buffers an unbounded remote response

**Location:** `public/api/settings.php:39-46`

The key-test cURL call uses `CURLOPT_RETURNTRANSFER` without a write-size cap. Most other outbound clients correctly cap responses. A faulty or compromised provider could return a very large body and exhaust PHP memory or tie up a shared-hosting worker.

**Remediation:** Reuse a common bounded HTTP client with a 1–2 MB write callback, connect and total timeouts, strict 2xx/JSON checks, TLS verification, and sanitized errors. Add response content-type validation.

### M-3 — Rate limits are host-local and restart-bypassable

**Location:** `src/bootstrap.php:70-90`

The limiter uses files under `sys_get_temp_dir()`. It is concurrency-safe on one filesystem, but counters may disappear on restart, may not be shared among multiple PHP hosts, and can be bypassed if traffic reaches separate workers with non-shared temp storage. On some proxies, all anonymous users may share one `REMOTE_ADDR`, allowing accidental or deliberate denial of service.

**Remediation:** Move security-sensitive limits to MySQL or Redis using atomic increments and expiry. Use a trusted-proxy configuration before reading forwarded client IPs—never blindly trust `X-Forwarded-For`. Combine per-account, per-IP, and global provider-operation budgets.

### M-4 — Browser flashing depends on live third-party ESM execution

**Location:** `public/assets/dashboard.js:59`, CSP in `src/bootstrap.php` and `public/.htaccess`

Version-scoped jsDelivr paths substantially reduce risk, but the flasher still executes CDN-delivered code at runtime without SRI. If the CDN/package version is compromised, code runs in the authenticated ESPForge origin and can access session-bound APIs and selected firmware bytes. CSP does not protect against an allowed compromised script.

**Remediation:** Vendor the complete dependency graph and stub assets during a trusted build, verify package integrity in CI, serve immutable local assets, and pin hashes in a lockfile. If vendoring is temporarily infeasible, isolate flashing on a separate origin with no authenticated application session and communicate with a tightly validated `postMessage` protocol.

### M-5 — Single mutable OAuth state causes concurrent-flow failures and stale map growth

**Location:** `public/api/auth.php:87-99,102-104,186`

The session keeps one current `oauth_state` but also state-indexed linking/deletion maps. Starting OAuth in a second tab invalidates the first flow. Abandoned state-indexed entries are not bounded or expired until a successful callback clears one state. This is mainly reliability/session-size risk, not identity bypass, because callback comparison is strict.

**Remediation:** Store a bounded map of hashed states with creation time, provider, intent, initiating user, and PKCE verifier. Consume exactly one entry atomically. Expire after 5–10 minutes and cap outstanding states (for example, five per session).

---

## Low-priority and defense-in-depth findings

### L-1 — Download errors may be hidden by frame protections

**Location:** `public/assets/dashboard.js:27`; `X-Frame-Options: DENY` and `frame-ancestors 'none'`

Downloads target a hidden same-origin iframe to preserve streaming. Attachment responses should download, but JSON error responses can be blocked from rendering by the application’s own frame policy, preventing the attempted modern error dialog. Users may see no explanation.

**Remediation:** Add a CSRF-protected prepare endpoint that validates build/artifact availability and returns a short-lived one-time download ticket. Show prepare errors in the normal dialog, then navigate to a streaming GET/POST endpoint using the consumed ticket.

### L-2 — GitHub OAuth token scope has a large blast radius

**Location:** `public/api/auth.php:94`

Classic OAuth requests `repo workflow`, and optional fork deletion requests `delete_repo`. This is functional but powerful. A stolen decrypted token can access all private repositories authorized by that classic OAuth grant.

**Remediation:** Prefer a GitHub App with per-repository installation permissions if fork creation constraints can be solved explicitly. Separate deletion capability into a short-lived token and avoid replacing the standard stored token with an elevated token longer than needed. Document revocation.

### L-3 — Secret encryption lacks field/account context separation

**Location:** `src/bootstrap.php:93-95`

AES-256-GCM is used correctly with random nonces, but all encrypted fields derive the same raw key and use no AAD. Database ciphertext could potentially be swapped between compatible secret fields/accounts and still decrypt, although downstream providers generally reject mismatched tokens.

**Remediation:** Derive per-purpose keys with HKDF (`github-token`, `ai-api-key`) and authenticate AAD containing schema version, user ID, provider, and field name. Version ciphertext envelopes for rotation.

### L-4 — AI keys can be saved without proving provider ownership/validity

**Location:** `public/api/settings.php:21-30,65-71`

Testing is optional. A typo or intentionally supplied string can be exclusively fingerprint-bound and block another account that later presents the same value. Knowledge of a real key is already sensitive, but “exclusive ownership” semantics are stronger if binding follows provider validation.

**Remediation:** Validate a new key before first binding, perform fingerprint uniqueness check and save in one transaction, and return a clean `409` on unique-index races.

### L-5 — GitHub client supports arbitrary absolute URLs

**Location:** `src/GitHubClient.php:10`

`request()` accepts paths beginning with `http` and still attaches the GitHub bearer token. Current callers use controlled URLs, so there is no demonstrated SSRF/token-exfiltration path today. It is a dangerous future footgun.

**Remediation:** Reject absolute URLs or enforce exactly `https://api.github.com/`. Use a separate unauthenticated/allowlisted download client for any external URL.

### L-6 — Migrations have no first-class ledger

**Location:** `database/migrations/`

Migrations are manual and not recorded by an application migration table. Operators can accidentally rerun non-idempotent `ALTER TABLE` or `CREATE TABLE` statements, or deploy code ahead of schema changes.

**Remediation:** Add a CLI-only migration runner with a `schema_migrations` table, checksums, advisory lock, transaction where supported, dry-run/status modes, and explicit refusal on checksum drift.

### L-7 — Audit events are collected but lack operator review/alerting

**Location:** `src/bootstrap.php:99`, `audit_events`

Useful events exist, but there is no documented alerting for repeated identity conflicts, deletion attempts, reset abuse, dispatch spikes, or audit-write failures.

**Remediation:** Add an operator-only CLI report or external log drain, alert thresholds, and request IDs. Never expose raw IPs or secret-bearing metadata.

### L-8 — HTTP method allowlists are inconsistent

Some APIs treat every non-GET request as mutation logic, and auth actions do not all explicitly reject unexpected methods. CSRF limits exploitation, but strict method handling improves correctness and observability.

**Remediation:** Return `405` with `Allow` on every endpoint/action before rate-limited business logic.

---

## Flow and correctness review

### Authentication

**Strengths:** Timing-resistant password checks, verified-email requirement, one-time hashed reset/verification tokens, session regeneration, absolute and idle session expiry, session-version revocation, neutral recovery responses, bounded request bodies, and modern dialogs.

**Flow risks:** Concurrent OAuth tabs conflict; registration depends on synchronous PHP `mail()`; no queue/retry observability; a production mail slowdown can occupy PHP workers. Consider transactional outbox + scheduled sender.

### OAuth identity ownership

**Strengths:** Provider IDs are unique, Google requires verified email, GitHub verified-email endpoint is used, email matching does not auto-merge workspaces, and an existing linked identity cannot be replaced.

**Flow risks:** Classic OAuth scope is broad; account linking would benefit from an explicit recent-auth challenge and POST initiation. OAuth callback database writes span multiple statements without a surrounding transaction, although uniqueness constraints prevent the most dangerous races.

### Repository and build lifecycle

**Strengths:** Ownership joins are consistently applied; repository duplicates are blocked in DB and code; operations are locked; arbitrary imported workflows are fail-closed; generated workflows use read-only permissions, immutable action SHAs, timeouts, pinned tools, and checksum verification.

**Flow risks:** Shared-host filesystem locks only serialize within hosts sharing that temp directory. Use MySQL advisory locks for multi-host deployment. Build reconciliation is polling-based and can temporarily mismatch runs; UUID correlation is a strong mitigation. GitHub webhooks with signature verification would reduce polling and latency.

### Artifact handling

**Strengths:** Authenticated ownership check, CSRF-protected POST, size-bounded streaming to temp files, mandatory ZIP inspection, zip-bomb limits, traversal/control-character/symlink rejection, nested ZIP reinspection, cleanup handlers, and artifact disambiguation.

**Residual risks:** No malware/content semantic scanning (reasonable for firmware), download starts only after full archive staging/inspection (disk pressure under concurrency), and temp capacity is not globally budgeted. Add a global semaphore and free-space check.

### Frontend/XSS

**Strengths:** Dynamic text generally uses `textContent` or `escapeHtml`; provider errors are not inserted as HTML; CSP blocks inline scripts; dialogs replace native popups; external links use `noopener`.

**Residual risks:** Large template strings with inline `onclick` increase maintenance risk, though IDs are database integers and dynamic text is escaped. Move handlers to event delegation and adopt Trusted Types when browser support/deployment permits.

### Web Serial

**Strengths:** HTTPS requirement, explicit user device selection, 16 MB firmware limit, SHA-256 consistency check, selected-chip check against manifest, scrollable terminal, and no server upload of local firmware.

**Residual risks:** The two high findings above; full firmware is held as ArrayBuffer and then converted to a binary string, briefly multiplying memory use on mobile. iOS Safari does not support Web Serial, so responsive UI can be cross-platform but flashing cannot honestly be universal.

### Operations

**Strengths:** Production preflight, secret rotation helper, pruning helper, fail-closed production config, security headers, no-store responses, migrations, CI syntax/regression tests, and a documented Alwaysdata process.

**Residual risks:** Shared hosting is not a production SLA, backups/restore are not tested in code, and live TLS/header/physical-device behavior must be monitored externally.

---

## Prioritized remediation roadmap

### Phase 1 — Device safety and truthful trust indicators

1. Strictly validate flash address, size, alignment, overlap, and detected physical chip.
2. Rename `manifest_verified` to `hash_matched` in UI/API/schema semantics.
3. Design and implement signed/attested manifests.
4. Add browser tests for malicious offsets, NaN, overflow, wrong chip, modified manifests, and multi-file overlap.

### Phase 2 — OAuth and outbound HTTP

1. Convert OAuth initiation to CSRF-protected POST and add PKCE/intent-bound expiring state records.
2. Bound AI key-test responses and centralize outbound HTTP policy.
3. Restrict `GitHubClient` to the GitHub API origin.
4. Add transactional OAuth linking and AI-key binding.

### Phase 3 — Distributed operations

1. Move rate limits and operation locks to MySQL/Redis.
2. Add a migration ledger/runner.
3. Add a mail outbox, retries, and delivery telemetry.
4. Add signed GitHub webhooks for build updates.

### Phase 4 — Supply chain and browser isolation

1. Vendor/lock the complete esptool-js dependency graph.
2. Put flasher functionality on a session-isolated origin if third-party runtime code remains.
3. Generate SBOMs and attest generated workflow artifacts.
4. Add Dependabot/Renovate with tests before pin updates.

---

## Pro tips

1. **Call hashes “integrity,” signatures “authenticity.”** This single wording discipline prevents dangerous user assumptions.
2. **Detect the physical chip and make it authoritative.** The dropdown/manifest can narrow expectations, but the connected silicon must decide compatibility.
3. **Treat GitHub Actions as hostile build input.** Keep permissions read-only, never expose ESPForge secrets, pin every action/tool, and consider artifact attestations.
4. **Separate trust domains.** Landing/auth, account dashboard, and hardware flasher benefit from separate origins and cookies.
5. **Use database advisory locks on shared hosting.** Filesystem locks are excellent locally but can silently fail as a distributed coordination strategy.
6. **Test recovery, not just backup.** Quarterly restoration drills are more valuable than merely confirming that backup files exist.
7. **Keep a canary repository and canary ESP board.** Automatically build daily; physically flash after significant flasher changes.
8. **Log security outcomes, not secrets.** Record provider, operation, stable request ID, latency class, and sanitized status—never token, key, code, or email link.
9. **Budget every untrusted dimension.** Bytes, rows, files, redirects, recursion, runtime, concurrency, retries, and remote response size all need explicit limits.
10. **Use progressive enhancement language for mobile.** ESPForge’s dashboard can support iOS, but Web Serial currently requires compatible Chromium-based desktop/Android environments and USB support.
11. **Create incident runbooks now.** Include APP key compromise, GitHub OAuth secret compromise, malicious workflow, leaked AI key, database breach, and bad firmware release.
12. **Add a security contact and disclosure policy.** A `SECURITY.md` with supported versions and private reporting instructions reduces public zero-day disclosure risk.

---

## Validation performed and limitations

- Inspected all first-party PHP APIs and core classes, database schema/migrations, frontend JavaScript sinks, CSP/security headers, generated workflow controls, and operational scripts.
- Searched for dangerous execution/deserialization/hash primitives, raw input usage, redirects, remote requests, and DOM injection sinks.
- Confirmed recent GitHub Actions quality runs pass PHP syntax, parser/workflow safety regressions, generated-workflow checks, and JavaScript syntax.
- The review is source-based, not a full penetration test. It did not use authenticated production accounts, intercept OAuth traffic, test mail-provider delivery, exhaust concurrency/disk, compromise a CDN, or flash adversarial images onto physical boards.
- Passing preflight and CI demonstrates configuration/schema and regression health; it does not prove absence of logic vulnerabilities.

## Overall assessment

**Current posture:** Strong MVP security baseline with no confirmed critical web compromise path.  
**Release recommendation:** Suitable for controlled beta after fixing strict flash-offset/chip validation and correcting manifest “verification” language. Treat signed provenance, OAuth POST/PKCE, bounded provider validation, and local browser dependencies as the next security milestones before broader production use.
