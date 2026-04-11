-- Migration script to add WhatsApp messages history table
-- Run this script to update existing database

USE chatbot_db;

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

