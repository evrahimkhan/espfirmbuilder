-- ESPForge production hardening migration.
-- Back up the database before applying. Run once during deployment, not per request.
START TRANSACTION;

-- Consolidate historical duplicate repository rows without losing their builds.
CREATE TEMPORARY TABLE repository_keepers AS
SELECT user_id, LOWER(full_name) AS normalized_name, MIN(id) AS keeper_id
FROM repositories GROUP BY user_id, LOWER(full_name);

UPDATE builds b
JOIN repositories r ON r.id=b.repo_id
JOIN repository_keepers k ON k.user_id=r.user_id AND k.normalized_name=LOWER(r.full_name)
SET b.repo_id=k.keeper_id
WHERE b.repo_id<>k.keeper_id;

DELETE r FROM repositories r
JOIN repository_keepers k ON k.user_id=r.user_id AND k.normalized_name=LOWER(r.full_name)
WHERE r.id<>k.keeper_id;

ALTER TABLE repositories ADD UNIQUE INDEX uq_repositories_user_full_name (user_id, full_name);
ALTER TABLE builds
  ADD COLUMN build_uuid CHAR(36) NULL AFTER repo_id,
  ADD UNIQUE INDEX uq_build_uuid (build_uuid),
  ADD INDEX ix_build_status (status, id);

COMMIT;
