-- Reviewed psql template, НЕ виконується Laravel migration.
-- Потрібна змінна runtime_role; ніколи не owner/migrator/verifier.
-- До B4 recovery mutation functions лишаються owner-only; PUBLIC EXECUTE revoked.
GRANT SELECT ON public.auth_recovery_gates TO :"runtime_role";
-- D3/B4/B5 мають окремо визначити verifier/operator identities та розподіл grants.
-- Не GRANT INSERT approvals звичайному runtime і не GRANT mutation EXECUTE до B4.
