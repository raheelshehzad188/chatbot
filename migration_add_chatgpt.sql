-- Migration script to add ChatGPT support
-- Run this script to update existing database

USE chatbot_db;

-- Add new columns to sub_admin_settings table
ALTER TABLE sub_admin_settings 
ADD COLUMN IF NOT EXISTS api_provider ENUM('gemini', 'chatgpt') DEFAULT 'gemini' AFTER webhook_token,
ADD COLUMN IF NOT EXISTS chatgpt_api_key VARCHAR(255) DEFAULT '' AFTER gemini_api_key,
ADD COLUMN IF NOT EXISTS system_instruction TEXT DEFAULT '' AFTER starting_message;

