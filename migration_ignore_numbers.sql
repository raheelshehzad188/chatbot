-- Add per-sub-admin ignore list for incoming WhatsApp numbers.
-- Match logic uses last 6 digits of incoming phone.

ALTER TABLE sub_admin_settings
ADD COLUMN IF NOT EXISTS ignore_numbers TEXT DEFAULT '' AFTER system_instruction;

