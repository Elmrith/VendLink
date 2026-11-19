CREATE DATABASE IF NOT EXISTS vendlink CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE vendlink;

-- Vendor and supplier usernames are stored on users and joined into product/order API results.
-- Marketplace search, order sorting, and delivery-list filtering are presentation behavior;
-- they do not require additional persisted columns.

-- 1. Users table
CREATE TABLE IF NOT EXISTS users (
    id VARCHAR(36) PRIMARY KEY,
    email VARCHAR(191) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    name VARCHAR(100) NOT NULL,
    businessName VARCHAR(100) NULL,
    applicationMessage TEXT NULL,
    businessPermitPath VARCHAR(255) NULL,
    businessPermitReviewStatus ENUM('pending', 'verified', 'rejected') NOT NULL DEFAULT 'pending',
    accountSetupTokenHash CHAR(64) NULL,
    accountSetupExpiresAt DATETIME NULL,
    passwordSetupCompletedAt DATETIME NULL,
    address TEXT NULL,
    municipality VARCHAR(100) NULL,
    province VARCHAR(100) NULL,
    barangay VARCHAR(100) NULL,
    sitioPurok VARCHAR(150) NULL,
    additionalAddress TEXT NULL,
    contactNumber VARCHAR(30) NULL,
    gcashNumber VARCHAR(30) NULL,
    paymayaNumber VARCHAR(30) NULL,
    username VARCHAR(50) UNIQUE NULL,
    role ENUM('admin', 'vendor', 'supplier') NOT NULL DEFAULT 'vendor',
    profileImage VARCHAR(255) NULL,
    isActive TINYINT(1) NOT NULL DEFAULT 1,
    isApproved TINYINT(1) NOT NULL DEFAULT 0,
    approvalStatus ENUM('pending', 'approved', 'disapproved') NOT NULL DEFAULT 'pending',
    failedAttempts INT NOT NULL DEFAULT 0,
    lockedUntil DATETIME NULL,
    twoFactorEnabled TINYINT(1) NOT NULL DEFAULT 0,
    twoFactorTempCode VARCHAR(10) NULL,
    twoFactorExpires DATETIME NULL,
    backupCodes TEXT NULL,
    notificationsEnabled TINYINT(1) NOT NULL DEFAULT 1,
    emailVerified TINYINT(1) NOT NULL DEFAULT 0,
    createdAt DATETIME DEFAULT CURRENT_TIMESTAMP,
    updatedAt DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- 2. Delivery addresses table
CREATE TABLE IF NOT EXISTS delivery_addresses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vendorId VARCHAR(36) NOT NULL,
    recipientName VARCHAR(100) NOT NULL,
    phone VARCHAR(30) NOT NULL,
    addressLine1 VARCHAR(255) NOT NULL,
    addressLine2 VARCHAR(255) NULL,
    city VARCHAR(100) NOT NULL,
    province VARCHAR(100) NULL,
    postalCode VARCHAR(20) NOT NULL,
    country VARCHAR(100) NOT NULL DEFAULT 'Philippines',
    deliveryNotes TEXT NULL,
    latitude DECIMAL(10, 7) NULL,
    longitude DECIMAL(10, 7) NULL,
    isDefault TINYINT(1) NOT NULL DEFAULT 0,
    createdAt DATETIME DEFAULT CURRENT_TIMESTAMP,
    updatedAt DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_delivery_addresses_vendor FOREIGN KEY (vendorId) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_delivery_addresses_vendor_default (vendorId, isDefault, updatedAt)
);

