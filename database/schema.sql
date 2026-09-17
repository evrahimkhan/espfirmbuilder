CREATE TABLE schema_migrations (
 migration VARCHAR(190) PRIMARY KEY, checksum CHAR(64) NOT NULL,
 applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE users (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, email VARCHAR(255) NOT NULL UNIQUE, email_verified_at TIMESTAMP NULL, name VARCHAR(120) NOT NULL,
 password_hash VARCHAR(255) NULL, session_version INT UNSIGNED NOT NULL DEFAULT 1, github_id VARCHAR(64) NULL UNIQUE, google_id VARCHAR(128) NULL UNIQUE,
 github_token TEXT NULL, github_verified_at TIMESTAMP NULL, github_connection_ok TINYINT(1) NULL, ai_provider VARCHAR(32) NULL, ai_api_key TEXT NULL, ai_key_fingerprint CHAR(64) NULL UNIQUE, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE email_verification_tokens (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL,
 token_hash CHAR(64) NOT NULL UNIQUE, expires_at TIMESTAMP NOT NULL, used_at TIMESTAMP NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
 INDEX(user_id, created_at), INDEX(expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE password_reset_tokens (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL,
 token_hash CHAR(64) NOT NULL UNIQUE, expires_at TIMESTAMP NOT NULL, used_at TIMESTAMP NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
 INDEX(user_id, created_at), INDEX(expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE repositories (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL, repo_url VARCHAR(500) NOT NULL,
 full_name VARCHAR(255) NOT NULL, default_branch VARCHAR(100) DEFAULT 'main', framework VARCHAR(40) NULL,
 workflow_config MEDIUMTEXT NULL, status VARCHAR(30) DEFAULT 'connected', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE, INDEX(user_id), UNIQUE KEY uq_repositories_user_full_name(user_id, full_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE builds (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, repo_id BIGINT UNSIGNED NOT NULL, build_uuid CHAR(36) NULL UNIQUE,
 target_id VARCHAR(190) NULL, target_name VARCHAR(120) NULL, source_commit_sha CHAR(40) NULL,
 analyzer_version VARCHAR(40) NULL, ai_model VARCHAR(100) NULL, target_config_json JSON NULL, workflow_sha256 CHAR(64) NULL,
 github_run_id BIGINT UNSIGNED NULL, status VARCHAR(30) DEFAULT 'queued', conclusion VARCHAR(30) NULL, logs MEDIUMTEXT NULL, artifact_url VARCHAR(1000) NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, completed_at TIMESTAMP NULL,
 FOREIGN KEY (repo_id) REFERENCES repositories(id) ON DELETE CASCADE, INDEX(repo_id, created_at), INDEX(repo_id, source_commit_sha, target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE audit_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NULL, event_type VARCHAR(80) NOT NULL,
 ip_hash CHAR(64) NULL, metadata_json JSON NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL, INDEX(user_id, created_at), INDEX(event_type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE flash_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL, chip VARCHAR(32) NOT NULL,
 firmware_size INT UNSIGNED NOT NULL, manifest_verified TINYINT(1) NOT NULL DEFAULT 0,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
 INDEX(user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE rate_limits (
 rate_key CHAR(64) PRIMARY KEY, request_count INT UNSIGNED NOT NULL, reset_at TIMESTAMP NOT NULL,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, INDEX(reset_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE flash_configs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL, name VARCHAR(100) NOT NULL,
 chip VARCHAR(40) DEFAULT 'esp32', baud INT DEFAULT 460800, flash_mode VARCHAR(20) DEFAULT 'dio', offsets_json JSON NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE operational_metrics (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, metric_name VARCHAR(80) NOT NULL,
 duration_ms INT UNSIGNED NULL, outcome VARCHAR(32) NOT NULL, metadata_json JSON NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_operational_metrics_name_created(metric_name, created_at), INDEX idx_operational_metrics_created(created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE analysis_jobs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 repo_id BIGINT UNSIGNED NOT NULL,
 source_commit_sha CHAR(40) NOT NULL,
 analyzer_version VARCHAR(40) NOT NULL,
 status ENUM('queued','processing','completed','failed') NOT NULL DEFAULT 'queued',
 attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
 result_encrypted MEDIUMTEXT NULL,
 error_message VARCHAR(500) NULL,
 available_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 started_at TIMESTAMP NULL,
 completed_at TIMESTAMP NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 FOREIGN KEY (repo_id) REFERENCES repositories(id) ON DELETE CASCADE,
 UNIQUE KEY uq_analysis_job_revision(repo_id,source_commit_sha,analyzer_version),
 INDEX idx_analysis_jobs_claim(status,available_at,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE build_plans (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 plan_uuid CHAR(36) NOT NULL UNIQUE,
 repo_id BIGINT UNSIGNED NOT NULL,
 source_commit_sha CHAR(40) NOT NULL,
 analyzer_version VARCHAR(40) NOT NULL,
 target_id VARCHAR(190) NOT NULL,
 target_name VARCHAR(120) NOT NULL,
 target_config_json JSON NOT NULL,
 plan_sha256 CHAR(64) NOT NULL,
 status ENUM('ready','approved','superseded','dispatched','failed') NOT NULL DEFAULT 'ready',
 approved_by BIGINT UNSIGNED NULL,
 approved_at TIMESTAMP NULL,
 dispatched_at TIMESTAMP NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 FOREIGN KEY (repo_id) REFERENCES repositories(id) ON DELETE CASCADE,
 FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
 UNIQUE KEY uq_build_plan_revision_target(repo_id,source_commit_sha,analyzer_version,target_id),
 INDEX idx_build_plans_repo_status(repo_id,status,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE github_webhook_deliveries (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 delivery_id VARCHAR(100) NOT NULL UNIQUE,
 event_name VARCHAR(80) NOT NULL,
 status VARCHAR(30) NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_webhook_deliveries_created(created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
