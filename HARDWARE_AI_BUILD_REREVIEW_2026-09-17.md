# Hardware Target and AI Build Re-review

**Date:** 2026-09-17  
**Reviewed branch:** `arena/01a07f43-espfirmbuilder`  
**Reviewed through:** `5632628`  
**Focus:** hardware target discovery/selection, selected configuration application, AI fallback and dependency output, generated build plans, caching, and tests.

## Executive summary

The latest remediation materially improved the design. Deterministic collectors are merged instead of first-match-only, PlatformIO catalogues are unioned, inline workflow fields are order-independent, duplicate target identities are namespaced, ESP-IDF configuration now has an application step, AI dependencies are constrained to a reviewed set, target provenance is visible, and immutable-SHA caches reduce repeat analysis.

However, the implementation does **not yet close every finding from the previous review**. The largest unresolved issue is that AI Arduino FQBNs still become selectable after grammar validation only; `arduino-cli board details` validates them later on a GitHub runner, after the user starts a build. ESP-IDF configuration grouping can still choose the first file arbitrarily. There is still no immutable persisted build-plan/provenance record, and AI target/dependency decisions are not fully reviewable before dispatch.

**Current findings:** 0 critical, 4 high, 7 medium, 5 low.

## Verified improvements

- Workflow, PlatformIO, ESP-IDF, and Arduino define collectors now contribute to one candidate set.
- AI runs only when deterministic collectors produce no targets.
- PlatformIO `default_envs` no longer prevents collection of `[env:...]` sections.
- Inline workflow matrix extraction is independent of field ordering and supports common quoting.
- Target identities are framework-namespaced and workflow evidence supersedes duplicate config evidence.
- Generic S3 fallback now requires an active macro and is marked inferred.
- The target dialog displays verified/inferred source badges, evidence, and warnings when available.
- Selected ESP-IDF config is copied to `sdkconfig` before the ESP-IDF action.
- Arduino builds run `board details` before compilation.
- AI dependency output is constrained to exact entries in a reviewed internal package/version set.
- Target and AI-library results have persistent, atomic, bounded caches keyed by immutable tree SHA and analyzer/provider identity.
- Parser, safety, generation, and JavaScript test suites are independently reported and passing.

## High findings

### H1. AI Arduino FQBNs remain unverified at selection time

`AITargetAnalyzer::validate()` accepts any three-segment FQBN and option string matching a broad character grammar. It does not restrict the vendor/architecture to the ESP32 package or verify board IDs and board-specific menu values. An AI result can therefore be displayed as a hardware choice even when the board or option does not exist.

The new `arduino-cli board details` step is useful, but it runs only after workflow dispatch, tool installation, and runner startup. It converts a compiler error into a clearer runner error; it does not make target analysis deterministic.

**Required fix:** resolve every AI Arduino target against pinned ESP32 package metadata before returning `/api/targets.php`. Store canonical FQBN, package-index digest, core version, board name, and valid menu selections. Reject—not merely warn on—unknown board IDs/options.

### H2. ESP-IDF configuration selection can be arbitrary and can mismatch the selected chip

Generic ESP-IDF discovery groups all paths associated with a chip into `config_paths`. `configurationStep()` silently takes element zero. Path ordering comes from the repository tree and broad filename/content inference, not an explicit hardware-to-config relationship. A shared file that mentions multiple chips can be associated with multiple targets.

Copying an arbitrary defaults file directly to `sdkconfig` may also conflict with the action's selected `target`, and no check verifies that the copied config contains the same `CONFIG_IDF_TARGET`.

**Required fix:** create one target per proven `(idf_target, config file)` pairing, show the config filename to the user, reject ambiguous pairings, validate its target assignment, and distinguish full `sdkconfig` from `SDKCONFIG_DEFAULTS` semantics.

### H3. There is still no immutable persisted build plan

The server rediscovers targets and regenerates a workflow during dispatch. The build row stores target ID/name but not:

- analyzed source/tree SHA;
- analyzer and prompt schema version;
- AI provider/model;
- canonical selected FQBN/config;
- verified dependency set;
- package-index digest;
- configuration digest;
- generated workflow digest/commit.

The runtime cache improves speed but is not an auditable build plan. A build cannot be reconstructed or compared reliably from its database record.

**Required fix:** persist a reviewed build-plan entity keyed by repository SHA and target ID; dispatch only from that immutable plan and attach its digest to the build row and artifact manifest.

### H4. AI-derived target evidence is still too weak for safe configuration approval

AI targets receive `source: ai`, but no evidence files/lines, confidence, assumptions, rejected alternatives, core version basis, or dependency-resolution summary. The UI badge says “AI inferred,” yet selection immediately dispatches with no explicit review/confirmation step.

**Required fix:** require AI to cite only supplied evidence paths and excerpts, validate citations against the snapshot, display the complete proposed plan and warnings, and require explicit confirmation before first dispatch of an AI-only target.

## Medium findings

### M1. The “reviewed registry” is a hard-coded compatibility map, not verified package metadata

Constraining AI libraries to the internal map prevents arbitrary suggestions from executing and is a clear security improvement. It does not verify current package availability, architecture compatibility, transitive constraints, or index digest. Unknown AI results are silently discarded.

