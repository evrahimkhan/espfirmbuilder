CREATE TABLE audit_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NULL, event_type VARCHAR(80) NOT NULL,
 ip_hash CHAR(64) NULL, metadata_json JSON NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
 INDEX ix_audit_user_time(user_id, created_at), INDEX ix_audit_type_time(event_type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
