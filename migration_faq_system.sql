-- Per-store FAQ + pending customer questions.
-- Tables are also created automatically on first use (see faq_ensure_schema in functions.php).

CREATE TABLE IF NOT EXISTS store_faq (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sub_admin_id INT NOT NULL,
    question TEXT NOT NULL,
    answer TEXT NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (sub_admin_id) REFERENCES admins(id) ON DELETE CASCADE,
    INDEX idx_store_faq_sub (sub_admin_id),
    INDEX idx_store_faq_sort (sub_admin_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pending_questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sub_admin_id INT NOT NULL,
    customer_phone VARCHAR(32) NOT NULL,
    message_text TEXT NOT NULL,
    message_hash CHAR(64) NOT NULL,
    status ENUM('open','answered','dismissed') NOT NULL DEFAULT 'open',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    answered_at TIMESTAMP NULL DEFAULT NULL,
    FOREIGN KEY (sub_admin_id) REFERENCES admins(id) ON DELETE CASCADE,
    INDEX idx_pq_sub_status (sub_admin_id, status),
    INDEX idx_pq_open_hash (sub_admin_id, message_hash, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Optional: add columns to sub_admin_settings (or rely on admin/settings.php / faq_ensure_schema auto-migration):
-- ALTER TABLE sub_admin_settings ADD COLUMN test_chat_token VARCHAR(64) DEFAULT NULL UNIQUE AFTER webhook_token;
-- ALTER TABLE sub_admin_settings ADD COLUMN faq_strict_unknown TINYINT(1) NOT NULL DEFAULT 0 AFTER message_interval;
-- ALTER TABLE sub_admin_settings ADD COLUMN unknown_question_reply TEXT NULL AFTER faq_strict_unknown;
