ALTER TABLE builds
  ADD COLUMN target_id VARCHAR(190) NULL AFTER build_uuid,
  ADD COLUMN target_name VARCHAR(120) NULL AFTER target_id;
