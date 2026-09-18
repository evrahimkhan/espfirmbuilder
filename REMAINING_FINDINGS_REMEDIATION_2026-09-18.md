# Remaining Findings Remediation

**Revision:** `05fe80b`  
**Quality run:** `35333011258` — passed

## Implemented

### Worker-bound materialization

Analysis workers now materialize final workflows from the immutable commit, target configuration, source evidence, AI dependency proposals, and pinned official metadata. The workflow is encrypted at rest and its SHA-256 is included in the build-plan digest. Dispatch only decrypts and verifies the prepared workflow; it no longer fetches source bundles, performs AI dependency analysis, or generates workflows. Migration `20260918_013_materialized_plans.sql` adds the encrypted payload, digest, and materialization timestamp. Dispatch fails closed for old, absent, or modified payloads.

### Signed artifact provenance

Artifact downloads now require an ESPForge manifest, bind its commit to the immutable build record, and add an Ed25519 publisher signature using a dedicated `ARTIFACT_SIGNING_KEY`. Signature verification canonicalizes the manifest and rejects modified content, forged signatures, wrong keys, and unknown key identifiers. The Web Flasher sends a selected manifest through the authenticated, CSRF-protected verification endpoint before accepting it and still independently checks firmware SHA-256, chip, size, and address. Production preflight requires PHP Sodium and a distinct non-placeholder signing key.

## Validation

Run `35333011258` passed PHP syntax, all policy/parser/workflow/provenance tests, npm audit and formatting, five browser tests with one intentional device skip, and MariaDB baseline/migration/conflict integration.

## Still externally blocked

The authenticated production monitor is implemented, but GitHub denied environment creation, secret installation, and workflow dispatch with `403 Resource not accessible by integration`. It cannot honestly be marked operational until repository administration grants environment/secrets/workflow permissions.

Production deployment must also apply migration 013, configure a distinct random `ARTIFACT_SIGNING_KEY`, pass preflight, and restart the analysis worker so existing repositories receive freshly materialized plans.

The bounded target catalogue deliberately does not claim to emulate all C/C++ compiler preprocessor semantics. Actual combined conditions are evaluated by the pinned compiler during the generated build; static discovery remains conservative. Full compiler-equivalent static preprocessing remains open if it is treated as a required feature rather than a non-goal.

## Honest current result

Source remediation: **0 critical, 1 externally blocked high, 1 medium, 0 low**. Production closure cannot be claimed until deployment and the external authenticated monitor succeed.
