CREATE TABLE IF NOT EXISTS webhard_shares (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    token VARCHAR(128) NOT NULL UNIQUE,
    base_path TEXT NOT NULL,
    can_upload TINYINT(1) NOT NULL DEFAULT 0,
    can_download TINYINT(1) NOT NULL DEFAULT 1,
    password_hash VARCHAR(255) DEFAULT NULL,
    expires_at DATETIME DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    revoked_at DATETIME DEFAULT NULL,
    INDEX idx_created_at (created_at),
    INDEX idx_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
