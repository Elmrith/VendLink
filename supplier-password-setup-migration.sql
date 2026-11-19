-- Safe, additive migration for supplier password setup state.
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS passwordSetupCompletedAt DATETIME NULL;

-- Preserve access for suppliers who completed the older setup flow, while leaving
-- suppliers with an outstanding setup token marked as not ready.
UPDATE users
SET passwordSetupCompletedAt = COALESCE(updatedAt, createdAt, NOW())
WHERE role = 'supplier'
  AND approvalStatus = 'approved'
  AND accountSetupTokenHash IS NULL
  AND passwordSetupCompletedAt IS NULL;