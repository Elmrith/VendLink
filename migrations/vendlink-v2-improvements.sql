-- Idempotent VendLink v2 improvements. Run against the selected database before uploading
-- the matching application files. Existing user, product, and order rows are retained.

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS municipality VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS province VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS barangay VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS sitioPurok VARCHAR(150) NULL,
    ADD COLUMN IF NOT EXISTS additionalAddress TEXT NULL;

ALTER TABLE products
    ADD COLUMN IF NOT EXISTS unit VARCHAR(30) NOT NULL DEFAULT 'unit';

ALTER TABLE order_items
    ADD COLUMN IF NOT EXISTS unit VARCHAR(30) NOT NULL DEFAULT 'unit';

ALTER TABLE orders
    ADD COLUMN IF NOT EXISTS arrivalConfirmedAt DATETIME NULL,
    MODIFY COLUMN status ENUM('pending', 'confirmed', 'processing', 'ready_for_dispatch', 'shipped', 'delivered', 'arrival_confirmed', 'cancelled') NOT NULL DEFAULT 'pending';

CREATE TABLE IF NOT EXISTS order_status_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    orderId INT NOT NULL,
    status ENUM('pending', 'confirmed', 'processing', 'ready_for_dispatch', 'shipped', 'delivered', 'arrival_confirmed', 'cancelled') NOT NULL,
    changedBy VARCHAR(36) NULL,
    changedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_order_status_history_order FOREIGN KEY (orderId) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_order_status_history_user FOREIGN KEY (changedBy) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_order_status_history_order_changed (orderId, changedAt)
);

CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    userId VARCHAR(36) NOT NULL,
    tokenHash CHAR(64) NOT NULL UNIQUE,
    expiresAt DATETIME NOT NULL,
    usedAt DATETIME NULL,
    createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_password_reset_tokens_user FOREIGN KEY (userId) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_password_reset_tokens_user_expiry (userId, expiresAt, usedAt)
);

CREATE TABLE IF NOT EXISTS password_reset_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    emailHash CHAR(64) NOT NULL,
    requestIp VARCHAR(45) NOT NULL,
    createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_password_reset_attempts_email_time (emailHash, createdAt),
    INDEX idx_password_reset_attempts_ip_time (requestIp, createdAt)
);
