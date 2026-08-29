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
