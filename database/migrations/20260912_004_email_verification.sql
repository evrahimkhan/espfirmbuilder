ALTER TABLE users ADD COLUMN email_verified_at TIMESTAMP NULL AFTER email;
UPDATE users SET email_verified_at=NOW() WHERE email_verified_at IS NULL;

CREATE TABLE email_verification_tokens (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 token_hash CHAR(64) NOT NULL UNIQUE,
 expires_at TIMESTAMP NOT NULL,
 used_at TIMESTAMP NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
 INDEX ix_email_verification_user(user_id, created_at),
 INDEX ix_email_verification_expiry(expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
