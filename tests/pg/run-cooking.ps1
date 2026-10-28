#requires -Version 5.1
# Disposable PG18, no network/ports, new socket volume, no .env from host.
$ErrorActionPreference = 'Continue'
$root = (Resolve-Path (Join-Path $PSScriptRoot '../..')).Path
$suffix = [guid]::NewGuid().ToString('N')
$pg = "telmanfit-cooking-$suffix"
$socketVolume = "telmanfit-cooking-socket-$suffix"
$socket = "/tmp/telmanfit-stage1.$suffix/socket"
$image = 'sha256:bbf83594798e144bff365a9c191c5d779f181fb3135902064ab8ff11918020fc'
function Docker-Checked {
    & docker @args
    if ($LASTEXITCODE -ne 0) { throw "Docker failed: $($args[0])" }
}
try {
    Docker-Checked volume create $socketVolume
    Docker-Checked run -d --name $pg --network none --tmpfs /var/lib/postgresql --mount "type=volume,src=$socketVolume,dst=$socket" -e POSTGRES_HOST_AUTH_METHOD=trust --entrypoint sh $image -c "chmod 777 $socket; exec docker-entrypoint.sh postgres -c unix_socket_directories=/var/run/postgresql,$socket"
    $ready = $false
    for ($n = 0; $n -lt 60; $n++) {
        $logs = & docker logs $pg 2>&1 | Out-String
        if ($logs.Contains('PostgreSQL init process complete; ready for start up.')) {
            & docker exec $pg pg_isready -h $socket -U postgres | Out-Null
            if ($LASTEXITCODE -eq 0) { $ready = $true; break }
        }
        Start-Sleep -Milliseconds 500
    }
    if (-not $ready) { throw 'PG18 startup timed out' }
    Docker-Checked exec $pg psql -h $socket -X -U postgres -v ON_ERROR_STOP=1 -c 'CREATE ROLE telmanfit_test_runner LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;' -c 'CREATE DATABASE telmanfit_stage1_test OWNER telmanfit_test_runner;' -c 'CREATE ROLE telmanfit_notice_writer NOLOGIN;' -c 'CREATE ROLE telmanfit_notice_dispatcher NOLOGIN;' -c 'GRANT telmanfit_notice_writer,telmanfit_notice_dispatcher TO telmanfit_test_runner;'
    Docker-Checked exec $pg psql -h $socket -X -U postgres -d telmanfit_stage1_test -v ON_ERROR_STOP=1 -c 'CREATE EXTENSION btree_gist;'
    # Copy current sources into a disposable layer; bundled vendor is read only input.
    Docker-Checked run --rm --network none --volumes-from $pg --mount "type=bind,src=$root,dst=/source,readonly" --entrypoint env telmanfit-local-app:latest -i PATH=/usr/local/bin:/usr/bin:/bin sh /source/tests/pg/cooking-runtime.sh $socket
} finally {
    & docker rm -f $pg | Out-Null
    & docker volume rm $socketVolume | Out-Null
}