-- 3. Products table
CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    price DECIMAL(10, 2) NOT NULL,
    description TEXT NULL,
    stockQuantity INT NOT NULL DEFAULT 0,
    demandStatus ENUM('low', 'medium', 'high') DEFAULT 'medium',
    category VARCHAR(50) NULL,
    unit VARCHAR(30) NOT NULL DEFAULT 'unit',
    supplierId VARCHAR(36) NOT NULL,
    supplierName VARCHAR(100) NULL,
    imageUrl VARCHAR(255) NULL,
    hasVariants TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('pending', 'approved', 'denied') DEFAULT 'approved',
    rejectionReason TEXT NULL,
    reviewedAt DATETIME NULL,
    createdAt DATETIME DEFAULT CURRENT_TIMESTAMP,
    updatedAt DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_products_supplier FOREIGN KEY (supplierId) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_products_marketplace (status, supplierId),
    INDEX idx_products_supplier_created (supplierId, createdAt)
);

-- 4. Product variants
CREATE TABLE IF NOT EXISTS product_variants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    productId INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    sku VARCHAR(100) NOT NULL,
    price DECIMAL(10, 2) NOT NULL,
    stockQuantity INT NOT NULL DEFAULT 0,
    imageUrl VARCHAR(255) NULL,
    createdAt DATETIME DEFAULT CURRENT_TIMESTAMP,
    updatedAt DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_product_variants_product FOREIGN KEY (productId) REFERENCES products(id) ON DELETE CASCADE,
    UNIQUE KEY uq_product_variant_name (productId, name),
    UNIQUE KEY uq_product_variant_sku (productId, sku),
    INDEX idx_product_variants_product (productId)
);

-- 5. Orders table
CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vendorId VARCHAR(36) NOT NULL,
    vendorName VARCHAR(100) NULL,
    vendorBusinessName VARCHAR(100) NULL,
    vendorAddress TEXT NULL,
    vendorContactNumber VARCHAR(30) NULL,
    supplierId VARCHAR(36) NOT NULL,
    supplierName VARCHAR(100) NULL,
    supplierBusinessName VARCHAR(100) NULL,
    supplierAddress TEXT NULL,
    supplierContactNumber VARCHAR(30) NULL,
    status ENUM('pending', 'confirmed', 'processing', 'ready_for_dispatch', 'shipped', 'delivered', 'arrival_confirmed', 'cancelled') NOT NULL DEFAULT 'pending',
    arrivalConfirmedAt DATETIME NULL,
    totalAmount DECIMAL(10, 2) NOT NULL,
    paymentMethod VARCHAR(50) NOT NULL DEFAULT 'Cash on Delivery',
    deliveryAddress TEXT NULL,
    deliveryNotes TEXT NULL,
    supplierOrderNumber INT NOT NULL DEFAULT 1,
    createdAt DATETIME DEFAULT CURRENT_TIMESTAMP,
    updatedAt DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_orders_vendor FOREIGN KEY (vendorId) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_orders_supplier FOREIGN KEY (supplierId) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_orders_supplier_number (supplierId, supplierOrderNumber),
    INDEX idx_orders_vendor_created (vendorId, createdAt),
    INDEX idx_orders_supplier_created (supplierId, createdAt),
    INDEX idx_orders_status (status)
);

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

-- 6. Order items table
CREATE TABLE IF NOT EXISTS order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    orderId INT NOT NULL,
    productId INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    priceAtOrder DECIMAL(10, 2) NOT NULL,
    quantity INT NOT NULL,
    unit VARCHAR(30) NOT NULL DEFAULT 'unit',
    imageUrl VARCHAR(255) NULL,
    variantId INT NULL,
    variantName VARCHAR(100) NULL,
    CONSTRAINT fk_items_order FOREIGN KEY (orderId) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_items_product FOREIGN KEY (productId) REFERENCES products(id) ON DELETE RESTRICT,
    CONSTRAINT fk_items_variant FOREIGN KEY (variantId) REFERENCES product_variants(id) ON DELETE RESTRICT
);

-- 7. Notifications table
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    userId VARCHAR(36) NOT NULL,
    title VARCHAR(150) NOT NULL,
    message TEXT NOT NULL,
    type VARCHAR(50) NOT NULL,
    isRead TINYINT(1) NOT NULL DEFAULT 0,
    createdAt DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notifications_user FOREIGN KEY (userId) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_notifications_user_read (userId, isRead, createdAt)
);

