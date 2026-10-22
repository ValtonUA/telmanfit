-- Виключно новий synthetic cluster. Production provisioning не виконується.
CREATE ROLE telmanfit_budget_owner NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
CREATE ROLE telmanfit_budget_runtime NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
GRANT USAGE, CREATE ON SCHEMA public TO telmanfit_budget_owner;
GRANT SELECT, INSERT, UPDATE, DELETE ON public.auth_rate_budgets TO telmanfit_budget_owner;
GRANT EXECUTE ON FUNCTION public.auth6b6_validate(),public.auth6b6_policy(text),public.auth6b6_events(timestamptz[],timestamptz,text) TO telmanfit_budget_owner;
ALTER FUNCTION public.auth6b6_group(text[],text[],text) OWNER TO telmanfit_budget_owner;
ALTER FUNCTION public.auth6b6_cleanup(integer) OWNER TO telmanfit_budget_owner;
GRANT EXECUTE ON FUNCTION public.auth6b6_group(text[],text[],text),public.auth6b6_cleanup(integer) TO telmanfit_budget_runtime;
GRANT telmanfit_budget_runtime TO telmanfit_test_runner;
REVOKE CREATE ON SCHEMA public FROM telmanfit_budget_owner;
