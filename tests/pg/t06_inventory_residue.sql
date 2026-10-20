-- TelmanFit: live schema inventory (step 6) + residue check (step 8/10). READ ONLY.
\pset footer off
\timing off

\echo '=== [1] BUSINESS TABLES ==='
SELECT count(*) AS business_tables
FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
WHERE n.nspname='public' AND c.relkind='r';
SELECT string_agg(c.relname, ', ' ORDER BY c.relname) AS table_names
FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
WHERE n.nspname='public' AND c.relkind='r';

\echo '=== [2] INDEXES ==='
SELECT count(*) AS all_indexes FROM pg_indexes WHERE schemaname='public';
SELECT count(*) AS partial_indexes FROM pg_indexes WHERE schemaname='public' AND indexdef LIKE '%WHERE%';
SELECT count(*) AS unique_partial_indexes FROM pg_indexes
WHERE schemaname='public' AND indexdef LIKE 'CREATE UNIQUE INDEX%' AND indexdef LIKE '%WHERE%';
SELECT string_agg(indexname, ', ' ORDER BY indexname) AS partial_index_list
FROM pg_indexes WHERE schemaname='public' AND indexdef LIKE '%WHERE%';

\echo '=== [3] TRIGGERS ==='
SELECT count(*) AS triggers
FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid JOIN pg_namespace n ON n.oid=c.relnamespace
WHERE n.nspname='public' AND NOT t.tgisinternal;
SELECT t.tgname || ' @ ' || c.relname || ' deferrable=' || t.tgdeferrable || ' initdeferred=' || t.tginitdeferred AS trigger_list
FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid JOIN pg_namespace n ON n.oid=c.relnamespace
WHERE n.nspname='public' AND NOT t.tgisinternal ORDER BY c.relname, t.tgname;

\echo '=== [4] EXCLUDE CONSTRAINTS ==='
SELECT conname || ' @ ' || conrelid::regclass::text || ' deferrable=' || condeferrable
       || ' initdeferred=' || condeferred || ' :: ' || pg_get_constraintdef(oid) AS exclude_def
FROM pg_constraint WHERE contype='x';

\echo '=== [5] CROSS-MODULE FOREIGN KEYS (module 02) ==='
SELECT conname || ' @ ' || conrelid::regclass::text || ' :: ' || pg_get_constraintdef(oid) AS fk_def
FROM pg_constraint WHERE conname LIKE 'fk_%' ORDER BY conname;

\echo '=== [6] DEFERRABLE CONSTRAINT TRIGGERS (user-defined, non-internal) ==='
SELECT t.tgname || ' @ ' || c.relname || ' :: ' || pg_get_triggerdef(t.oid) AS constraint_trigger
FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid JOIN pg_namespace n ON n.oid=c.relnamespace
WHERE n.nspname='public' AND NOT t.tgisinternal AND t.tgconstraint <> 0;

\echo '=== [7] ROW COUNTS PER BUSINESS TABLE (must all be 0 after ROLLBACKed tests) ==='
SELECT format('SELECT %L AS tbl, count(*) AS rows FROM public.%I', c.relname, c.relname)
FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
WHERE n.nspname='public' AND c.relkind='r' ORDER BY 1
\gexec
