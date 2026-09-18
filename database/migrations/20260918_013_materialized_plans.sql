ALTER TABLE build_plans
 ADD COLUMN workflow_encrypted MEDIUMTEXT NULL AFTER target_config_json,
 ADD COLUMN workflow_sha256 CHAR(64) NULL AFTER workflow_encrypted,
 ADD COLUMN materialized_at TIMESTAMP NULL AFTER workflow_sha256;
