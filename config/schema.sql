

-- Users table (Engineers & Supervisors)
CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('engineer','supervisor') NOT NULL DEFAULT 'engineer',
    phone VARCHAR(30) NULL,
    position VARCHAR(100) NULL,
    signature_image VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_role (role),
    INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Daily Logs table
CREATE TABLE IF NOT EXISTS daily_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    log_date DATE NOT NULL,
    engineer_id INT UNSIGNED NOT NULL,
    supervisor_id INT UNSIGNED NULL,
    
    -- Consumption
    total_electricity DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'kWh',
    total_water DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'm3',
    total_gas DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'kg',
    
    -- Photo
    photo_path VARCHAR(255) NULL,
    
    -- Content
    work_activities TEXT NOT NULL,
    obstacles TEXT NULL,
    solutions TEXT NULL,
    
    -- Approval
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    revision_notes TEXT NULL,
    supervisor_signature VARCHAR(255) NULL,
    approved_at DATETIME NULL,
    
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY uniq_engineer_date (engineer_id, log_date),
    INDEX idx_date (log_date),
    INDEX idx_status (status),
    INDEX idx_engineer (engineer_id),
    INDEX idx_supervisor (supervisor_id),
    CONSTRAINT fk_daily_log_engineer FOREIGN KEY (engineer_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_daily_log_supervisor FOREIGN KEY (supervisor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default users (password: 123456) - IGNORE jika sudah ada
INSERT IGNORE INTO users (name, email, password, role, phone, position) VALUES
('Engineer Staff 1', 'engineer@stregisbali.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'engineer', '+62 812-3456-7890', 'Engineering Staff'),
('Supervisor Engineering', 'supervisor@stregisbali.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'supervisor', '+62 812-9876-5432', 'Engineering Manager');
