# Hardware and AI Build Remediation

Implemented from `HARDWARE_AI_BUILD_REVIEW_2026-09-17.md`:

- Deterministic target collectors now merge workflow, PlatformIO, ESP-IDF, and Arduino configuration evidence instead of stopping at the first nonempty detector.
- PlatformIO `default_envs` entries are unioned with discovered `[env:...]` sections.
- Inline workflow matrix fields are parsed independently of field order and support single/double-quoted values.
- Target identities are namespaced and deterministic; stronger workflow evidence supersedes duplicate config evidence.
- AI remains fallback-only and cannot replace proven deterministic targets.
- Generic fallback no longer selects S3 from incidental prose; it requires an active board macro and is visibly marked inferred.
- Target selection UI identifies verified configuration versus AI/fallback inference, and displays evidence/warnings.
- Selected ESP-IDF sdkconfig is validated and copied into place before the ESP-IDF build action.
- Arduino FQBNs receive an explicit `arduino-cli board details` preflight against the installed selected core before compilation.
- AI Arduino dependencies are accepted only when exact package and version match ESPForge's reviewed package registry. Unknown AI package facts are not emitted into workflows.
- Target analysis is cached persistently by repository, immutable tree SHA, analyzer version, provider, and AI-key fingerprint, with a bounded session fast path.
- AI library analysis is cached by repository SHA, target, analyzer version, provider, and key fingerprint.
- Cache writes are atomic, bounded to 2 MB, HMAC-addressed, private-permission files under ignored runtime storage.
- Quality workflow now reports parser, workflow-safety, and workflow-generation suites independently.

Remaining operational note: the initial source snapshot still requires bounded GitHub content retrieval. Subsequent target and AI analysis for the same immutable commit use persistent cache results.
