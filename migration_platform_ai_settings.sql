-- Platform-wide AI: one Gemini + one OpenAI key for all store owners (sub-admins).
-- Run: mysql -u root chatbot < migration_platform_ai_settings.sql

CREATE TABLE IF NOT EXISTS platform_ai_settings (
    id INT PRIMARY KEY DEFAULT 1,
    api_provider ENUM('gemini', 'chatgpt') NOT NULL DEFAULT 'gemini',
    gemini_api_key VARCHAR(255) DEFAULT '',
    chatgpt_api_key VARCHAR(255) DEFAULT '',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO platform_ai_settings (id, api_provider, gemini_api_key, chatgpt_api_key)
VALUES (1, 'gemini', '', '')
ON DUPLICATE KEY UPDATE id = id;