-- 8. Trusted devices table for remembered sign-in devices
CREATE TABLE IF NOT EXISTS user_devices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    userId VARCHAR(36) NOT NULL,
    deviceTokenHash CHAR(64) NOT NULL,
    deviceName VARCHAR(100) NULL,
    userAgent VARCHAR(255) NULL,
    ipAddress VARCHAR(45) NULL,
    lastSeenAt DATETIME DEFAULT CURRENT_TIMESTAMP,
    createdAt DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_devices_token (userId, deviceTokenHash),
    INDEX idx_user_devices_user_last_seen (userId, lastSeenAt),
    CONSTRAINT fk_user_devices_user FOREIGN KEY (userId) REFERENCES users(id) ON DELETE CASCADE
);

-- 9. Verification codes for new-device login confirmations
CREATE TABLE IF NOT EXISTS user_login_verifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    userId VARCHAR(36) NOT NULL,
    verificationCode CHAR(6) NOT NULL,
    purpose ENUM('login', 'email') NOT NULL DEFAULT 'login',
    ipAddress VARCHAR(45) NULL,
    userAgent VARCHAR(255) NULL,
    expiresAt DATETIME NOT NULL,
    isVerified TINYINT(1) NOT NULL DEFAULT 0,
    createdAt DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_login_verifications_user_expiry (userId, expiresAt, isVerified),
    CONSTRAINT fk_user_login_verifications_user FOREIGN KEY (userId) REFERENCES users(id) ON DELETE CASCADE
);

-- 10. Security logs table
CREATE TABLE IF NOT EXISTS security_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    userId VARCHAR(36) NULL,
    ip VARCHAR(45) NULL,
    action VARCHAR(100) NOT NULL,
    meta TEXT NULL,
    createdAt DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_security_logs_user FOREIGN KEY (userId) REFERENCES users(id) ON DELETE SET NULL
);

