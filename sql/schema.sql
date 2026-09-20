-- OwnRun database schema (MariaDB / MySQL)

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(64) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    failed_login_count INT UNSIGNED NOT NULL DEFAULT 0,
    locked_until DATETIME NULL,
    token_version INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS login_attempts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(64) NOT NULL,
    ip VARCHAR(45) NOT NULL,
    succeeded TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_username_created (username, created_at),
    INDEX idx_ip_created (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS refresh_tokens (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    family_id CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    remember_me TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME NULL,
    revoked_at DATETIME NULL,
    INDEX idx_token_hash (token_hash),
    INDEX idx_user_id (user_id),
    INDEX idx_family_id (family_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS runners (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(64) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    last_seen_at DATETIME NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    available_models JSON NULL,
    agent_profiles JSON NULL,
    agent_models JSON NULL,
    agent_projects JSON NULL,
    timezone VARCHAR(64) NULL,
    ollama_web_search TINYINT(1) NULL,
    agent_tool_ids JSON NULL,
    backend_state VARCHAR(32) NULL,
    sd_available TINYINT(1) NULL,
    sd_models JSON NULL,
    ollama_models_stale TINYINT(1) NULL,
    UNIQUE KEY uk_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS jobs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    runner_id INT UNSIGNED NULL,
    type VARCHAR(64) NOT NULL,
    status ENUM('queued', 'running', 'succeeded', 'failed', 'cancelled') NOT NULL DEFAULT 'queued',
    request_payload JSON NOT NULL,
    result_payload JSON NULL,
    progress_payload JSON NULL,
    error_message TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    queued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    claimed_at DATETIME NULL,
    finished_at DATETIME NULL,
    lease_expires_at DATETIME NULL,
    INDEX idx_status (status),
    INDEX idx_user_id (user_id),
    INDEX idx_lease (lease_expires_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (runner_id) REFERENCES runners(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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

CREATE TABLE IF NOT EXISTS task_entries (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    name VARCHAR(200) NOT NULL,
    description TEXT NULL,
    due_date DATE NULL,
    project VARCHAR(64) NULL,
    priority ENUM('high', 'normal', 'low') NOT NULL DEFAULT 'normal',
    status ENUM('todo', 'in_progress', 'finished', 'deleted') NOT NULL DEFAULT 'todo',
    created_by ENUM('user', 'agent') NOT NULL,
    created_job_id INT UNSIGNED NULL,
    updated_job_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_status_due (user_id, status, due_date, id),
    INDEX idx_user_project_status_due (user_id, project, status, due_date),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (created_job_id) REFERENCES jobs(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_job_id) REFERENCES jobs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS system_prompts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    name VARCHAR(80) NOT NULL,
    body TEXT NOT NULL,
    applies_to ENUM('chat', 'agent', 'both') NOT NULL DEFAULT 'both',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_user_name (user_id, name),
    INDEX idx_user_id (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_prompt_defaults (
    user_id INT UNSIGNED PRIMARY KEY,
    chat_prompt_id INT UNSIGNED NULL,
    agent_prompt_id INT UNSIGNED NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (chat_prompt_id) REFERENCES system_prompts(id) ON DELETE SET NULL,
    FOREIGN KEY (agent_prompt_id) REFERENCES system_prompts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS project_prompt_defaults (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    project VARCHAR(64) NOT NULL,
    chat_prompt_id INT UNSIGNED NULL,
    agent_prompt_id INT UNSIGNED NULL,
    UNIQUE KEY uk_user_project (user_id, project),
    INDEX idx_user_id (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (chat_prompt_id) REFERENCES system_prompts(id) ON DELETE SET NULL,
    FOREIGN KEY (agent_prompt_id) REFERENCES system_prompts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS project_tool_policies (
    user_id INT UNSIGNED NOT NULL,
    project VARCHAR(64) NOT NULL,
    disabled_tools JSON NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, project),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS kb_bases (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    name VARCHAR(80) NOT NULL,
    slug VARCHAR(64) NOT NULL,
    project VARCHAR(64) NULL,
    description VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_user_slug (user_id, slug),
    INDEX idx_user_project (user_id, project),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS kb_documents (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    kb_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    filename VARCHAR(255) NOT NULL,
    content_type VARCHAR(128) NOT NULL,
    format ENUM('pdf', 'docx', 'epub', 'md', 'txt') NOT NULL,
    size_bytes INT UNSIGNED NOT NULL,
    storage_path VARCHAR(512) NOT NULL,
    status ENUM('pending', 'queued', 'running', 'ready', 'failed', 'deleting') NOT NULL DEFAULT 'pending',
    error_message TEXT NULL,
    chunk_count INT UNSIGNED NULL,
    page_count INT UNSIGNED NULL,
    chapter_count INT UNSIGNED NULL,
    content_hash CHAR(64) NOT NULL,
    ingest_job_id INT UNSIGNED NULL,
    embedding_model VARCHAR(128) NULL,
    deleted_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_kb_status (kb_id, status),
    INDEX idx_user_status (user_id, status),
    FOREIGN KEY (kb_id) REFERENCES kb_bases(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (ingest_job_id) REFERENCES jobs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
