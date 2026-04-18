-- Create database
CREATE DATABASE IF NOT EXISTS chatbot_db;
USE chatbot_db;

-- Create admins table
CREATE TABLE IF NOT EXISTS store_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    developer_prompt TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_sc_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NULL DEFAULT NULL,
    username VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    email VARCHAR(255) DEFAULT '',
    role ENUM('super_admin', 'sub_admin') NOT NULL DEFAULT 'sub_admin',
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_username (username),
    INDEX idx_role (role),
    INDEX idx_admins_category (category_id),
    FOREIGN KEY (category_id) REFERENCES store_categories(id) ON DELETE SET NULL
);

-- Create sub_admin_settings table
CREATE TABLE IF NOT EXISTS sub_admin_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id INT NOT NULL,
    webhook_token VARCHAR(100) NOT NULL UNIQUE,
    test_chat_token VARCHAR(64) DEFAULT NULL,
    api_provider ENUM('gemini', 'chatgpt') DEFAULT 'gemini',
    gemini_api_key VARCHAR(255) DEFAULT '',
    chatgpt_api_key VARCHAR(255) DEFAULT '',
    whatsapp_api_token VARCHAR(255) DEFAULT '',
    starting_message TEXT DEFAULT '',
    system_instruction TEXT DEFAULT '',
    ignore_numbers TEXT DEFAULT '',
    message_interval INT DEFAULT 60,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE,
    INDEX idx_admin_id (admin_id),
    INDEX idx_webhook_token (webhook_token)
);

-- Create leads table (updated with sub_admin_id)
CREATE TABLE IF NOT EXISTS leads (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sub_admin_id INT NOT NULL,
    phone VARCHAR(20) NOT NULL,
    name VARCHAR(255) DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (sub_admin_id) REFERENCES admins(id) ON DELETE CASCADE,
    UNIQUE KEY unique_subadmin_phone (sub_admin_id, phone),
    INDEX idx_phone (phone),
    INDEX idx_sub_admin_id (sub_admin_id)
);

-- Create message_logs table (updated with sub_admin_id)
CREATE TABLE IF NOT EXISTS message_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sub_admin_id INT NOT NULL,
    phone VARCHAR(20) NOT NULL,
    name VARCHAR(255) DEFAULT '',
    message TEXT NOT NULL,
    type ENUM('received', 'sent') NOT NULL,
    received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sub_admin_id) REFERENCES admins(id) ON DELETE CASCADE,
    INDEX idx_phone (phone),
    INDEX idx_sub_admin_id (sub_admin_id),
    INDEX idx_received_at (received_at),
    INDEX idx_type (type)
);

-- Create chat_history table (updated with sub_admin_id)
CREATE TABLE IF NOT EXISTS chat_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sub_admin_id INT NOT NULL,
    phone VARCHAR(20) NOT NULL,
    name VARCHAR(255) DEFAULT '',
    message TEXT NOT NULL,
    direction ENUM('incoming', 'outgoing') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sub_admin_id) REFERENCES admins(id) ON DELETE CASCADE,
    INDEX idx_phone (phone),
    INDEX idx_sub_admin_id (sub_admin_id),
    INDEX idx_created_at (created_at),
    INDEX idx_direction (direction)
);

-- Create chatgpt_history table
CREATE TABLE IF NOT EXISTS chatgpt_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sub_admin_id INT NOT NULL,
    phone VARCHAR(20) DEFAULT '',
    name VARCHAR(255) DEFAULT '',
    type ENUM('welcome', 'reply') NOT NULL,
    incoming_message TEXT DEFAULT '',
    generated_message TEXT NOT NULL,
    request_payload TEXT NOT NULL,
    response_data TEXT NOT NULL,
    http_code INT DEFAULT NULL,
    api_time DECIMAL(10, 3) DEFAULT NULL,
    error TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sub_admin_id) REFERENCES admins(id) ON DELETE CASCADE,
    INDEX idx_sub_admin_id (sub_admin_id),
    INDEX idx_phone (phone),
    INDEX idx_type (type),
    INDEX idx_created_at (created_at)
);

-- Create whatsapp_messages table
CREATE TABLE IF NOT EXISTS whatsapp_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sub_admin_id INT NOT NULL,
    phone VARCHAR(20) NOT NULL,
    name VARCHAR(255) DEFAULT '',
    message TEXT NOT NULL,
    request_payload TEXT DEFAULT NULL,
    response_data TEXT DEFAULT NULL,
    http_code INT DEFAULT NULL,
    success TINYINT(1) DEFAULT 0,
    error TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sub_admin_id) REFERENCES admins(id) ON DELETE CASCADE,
    INDEX idx_sub_admin_id (sub_admin_id),
    INDEX idx_phone (phone),
    INDEX idx_success (success),
    INDEX idx_created_at (created_at)
);

-- Create gemini_history table
CREATE TABLE IF NOT EXISTS gemini_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sub_admin_id INT NOT NULL,
    phone VARCHAR(20) DEFAULT '',
    name VARCHAR(255) DEFAULT '',
    type ENUM('welcome', 'reply') NOT NULL,
    incoming_message TEXT DEFAULT '',
    generated_message TEXT NOT NULL,
    request_payload TEXT NOT NULL,
    response_data TEXT NOT NULL,
    http_code INT DEFAULT NULL,
    api_time DECIMAL(10, 3) DEFAULT NULL,
    error TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sub_admin_id) REFERENCES admins(id) ON DELETE CASCADE,
    INDEX idx_sub_admin_id (sub_admin_id),
    INDEX idx_phone (phone),
    INDEX idx_type (type),
    INDEX idx_created_at (created_at)
);

-- Create message_queue table
CREATE TABLE IF NOT EXISTS message_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sub_admin_id INT NOT NULL,
    phone VARCHAR(20) NOT NULL,
    name VARCHAR(255) DEFAULT '',
    message TEXT NOT NULL,
    status ENUM('pending', 'processing', 'sent', 'failed') DEFAULT 'pending',
    scheduled_at TIMESTAMP NULL DEFAULT NULL,
    sent_at TIMESTAMP NULL DEFAULT NULL,
    attempts INT DEFAULT 0,
    error TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sub_admin_id) REFERENCES admins(id) ON DELETE CASCADE,
    INDEX idx_sub_admin_id (sub_admin_id),
    INDEX idx_status (status),
    INDEX idx_scheduled_at (scheduled_at),
    INDEX idx_created_at (created_at)
);

-- Platform-wide AI (super admin sets keys; all sub-stores share)
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

-- Per-store FAQ (optional columns faq_strict_unknown, unknown_question_reply on sub_admin_settings are added by app on first use)
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

-- Insert default super admin (password: admin123 - change after first login)
INSERT INTO admins (username, password, role, status) 
VALUES ('superadmin', '$2y$10$H.c7UjtjmOB8wBYZgBksYetJtlhPoef6lQZebfAL.57hnu9xVwARO', 'super_admin', 'active')
ON DUPLICATE KEY UPDATE username=username;

