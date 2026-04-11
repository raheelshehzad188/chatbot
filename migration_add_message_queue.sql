-- Migration script to add message queue table and message_interval field
-- Run this script to update existing database

USE chatbot_db;

-- Add message_interval column to sub_admin_settings if not exists
ALTER TABLE sub_admin_settings 
ADD COLUMN IF NOT EXISTS message_interval INT DEFAULT 60 AFTER system_instruction;

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

