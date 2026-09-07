-- Access JWT revocation + refresh-token families
-- Applied by scripts/migrate_token_revocation.php

ALTER TABLE users
    ADD COLUMN token_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER locked_until;

ALTER TABLE refresh_tokens
    ADD COLUMN family_id CHAR(64) NOT NULL DEFAULT '' AFTER token_hash;

ALTER TABLE refresh_tokens
    ADD INDEX idx_family_id (family_id);