**Fix:** resolve against a pinned/cached Arduino Library Manager index, record canonical package metadata/digest, and return unresolved suggestions to the UI instead of silently dropping them.

### M2. AI analysis still blocks first-time target/build requests

Caches help repeated analysis, but the first request can fetch many GitHub files sequentially and wait up to 60 seconds for AI. Arduino dispatch still builds a source bundle on every build even when target/AI library results are cached.

**Fix:** create analysis asynchronously at connect/sync time, cache a bounded source-derived build plan by SHA, and make dispatch a short database/GitHub operation.

### M3. Persistent file-cache lifecycle is unmanaged

Cache entries expire logically on read but are not pruned. The cache has no total quota, ledger, lock around competing writers beyond atomic rename, or operational metrics. Every analyzer-version/key/commit combination can leave a file permanently.

**Fix:** add scheduled pruning, global/user quotas, cache hit/miss/error metrics, and tests for concurrent writes and malformed entries.

### M4. Analyzer versions are scattered string literals

`targets-v2` and `libraries-v2` live in API code. Prompt/model/parser changes can accidentally reuse old results unless a developer remembers to update each literal.

**Fix:** centralize version constants and derive cache/build-plan schema IDs from parser, prompt, model, registry, and profile versions.

### M5. Workflow YAML support remains limited to inline objects

The parser improved field order and quote handling but still ignores standard block-style matrix entries, YAML aliases, anchors, expressions, and nested mappings. A partial inline match can coexist with missed block entries without an incompleteness warning.

**Fix:** use a bounded YAML parser and explicitly traverse matrix `include` objects; add fixtures for block mappings, reordered fields, aliases, and malformed input.

### M6. Project-specific compatibility remains in the generic workflow engine

Marauder-specific ODR patches, partition overrides, dependencies, and fingerprints remain embedded in `WorkflowEngine`. Each new board failure adds another generic-engine branch.

**Fix:** move these declarations to a versioned project profile with explicit fingerprint, supported source revisions/targets, toolchain constraints, patches, and regression fixtures.

### M7. Arduino target validation is split across inconsistent paths

Workflow-derived FQBNs use `TargetAnalyzer::validEsp32Fqbn()`, while AI FQBNs use a different broader regex. Define-derived FQBNs are generated internally, and compatibility overrides occur later in `WorkflowEngine`. There is no single canonical resolver.

**Fix:** introduce one `ArduinoBoardResolver` used by every source, after compatibility/profile policy, before target presentation and workflow generation.

## Low findings

### L1. AI dependency audit counts discovered suggestions, not accepted verified dependencies

The audit count is recorded before `WorkflowEngine` filters suggestions through the reviewed map. Operators cannot distinguish proposed, accepted, and rejected dependencies.

### L2. Target labels can still be misleading

Macro labels are generated mechanically with `ucwords`, and inferred targets can appear alongside product names without model descriptions or board photos/pin evidence. This is safe but can cause user selection mistakes.

### L3. Fallback Arduino selection can survive without proving a buildable sketch

The fallback returns Arduino ESP32/S3 based on source evidence, while actual sketch selection happens later in workflow generation. A target may be shown and then fail because no valid primary sketch exists.

### L4. Cache content is integrity-addressed but not confidential

Cache filenames use HMAC and permissions are private, but values are plaintext JSON. Current cached values are mostly target/package metadata; future expansion must not place source excerpts, secrets, or private repository content there without encryption.

### L5. Test coverage still lacks end-to-end build-plan fixtures

Current tests validate parser snippets and workflow strings. There are no complete repository fixtures asserting the final selectable targets and generated commands for mixed PlatformIO/Arduino/ESP-IDF projects, AI-only projects, ambiguous sdkconfigs, or invalid AI FQBN/menu options.

## Regression risks introduced by the remediation

1. **Target IDs changed format.** Existing UI requests use fresh IDs, but external clients/bookmarks and cached selections using old raw IDs are incompatible. The API should version responses or provide migration aliases.
2. **More targets may now be shown.** Merging all collectors can expose low-confidence define candidates that were previously hidden by authoritative workflow targets. Provenance helps, but collector confidence/filtering needs stronger policy.
3. **`sdkconfig` overwrite is consequential.** Copying configuration is correct only when the discovered file is explicitly paired and semantically a full sdkconfig.
4. **AI libraries can disappear silently.** Security improved, but users are not told that an AI suggestion was rejected by the internal registry.

## Required next implementation sequence

1. Build a canonical Arduino board/package resolver and validate targets before presentation.
2. Model ESP-IDF targets as explicit chip/config pairs and reject ambiguity.
3. Add immutable database-backed build plans and provenance fields/manifests.
4. Add AI evidence/citation validation and a confirmation screen.
5. Resolve libraries through pinned official metadata and expose rejected/unresolved items.
6. Move analysis out of synchronous dispatch and cache the final plan, not just partial results.
7. Extract project profiles from `WorkflowEngine`.
8. Replace remaining YAML/config regex limitations and add end-to-end fixtures.

## Conclusion

The remediation improved target coverage, safety boundaries, visibility, and repeat performance. The system is still best described as **sanitized AI-assisted workflow generation**, not yet **deterministically verified hardware configuration**. The decisive next step is a canonical, registry-backed, immutable build plan created before the user presses Build.
