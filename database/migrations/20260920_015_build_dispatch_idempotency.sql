ALTER TABLE builds ADD COLUMN client_request_uuid CHAR(36) NULL AFTER build_uuid, ADD UNIQUE KEY uq_build_client_request_uuid(client_request_uuid);
