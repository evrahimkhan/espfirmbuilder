ALTER TABLE builds
  ADD COLUMN source_commit_sha CHAR(40) NULL AFTER target_name,
  ADD COLUMN analyzer_version VARCHAR(40) NULL AFTER source_commit_sha,
  ADD COLUMN ai_model VARCHAR(100) NULL AFTER analyzer_version,
  ADD COLUMN target_config_json JSON NULL AFTER ai_model,
  ADD COLUMN workflow_sha256 CHAR(64) NULL AFTER target_config_json;

CREATE INDEX idx_builds_source_plan ON builds(repo_id, source_commit_sha, target_id);
