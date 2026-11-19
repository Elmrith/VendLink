ALTER TABLE users
    ADD COLUMN IF NOT EXISTS businessPermitPath VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS businessPermitReviewStatus ENUM('pending', 'verified', 'rejected') NOT NULL DEFAULT 'pending';
