-- Migration script to add Gemini history table
-- Run this script to update existing database

USE chatbot_db;

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

