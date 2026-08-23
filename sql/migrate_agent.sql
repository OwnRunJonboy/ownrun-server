-- Agent job support (Phase 4). Safe to re-run on MariaDB 10.3+ if columns already exist? 
-- Prefer scripts/migrate_agent.php which checks before altering.

ALTER TABLE jobs
    ADD COLUMN progress_payload JSON NULL AFTER result_payload;

ALTER TABLE runners
    ADD COLUMN agent_profiles JSON NULL AFTER available_models,
    ADD COLUMN agent_models JSON NULL AFTER agent_profiles,
    ADD COLUMN agent_projects JSON NULL AFTER agent_models;
