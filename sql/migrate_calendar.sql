-- Calendar feature (Phase 5). Safe to run on an existing OwnRun database.

CREATE TABLE IF NOT EXISTS calendar_entries (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NULL,
    all_day TINYINT(1) NOT NULL DEFAULT 0,
    project VARCHAR(64) NULL,
    created_by ENUM('user', 'agent') NOT NULL,
    created_job_id INT UNSIGNED NULL,
    updated_job_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_starts (user_id, starts_at),
    INDEX idx_user_project_starts (user_id, project, starts_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (created_job_id) REFERENCES jobs(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_job_id) REFERENCES jobs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
