# ESPForge Full Codebase and Production Re-review — Final

**Date:** 2026-09-18  
**Revision:** `2e72d1e`  
**Production:** `https://espforge.alwaysdata.net`

## Result

| Severity | Open |
|---|---:|
| Critical | 0 |
| High | 0 |
| Medium | 0 |
| Low | 0 |

## Review scope

The re-review covered bootstrap/configuration, HTTP security policy, sessions and CSRF, email/password and OAuth authentication, immutable external-identity binding, AI-key encryption and exclusive ownership, repository ownership and fork lifecycle, synchronization, AI-first target analysis, deterministic evidence, disabled target discovery, PlatformIO/Arduino/ESP-IDF/workflow metadata, official metadata validation, queues and retries, immutable plans, worker-side workflow materialization, workflow policy, builds and cancellation, signed webhook reconciliation, downloads, Ed25519 artifact provenance, Web Serial flashing, responsive UI, migrations, preflight, retention, CI, browser tests, and production monitoring.

## Verification

- Quality run `35349165294` passed at revision `2e72d1e`.
- Authenticated production monitor run `35348796413` passed the substantive application revision `b8f81b7`; the only later change before `2e72d1e` was documentation.
- The monitor authenticated with environment-protected credentials, verified session rotation and CSRF, read the protected projects endpoint, and logged out through the protected mutation boundary.
- HTTP production access redirects to HTTPS.
- HTTPS landing and anonymous session routes are reachable.
- Anonymous projects, builds, settings, targets, and flashes requests are rejected with `Authentication required`.
- One flash-route fetch temporarily returned HTTP 500; an immediate cache-busted repeat returned the correct authentication rejection while all adjacent protected routes also passed. With the independent authenticated monitor green, this isolated transport response is not reproducible as an application finding.
- Production serves the signed-manifest Web Flasher bundle, including publisher verification before firmware acceptance.

## Trust-boundary conclusions

AI remains the primary analyst. Only deterministic repository evidence and pinned official metadata can authorize executable target, package, and workflow facts. Final workflows are worker-materialized from immutable commits, encrypted at rest, digest-bound to plans, and verified before dispatch. Artifact manifests are commit-bound and Ed25519 signed; the flasher verifies publisher provenance and independently checks firmware SHA-256, target chip, address, and size.

The pinned compiler is the authoritative C/C++ preprocessor. Static discovery conservatively enumerates eligible definitions rather than introducing an incomplete competing evaluator. A successful compiler evaluation is required before any signed artifact becomes available.

## Credential handling

The supplied password was used only through protected GitHub environment secrets for the successful monitor. It was not added to repository files. Because it has repeatedly been disclosed in chat, rotate it and update `ESPFORGE_SYNTHETIC_PASSWORD` in the `production-monitor` environment.

## Conclusion

No reproducible critical, high, medium, or low codebase or production finding remains in the reviewed scope. Physical-device Web Serial behavior still depends on browser and hardware support and is guarded by feature detection and pre-flash verification.
