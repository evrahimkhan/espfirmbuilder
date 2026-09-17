# Hardware/AI Findings Final Remediation

AI is now the primary target analyst when an account has a configured AI provider. Its proposals are reconciled against deterministic repository evidence before executable configuration is accepted.

Implemented:

- AI runs for configured accounts even when deterministic targets exist.
- AI ordering and human-readable naming lead reconciled results.
- PlatformIO environment, ESP-IDF pairing, Arduino FQBN, and compiler facts remain deterministic trust boundaries.
- AI proposals matching repository configuration become `ai_verified` targets.
- Standalone AI Arduino targets are restricted to ESPForge's generic ESP32 board policy; board-specific options cannot be invented.
- Unverified AI PlatformIO environments, ESP-IDF pairings, boards, and menu options are rejected.
- AI responses include bounded confidence and exact repository evidence paths; nonexistent evidence paths are discarded.
- AI-only generic plans carry warnings and require explicit user confirmation before dispatch.
- ESP-IDF discovery now emits explicit chip/config pairs. Shared or ambiguous config files are not silently assigned.
- Selected ESP-IDF configs are validated and applied before build.
- Exact AI Arduino library package/version facts are constrained to the reviewed dependency registry.
- Arduino board configuration receives a board-details preflight against the installed core.
- Build rows now persist source commit SHA, analyzer version, AI model, canonical target JSON, and workflow SHA-256.
- Artifact manifests now include analyzer version and configuration SHA-256.
- Target and dependency caches are keyed by immutable commit, analyzer version, provider, model context, target, and key fingerprint.
- UI target cards identify AI-verified, inferred, and repository-verified plans and show evidence/warnings.
- Regression tests cover AI-primary reconciliation, rejection of invalid AI FQBN options, ESP-IDF config application, and manifest provenance.

Deployment requirement: apply `database/migrations/20260917_008_build_plan_provenance.sql` before deploying the PHP revision that inserts build-plan provenance fields.
