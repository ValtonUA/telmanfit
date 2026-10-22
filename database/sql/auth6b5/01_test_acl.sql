-- Застосовується superuser ТІЛЬКИ в новому isolated runner після migrate.
CREATE ROLE telmanfit_retention_owner NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
CREATE ROLE telmanfit_retention_executor NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
CREATE ROLE telmanfit_archive_verifier NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
CREATE ROLE telmanfit_hold_registrar NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
GRANT USAGE ON SCHEMA public TO telmanfit_retention_owner,telmanfit_retention_executor,telmanfit_archive_verifier,telmanfit_hold_registrar;
GRANT SELECT ON auth_retention_holds,auth_archive_manifests,auth_retention_runs,auth_retention_batches,auth_recovery_cases TO telmanfit_retention_owner;
GRANT SELECT,DELETE ON auth_audit_events TO telmanfit_retention_owner;
-- FOR UPDATE потребує UPDATE privilege, trigger однаково забороняє actual UPDATE.
GRANT UPDATE ON auth_audit_events,auth_retention_runs TO telmanfit_retention_owner;
GRANT INSERT ON auth_retention_batches,auth_audit_events TO telmanfit_retention_owner;
GRANT USAGE ON SEQUENCE auth_audit_events_id_seq TO telmanfit_retention_owner;
ALTER FUNCTION auth_retention_purge_audit(uuid,integer,uuid,bigint[]) OWNER TO telmanfit_retention_owner;
GRANT EXECUTE ON FUNCTION auth_retention_purge_audit(uuid,integer,uuid,bigint[]) TO telmanfit_retention_executor;
GRANT SELECT ON auth_audit_events,auth_archive_manifests,auth_retention_runs,auth_retention_batches,auth_security_runtime TO telmanfit_retention_executor;
GRANT SELECT,INSERT ON auth_retention_runs TO telmanfit_retention_executor;
GRANT SELECT,INSERT ON auth_archive_manifests TO telmanfit_archive_verifier;
GRANT SELECT,INSERT,UPDATE ON auth_retention_holds TO telmanfit_hold_registrar;
GRANT SELECT ON auth_security_runtime TO telmanfit_notice_writer,telmanfit_notice_dispatcher;
GRANT telmanfit_retention_executor,telmanfit_archive_verifier,telmanfit_hold_registrar TO telmanfit_test_runner;
-- Важливо: NO membership telmanfit_retention_owner, навіть test runner.
GRANT SELECT ON users,auth_flows,auth_challenges,mfa_recovery_codes,user_totp_factors,client_invitations,auth_security_notice_outbox,failed_jobs TO telmanfit_retention_owner;
GRANT UPDATE ON users,user_totp_factors TO telmanfit_retention_owner;
GRANT DELETE ON auth_flows,auth_challenges,mfa_recovery_codes,user_totp_factors,client_invitations,auth_security_notice_outbox,failed_jobs TO telmanfit_retention_owner;
GRANT EXECUTE ON FUNCTION auth_retention_candidates(text,timestamptz) TO telmanfit_retention_owner;
ALTER FUNCTION auth_retention_cleanup(uuid,integer,text) OWNER TO telmanfit_retention_owner;
GRANT EXECUTE ON FUNCTION auth_retention_cleanup(uuid,integer,text) TO telmanfit_retention_executor;
GRANT SELECT ON auth_recovery_grants TO telmanfit_retention_owner;

GRANT SELECT ON auth_security_runtime TO telmanfit_retention_owner;
ALTER FUNCTION auth_retention_finish(uuid,boolean) OWNER TO telmanfit_retention_owner;
GRANT EXECUTE ON FUNCTION auth_retention_finish(uuid,boolean) TO telmanfit_retention_executor;

GRANT UPDATE ON failed_jobs TO telmanfit_retention_owner;