-- 11. Settings table
CREATE TABLE IF NOT EXISTS settings (
    `key` VARCHAR(100) PRIMARY KEY,
    `value` TEXT NULL,
    updatedAt DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Migration compatibility for older installs
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS businessName VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS applicationMessage TEXT NULL,
    ADD COLUMN IF NOT EXISTS businessPermitPath VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS businessPermitReviewStatus ENUM('pending', 'verified', 'rejected') NOT NULL DEFAULT 'pending',
    ADD COLUMN IF NOT EXISTS accountSetupTokenHash CHAR(64) NULL,
    ADD COLUMN IF NOT EXISTS accountSetupExpiresAt DATETIME NULL,
    ADD COLUMN IF NOT EXISTS passwordSetupCompletedAt DATETIME NULL,
    ADD COLUMN IF NOT EXISTS address TEXT NULL,
    ADD COLUMN IF NOT EXISTS municipality VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS province VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS barangay VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS sitioPurok VARCHAR(150) NULL,
    ADD COLUMN IF NOT EXISTS additionalAddress TEXT NULL,
    ADD COLUMN IF NOT EXISTS contactNumber VARCHAR(30) NULL,
    ADD COLUMN IF NOT EXISTS gcashNumber VARCHAR(30) NULL,
    ADD COLUMN IF NOT EXISTS paymayaNumber VARCHAR(30) NULL,
    ADD COLUMN IF NOT EXISTS username VARCHAR(50) UNIQUE NULL,
    ADD COLUMN IF NOT EXISTS profileImage VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS isActive TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS isApproved TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS approvalStatus ENUM('pending', 'approved', 'disapproved') NOT NULL DEFAULT 'pending',
    ADD COLUMN IF NOT EXISTS failedAttempts INT NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS lockedUntil DATETIME NULL,
    ADD COLUMN IF NOT EXISTS twoFactorEnabled TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS twoFactorTempCode VARCHAR(10) NULL,
    ADD COLUMN IF NOT EXISTS twoFactorExpires DATETIME NULL,
    ADD COLUMN IF NOT EXISTS backupCodes TEXT NULL,
    ADD COLUMN IF NOT EXISTS notificationsEnabled TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS emailVerified TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS updatedAt DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

UPDATE users
SET passwordSetupCompletedAt = COALESCE(updatedAt, createdAt, NOW())
WHERE role = 'supplier'
    AND approvalStatus = 'approved'
    AND accountSetupTokenHash IS NULL
    AND passwordSetupCompletedAt IS NULL;

ALTER TABLE notifications
    ADD COLUMN IF NOT EXISTS userId VARCHAR(36) NOT NULL,
    ADD COLUMN IF NOT EXISTS title VARCHAR(150) NOT NULL,
    ADD COLUMN IF NOT EXISTS message TEXT NOT NULL,
    ADD COLUMN IF NOT EXISTS type VARCHAR(50) NOT NULL,
    ADD COLUMN IF NOT EXISTS isRead TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS createdAt DATETIME DEFAULT CURRENT_TIMESTAMP;

ALTER TABLE products
    ADD COLUMN IF NOT EXISTS rejectionReason TEXT NULL,
    ADD COLUMN IF NOT EXISTS reviewedAt DATETIME NULL,
    ADD COLUMN IF NOT EXISTS updatedAt DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    ADD COLUMN IF NOT EXISTS hasVariants TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE products
    ADD COLUMN IF NOT EXISTS unit VARCHAR(30) NOT NULL DEFAULT 'unit';
ALTER TABLE order_items
    ADD COLUMN IF NOT EXISTS unit VARCHAR(30) NOT NULL DEFAULT 'unit';

ALTER TABLE orders
    ADD COLUMN IF NOT EXISTS updatedAt DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    ADD COLUMN IF NOT EXISTS deliveryAddress TEXT NULL,
    ADD COLUMN IF NOT EXISTS deliveryNotes TEXT NULL,
    ADD COLUMN IF NOT EXISTS vendorBusinessName VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS vendorAddress TEXT NULL,
    ADD COLUMN IF NOT EXISTS vendorContactNumber VARCHAR(30) NULL,
    ADD COLUMN IF NOT EXISTS supplierBusinessName VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS supplierAddress TEXT NULL,
    ADD COLUMN IF NOT EXISTS supplierContactNumber VARCHAR(30) NULL;
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

ALTER TABLE user_login_verifications
    ADD COLUMN IF NOT EXISTS purpose ENUM('login', 'email') NOT NULL DEFAULT 'login';

CREATE TABLE IF NOT EXISTS user_devices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    userId VARCHAR(36) NOT NULL,
    deviceTokenHash CHAR(64) NOT NULL,
    deviceName VARCHAR(100) NULL,
    userAgent VARCHAR(255) NULL,
    ipAddress VARCHAR(45) NULL,
    lastSeenAt DATETIME DEFAULT CURRENT_TIMESTAMP,
    createdAt DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_devices_token (userId, deviceTokenHash),
    INDEX idx_user_devices_user_last_seen (userId, lastSeenAt),
    CONSTRAINT fk_user_devices_user FOREIGN KEY (userId) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS user_login_verifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    userId VARCHAR(36) NOT NULL,
    verificationCode CHAR(6) NOT NULL,
    purpose ENUM('login', 'email') NOT NULL DEFAULT 'login',
    ipAddress VARCHAR(45) NULL,
    userAgent VARCHAR(255) NULL,
    expiresAt DATETIME NOT NULL,
    isVerified TINYINT(1) NOT NULL DEFAULT 0,
    createdAt DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_login_verifications_user_expiry (userId, expiresAt, isVerified),
    CONSTRAINT fk_user_login_verifications_user FOREIGN KEY (userId) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS cookie_preferences (
    id INT AUTO_INCREMENT PRIMARY KEY,
    userId VARCHAR(36) NULL,
    sessionId VARCHAR(128) NULL,
    essential TINYINT(1) NOT NULL DEFAULT 1,
    analytics TINYINT(1) NOT NULL DEFAULT 0,
    functional TINYINT(1) NOT NULL DEFAULT 0,
    marketing TINYINT(1) NOT NULL DEFAULT 0,
    consentSource VARCHAR(50) NOT NULL DEFAULT 'banner',
    policyVersion VARCHAR(30) NOT NULL DEFAULT '2025-01',
    createdAt DATETIME DEFAULT CURRENT_TIMESTAMP,
    updatedAt DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cookie_preferences_user (userId),
    UNIQUE KEY uq_cookie_preferences_session (sessionId),
    INDEX idx_cookie_preferences_user (userId),
    INDEX idx_cookie_preferences_session (sessionId),
    CONSTRAINT fk_cookie_preferences_user FOREIGN KEY (userId) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS user_consents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    userId VARCHAR(36) NULL,
    consentPurpose VARCHAR(80) NOT NULL,
    consentGranted TINYINT(1) NOT NULL DEFAULT 0,
    policyVersion VARCHAR(30) NOT NULL DEFAULT '2025-01',
    ipAddress VARCHAR(45) NULL,
    userAgent VARCHAR(255) NULL,
    consentDetails TEXT NULL,
    createdAt DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_consents_user (userId, consentPurpose),
    INDEX idx_user_consents_purpose (consentPurpose, createdAt),
    CONSTRAINT fk_user_consents_user FOREIGN KEY (userId) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS privacy_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    userId VARCHAR(36) NULL,
    requestType VARCHAR(40) NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'pending',
    requestReason TEXT NULL,
    requestedAt DATETIME DEFAULT CURRENT_TIMESTAMP,
    reviewedAt DATETIME NULL,
    reviewedBy VARCHAR(36) NULL,
    notes TEXT NULL,
    policyVersion VARCHAR(30) NOT NULL DEFAULT '2025-01',
    INDEX idx_privacy_requests_user (userId, status, requestedAt),
    INDEX idx_privacy_requests_status (status, requestedAt),
    CONSTRAINT fk_privacy_requests_user FOREIGN KEY (userId) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_privacy_requests_reviewer FOREIGN KEY (reviewedBy) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS email_preferences (
    id INT AUTO_INCREMENT PRIMARY KEY,
    userId VARCHAR(36) NOT NULL,
    promotionalEmails TINYINT(1) NOT NULL DEFAULT 0,
    transactionalEmails TINYINT(1) NOT NULL DEFAULT 1,
    unsubscribeToken CHAR(64) NOT NULL UNIQUE,
    updatedAt DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    createdAt DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_email_preferences_user FOREIGN KEY (userId) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_email_preferences_user (userId)
);

CREATE TABLE IF NOT EXISTS policy_versions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    version VARCHAR(30) NOT NULL UNIQUE,
    title VARCHAR(120) NOT NULL,
    effectiveAt DATETIME NOT NULL,
    summary TEXT NULL,
    createdAt DATETIME DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO settings (`key`, `value`) VALUES
    ('site_background', 'default'),
    ('require_email_verification', '1'),
    ('admin_email', 'admin@vendlink.com'),
    ('business_name', 'VendLink'),
    ('business_email', 'support@vendlink.com'),
    ('business_phone', '+63 33 000 0000'),
    ('business_address', 'Guimbal, Iloilo, Philippines'),
    ('business_hours', 'Monday - Saturday, 8:00 AM - 5:00 PM'),
    ('privacy_policy_version', '2025-01'),
    ('terms_version', '2025-01'),
    ('cookie_policy_version', '2025-01')
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);

INSERT INTO policy_versions (version, title, effectiveAt, summary)
VALUES ('2025-01', 'VendLink privacy and consent policy', NOW(), 'Baseline privacy, cookie, consent, and data-processing documentation for VendLink.')
ON DUPLICATE KEY UPDATE title = VALUES(title), summary = VALUES(summary);

-- No accounts are created by the schema. Use scripts/seed.php from the CLI
-- with VENDLINK_ADMIN_EMAIL and a strong VENDLINK_ADMIN_PASSWORD to create an admin.
