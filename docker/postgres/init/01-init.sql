-- Runs once, on first initialization of an empty data volume.
-- The primary database (POSTGRES_DB) is created by the entrypoint; this adds
-- the parallel database used by phpunit.xml so `php artisan test` never touches
-- development data.
CREATE DATABASE lexia_testing OWNER lexia;

-- pgvector ships with the pgvector/pgvector image but is not enabled per-database.
-- The Jurisprudence/RAG module will call Schema::ensureVectorExtensionExists(),
-- which is idempotent; enabling it up front keeps both databases symmetrical.
\connect lexia
CREATE EXTENSION IF NOT EXISTS vector;

\connect lexia_testing
CREATE EXTENSION IF NOT EXISTS vector;
