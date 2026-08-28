CREATE TABLE IF NOT EXISTS journal_entries (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    title VARCHAR(200) NULL,
    body TEXT NOT NULL,
    entry_date DATE NOT NULL,
    project VARCHAR(64) NULL,
    created_by ENUM('user', 'agent') NOT NULL,
    created_job_id INT UNSIGNED NULL,
    updated_job_id INT UNSIGNED NULL,
    deleted_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_entry_date (user_id, entry_date, id),
    INDEX idx_user_project_entry_date (user_id, project, entry_date),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (created_job_id) REFERENCES jobs(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_job_id) REFERENCES jobs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
