CREATE TABLE github_webhook_deliveries (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 delivery_id VARCHAR(100) NOT NULL UNIQUE,
 event_name VARCHAR(80) NOT NULL,
 status VARCHAR(30) NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_webhook_deliveries_created(created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
