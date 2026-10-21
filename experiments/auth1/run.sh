#!/usr/bin/env bash
set -euo pipefail
spike_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
export AUTH1_VENDOR=${AUTH1_VENDOR:-"$spike_dir/../../vendor"}
# Не завантажувати .env застосунку. App, ключ, SQLite та sessions створюються кожним тестом.
spike_tmp=$(mktemp -d -t telmanfit-auth1-run-XXXXXXXX)
trap 'rm -rf -- "$spike_tmp"' EXIT
if [[ -z ${AUTH1_UPSTREAM:-} ]]; then
    python3 "$spike_dir/fetch-upstream.py" "$spike_tmp/upstream"
    export AUTH1_UPSTREAM="$spike_tmp/upstream"
fi
python3 "$spike_dir/resolve.py"
php "$AUTH1_VENDOR/bin/phpunit" --no-configuration --do-not-cache-result \
    --fail-on-warning --fail-on-risky --bootstrap "$spike_dir/bootstrap.php" \
    "$spike_dir/SpikeTest.php" --colors=never
