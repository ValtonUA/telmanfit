#!/usr/bin/env bash
set -euo pipefail
# Контрактні перевірки fail-closed поведінки без БД. Це НЕ PG-регресія.
here=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
work=$(mktemp -d)
trap 'rm -rf -- "$work"' EXIT
mkdir -p "$work/bin" "$work/suite"
cp "$here/run-pg-tests.sh" "$here"/t0*.sql "$work/suite/"
cat > "$work/bin/psql" <<'MOCK'
#!/usr/bin/env bash
sql=$(cat)
[[ $MODE != identity ]] || exit 1
case "$sql" in
  *'SELECT current_database(), current_user, session_user;'*) echo 'telmanfit_test|telmanfit_test_runner|telmanfit_test_runner'; exit;;
  *'SELECT extversion'*) echo 1.8; exit;;
  *'DO $empty$'*) [[ $MODE != residue ]]; exit;;
  *'SELECT count(*) FROM pg_class'*) echo 37; exit;;
esac
group=6
for n in 1 2 3 4 5; do [[ $sql != *"t0$n results"* ]] || group=$n; done
[[ $group != 6 || $MODE != inventory ]] || exit 3
counts=(0 9 16 24 12 15 0)
count=${counts[$group]}
start=1; [[ $group != 1 ]] || start=0
for ((n=start;n<start+count;n++)); do
    [[ $MODE != missing || $group != 3 || $n != 12 ]] || continue
    status=PASS
    [[ $MODE != gap || $group != 4 || $n != 7 ]] || status=GAP
    [[ $MODE != fail || $group != 5 || $n != 9 ]] || status=FAIL
    printf '%d|T%d.%d|%s|mock\n' "$((n-start+1))" "$group" "$n" "$status"
done
[[ $MODE != duplicate || $group != 2 ]] || echo '17|T2.1|PASS|duplicate'
[[ $count == 0 || $MODE == summary ]] || printf 'PASS|%d\n' "$count"
MOCK
chmod +x "$work/bin/psql"
export PATH="$work/bin:$PATH"
unset PG_TEST_CONTAINER
for pair in ok:0 identity:2 missing:5 duplicate:5 gap:5 fail:5 summary:5 inventory:4 residue:6; do
    export MODE=${pair%:*}
    expected=${pair#*:}
    code=0
    bash "$work/suite/run-pg-tests.sh" > "$work/$MODE.log" 2>&1 || code=$?
    [[ $code == "$expected" ]] || { cat "$work/$MODE.log"; echo "$MODE: expected $expected, got $code" >&2; exit 1; }
    printf '%s: exit %s (expected)\n' "$MODE" "$code"
done
# Відсутній файл має зупинити suite до SQL-тестів.
rm -- "$work/suite/t04_assignments.sql"
export MODE=ok
code=0
bash "$work/suite/run-pg-tests.sh" > "$work/missing-file.log" 2>&1 || code=$?
[[ $code == 5 ]] || exit 1
echo 'missing-file: exit 5 (expected)'
