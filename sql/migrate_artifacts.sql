-- Image generation: job artifacts + runner backend/SD capability fields

CREATE TABLE IF NOT EXISTS job_artifacts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    job_id INT UNSIGNED NOT NULL,
    kind VARCHAR(32) NOT NULL DEFAULT 'output',
    filename VARCHAR(255) NOT NULL,
    content_type VARCHAR(128) NOT NULL,
    storage_path VARCHAR(512) NOT NULL,
    size_bytes INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_job (job_id),
    FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE runners
    ADD COLUMN backend_state VARCHAR(32) NULL AFTER agent_tool_ids,
    ADD COLUMN sd_available TINYINT(1) NULL AFTER backend_state,
    ADD COLUMN sd_models JSON NULL AFTER sd_available,
    ADD COLUMN ollama_models_stale TINYINT(1) NULL AFTER sd_models;
