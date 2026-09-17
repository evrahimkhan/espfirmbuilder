ALTER TABLE users ADD COLUMN github_verified_at TIMESTAMP NULL AFTER github_token;
ALTER TABLE users ADD COLUMN github_connection_ok TINYINT(1) NULL AFTER github_verified_at;
CREATE TABLE operational_metrics (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 metric_name VARCHAR(80) NOT NULL,
 duration_ms INT UNSIGNED NULL,
 outcome VARCHAR(32) NOT NULL,
 metadata_json JSON NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_operational_metrics_name_created(metric_name, created_at),
 INDEX idx_operational_metrics_created(created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
