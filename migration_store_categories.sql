-- Store categories + sub-admin assignment (also auto-created by categories_ensure_schema in functions.php)

CREATE TABLE IF NOT EXISTS store_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    developer_prompt TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_sc_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ALTER TABLE admins ADD COLUMN category_id INT NULL DEFAULT NULL;
-- ALTER TABLE admins ADD CONSTRAINT fk_admins_store_category FOREIGN KEY (category_id) REFERENCES store_categories(id) ON DELETE SET NULL;
