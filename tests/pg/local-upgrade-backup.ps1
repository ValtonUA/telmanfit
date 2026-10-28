#requires -Version 5.1
$ErrorActionPreference = 'Stop'
$runId = [guid]::NewGuid().ToString('N').Substring(0, 16)
$label = "telmanfit.local-upgrade-test=$runId"
$prefix = "telmanfit-upgrade-test-$runId"
$sourceName = "$prefix-source"
$restoreName = "$prefix-restore"
$volumes = @('database', 'database-secrets', 'local-state', 'epoch', 'storage')
$allVolumes = @()
$containers = @()
$image = 'postgres:18.6-bookworm@sha256:bbf83594798e144bff365a9c191c5d779f181fb3135902064ab8ff11918020fc'
$work = Join-Path ([IO.Path]::GetTempPath()) $prefix
$null = New-Item -ItemType Directory -Path $work

function Invoke-DockerChecked {
    param([Parameter(Mandatory)][string[]] $Arguments)
    $output = @(& docker @Arguments 2>&1)
    if ($LASTEXITCODE -ne 0) { throw "docker $($Arguments[0]) failed: $($output -join ' ')" }
    return $output
}

function Invoke-DockerProbe {
    param([Parameter(Mandatory)][string[]] $Arguments)
    $output = @(& docker @Arguments 2>&1)
    return [pscustomobject]@{ ExitCode = $LASTEXITCODE; Output = $output }
}

function Invoke-VolumeArchive {
    param([string] $VolumeName, [string] $ArchivePath, [switch] $Extract)
    $mountTarget = if ($Extract) { '/restore' } else { '/source' }
    $mode = if ($Extract) { '' } else { ',readonly' }
    $archiveName = Split-Path -Leaf $ArchivePath
    $mount = "type=volume,source=$VolumeName,target=$mountTarget$mode"
    $backupMount = "type=bind,source=$work,target=/backup"
    if ($Extract) {
        $null = Invoke-DockerChecked -Arguments @('run', '--rm', '--network', 'none', '--user', '0', '--entrypoint', 'tar', '--mount', $mount, '--mount', $backupMount, $image, '--numeric-owner', '-xpf', "/backup/$archiveName", '-C', $mountTarget)
        $compareTarget = "type=volume,source=$VolumeName,target=$mountTarget,readonly"
        $null = Invoke-DockerChecked -Arguments @('run', '--rm', '--network', 'none', '--user', '0', '--entrypoint', 'tar', '--mount', $compareTarget, '--mount', "type=bind,source=$work,target=/backup,readonly", $image, '--numeric-owner', '-dpf', "/backup/$archiveName", '-C', $mountTarget)
    } else {
        $null = Invoke-DockerChecked -Arguments @('run', '--rm', '--network', 'none', '--user', '0', '--entrypoint', 'tar', '--mount', $mount, '--mount', $backupMount, $image, '--numeric-owner', '-cpf', "/backup/$archiveName", '-C', $mountTarget, '.')
        $entries = Invoke-DockerChecked -Arguments @('run', '--rm', '--network', 'none', '--user', '0', '--entrypoint', 'tar', '--mount', "type=bind,source=$work,target=/backup,readonly", $image, '-tf', "/backup/$archiveName")
        if (-not $entries.Count) { throw "Archive is empty or unreadable: $archiveName" }
        $null = Invoke-DockerChecked -Arguments @('run', '--rm', '--network', 'none', '--user', '0', '--entrypoint', 'tar', '--mount', $mount, '--mount', "type=bind,source=$work,target=/backup,readonly", $image, '--numeric-owner', '-dpf', "/backup/$archiveName", '-C', $mountTarget)
    }
}

function New-LabeledVolume {
    param([string] $Name)
    $null = Invoke-DockerChecked -Arguments @('volume', 'create', '--label', $label, $Name)
    $script:allVolumes += $Name
}

function Assert-TestLabel {
    param([string] $ContainerName)
    $json = (Invoke-DockerChecked -Arguments @('inspect', $ContainerName)) -join "`n" | ConvertFrom-Json
    if ($json[0].Config.Labels.'telmanfit.local-upgrade-test' -ne $runId) { throw "Refusing to remove non-test container $ContainerName" }
}

try {
    $resolved = Invoke-DockerChecked -Arguments @('image', 'inspect', $image, '--format', '{{.Id}}')
    if (-not $resolved.Count) { throw 'Pinned PostgreSQL 18.6 image is unavailable locally.' }

    foreach ($volume in $volumes) {
        New-LabeledVolume -Name "${prefix}-source-$volume"
        New-LabeledVolume -Name "${prefix}-restored-$volume"
    }
    $sourceDb = "${prefix}-source-database"
    $sourceSecrets = "${prefix}-source-database-secrets"
    $null = Invoke-DockerChecked -Arguments @('run', '--rm', '--network', 'none', '--mount', "type=volume,source=$sourceSecrets,target=/secrets", '--entrypoint', 'sh', $image, '-c', "printf '%s' 'synthetic-password' > /secrets/password; chmod 600 /secrets/password")
    $null = Invoke-DockerChecked -Arguments @('run', '--rm', '--network', 'none', '--mount', "type=volume,source=${prefix}-source-epoch,target=/epoch", '--entrypoint', 'sh', $image, '-c', "printf '%s' '$runId' > /epoch/epoch")
    $null = Invoke-DockerChecked -Arguments @('run', '--rm', '--network', 'none', '--mount', "type=volume,source=${prefix}-source-local-state,target=/state", '--entrypoint', 'sh', $image, '-c', "printf '%s' 'synthetic-runtime-state' > /state/runtime-fixture")
    $null = Invoke-DockerChecked -Arguments @('run', '--rm', '--network', 'none', '--mount', "type=volume,source=${prefix}-source-storage,target=/storage", '--entrypoint', 'sh', $image, '-c', "printf '%s' 'private-file-fixture' > /storage/private-fixture")

    $null = Invoke-DockerChecked -Arguments @('run', '-d', '--name', $sourceName, '--label', "telmanfit.local-upgrade-test=$runId", '--network', 'none', '--mount', "type=volume,source=$sourceDb,target=/var/lib/postgresql", '--mount', "type=volume,source=$sourceSecrets,target=/run/database-secrets,readonly", '-e', 'POSTGRES_PASSWORD_FILE=/run/database-secrets/password', $image)
    $containers += $sourceName
    $ready = $false
    for ($i = 0; $i -lt 60; $i++) {
        $probe = Invoke-DockerProbe -Arguments @('exec', $sourceName, 'pg_isready', '-U', 'postgres')
        if ($probe.ExitCode -eq 0 -and (($probe.Output -join ' ').Contains('accepting connections'))) { $ready = $true; break }
        Start-Sleep -Seconds 1
    }
    if (-not $ready) { throw 'Disposable source PostgreSQL did not become ready.' }

    $database = "telmanfit_local_$runId"
    Write-Output "stage=create-fixture-database name=$database"
    $null = Invoke-DockerChecked -Arguments @('exec', $sourceName, 'createdb', '-U', 'postgres', '--encoding=UTF8', $database)
    $dbExists = (Invoke-DockerChecked -Arguments @('exec', $sourceName, 'psql', '-X', '-U', 'postgres', '-d', 'postgres', '-Atc', "SELECT count(*) FROM pg_database WHERE datname='$database'")) -join ''
    if ($dbExists -cne '1') { throw 'Disposable fixture database was not created.' }
    Write-Output 'stage=populate-fixtures'
    $null = Invoke-DockerChecked -Arguments @('exec', $sourceName, 'psql', '-X', '-U', 'postgres', '-d', $database, '-v', 'ON_ERROR_STOP=1', '-c', "COMMENT ON DATABASE `"$database`" IS 'telmanfit-compose:$runId';", '-c', 'CREATE TABLE catalog_images (id integer PRIMARY KEY, image_url text); INSERT INTO catalog_images SELECT id, CASE WHEN id <= 85 THEN ''https://images.example.invalid/''||id ELSE NULL END FROM generate_series(1,108) id;', '-c', 'CREATE TABLE migrations (migration text PRIMARY KEY); INSERT INTO migrations SELECT ''synthetic_migration_''||id FROM generate_series(1,28) id;', '-c', 'CREATE TABLE auth_mfa_fixture (id integer PRIMARY KEY, secret_fingerprint text NOT NULL); INSERT INTO auth_mfa_fixture VALUES (1, md5(''synthetic-mfa-secret''));', '-c', 'CREATE TABLE recovery_fixture (id integer PRIMARY KEY, code_fingerprint text NOT NULL); INSERT INTO recovery_fixture VALUES (1, md5(''synthetic-recovery-code'')), (2, md5(''synthetic-recovery-code-2''));', '-c', "CREATE TABLE restore_fence_fixture (id integer PRIMARY KEY CHECK(id=1), epoch text NOT NULL); INSERT INTO restore_fence_fixture VALUES (1, '$runId');")
    $before = (Invoke-DockerChecked -Arguments @('exec', $sourceName, 'psql', '-X', '-U', 'postgres', '-d', $database, '-Atc', "SELECT (SELECT count(*) FROM catalog_images)||'/'||(SELECT count(*) FROM catalog_images WHERE image_url IS NOT NULL)||'/'||(SELECT count(*) FROM migrations)||'/'||(SELECT count(*) FROM auth_mfa_fixture)||'/'||(SELECT count(*) FROM recovery_fixture)||'/'||(SELECT epoch FROM restore_fence_fixture)||'/'||(SELECT shobj_description(oid, 'pg_database') FROM pg_database WHERE datname=current_database())")) -join ''
    if ($before -cne "108/85/28/1/2/$runId/telmanfit-compose:$runId") { throw 'Synthetic source invariants failed before backup.' }

    $dump = Join-Path $work 'postgresql.dump'
    $null = Invoke-DockerChecked -Arguments @('exec', $sourceName, 'pg_dump', '--format=custom', '--no-owner', '--no-privileges', '-U', 'postgres', '-d', $database, '-f', '/tmp/local-upgrade.dump')
    $null = Invoke-DockerChecked -Arguments @('cp', "${sourceName}:/tmp/local-upgrade.dump", $dump)
    $toc = Invoke-DockerChecked -Arguments @('exec', $sourceName, 'pg_restore', '--list', '/tmp/local-upgrade.dump')
    if ($toc.Count -lt 10) { throw 'Custom dump TOC is incomplete.' }
    $dumpHash = (Get-FileHash -LiteralPath $dump -Algorithm SHA256).Hash

    Write-Output 'stage=physical-backup-and-archives'
    $null = Invoke-DockerChecked -Arguments @('stop', '--time', '30', $sourceName)
    foreach ($volume in $volumes) {
        Invoke-VolumeArchive -VolumeName "${prefix}-source-$volume" -ArchivePath (Join-Path $work "$volume.tar")
    }
    foreach ($volume in $volumes) {
        Invoke-VolumeArchive -VolumeName "${prefix}-restored-$volume" -ArchivePath (Join-Path $work "$volume.tar") -Extract
    }
    $archiveHashes = @{}
    foreach ($volume in $volumes) {
        $archive = Join-Path $work "$volume.tar"
        $metadata = Get-Item -LiteralPath $archive
        $hash = (Get-FileHash -LiteralPath $archive -Algorithm SHA256).Hash
        if ($metadata.Length -le 0 -or -not $hash) { throw "Archive verification failed: $volume" }
        $archiveHashes[$volume] = "{0}:{1}" -f $metadata.Length, $hash
    }

    Write-Output 'stage=physical-restore-check'
    $restoreDb = "${prefix}-restored-database"
    $null = Invoke-DockerChecked -Arguments @('run', '-d', '--name', $restoreName, '--label', "telmanfit.local-upgrade-test=$runId", '--network', 'none', '--mount', "type=volume,source=$restoreDb,target=/var/lib/postgresql", '--mount', "type=volume,source=${prefix}-restored-database-secrets,target=/run/database-secrets,readonly", '-e', 'POSTGRES_PASSWORD_FILE=/run/database-secrets/password', $image)
    $containers += $restoreName
    $restoredReady = $false
    for ($i = 0; $i -lt 60; $i++) {
        $probe = Invoke-DockerProbe -Arguments @('exec', $restoreName, 'pg_isready', '-U', 'postgres')
        if ($probe.ExitCode -eq 0 -and (($probe.Output -join ' ').Contains('accepting connections'))) { $restoredReady = $true; break }
        Start-Sleep -Seconds 1
    }
    if (-not $restoredReady) { throw 'Restored physical volume did not start PostgreSQL.' }
    $after = (Invoke-DockerChecked -Arguments @('exec', $restoreName, 'psql', '-X', '-U', 'postgres', '-d', $database, '-Atc', "SELECT (SELECT count(*) FROM catalog_images)||'/'||(SELECT count(*) FROM catalog_images WHERE image_url IS NOT NULL)||'/'||(SELECT count(*) FROM migrations)||'/'||(SELECT count(*) FROM auth_mfa_fixture)||'/'||(SELECT count(*) FROM recovery_fixture)||'/'||(SELECT epoch FROM restore_fence_fixture)||'/'||(SELECT shobj_description(oid, 'pg_database') FROM pg_database WHERE datname=current_database())")) -join ''
    if ($after -cne $before) { throw 'Restored DB identity, migration history, catalog URLs or MFA fixtures differ.' }
    $storage = (Invoke-DockerChecked -Arguments @('run', '--rm', '--network', 'none', '--mount', "type=volume,source=${prefix}-restored-storage,target=/storage,readonly", '--entrypoint', 'cat', $image, '/storage/private-fixture')) -join ''
    $epoch = (Invoke-DockerChecked -Arguments @('run', '--rm', '--network', 'none', '--mount', "type=volume,source=${prefix}-restored-epoch,target=/epoch,readonly", '--entrypoint', 'cat', $image, '/epoch/epoch')) -join ''
    if ($storage -cne 'private-file-fixture' -or $epoch -cne $runId) { throw 'Restored storage/epoch volume fixture differs.' }
    $localState = (Invoke-DockerChecked -Arguments @('run', '--rm', '--network', 'none', '--mount', "type=volume,source=${prefix}-restored-local-state,target=/state,readonly", '--entrypoint', 'cat', $image, '/state/runtime-fixture')) -join ''
    if ($localState -cne 'synthetic-runtime-state') { throw 'Restored local-state fixture differs.' }

    Write-Output 'stage=logical-restore-check'
    $null = Invoke-DockerChecked -Arguments @('exec', $restoreName, 'createdb', '-U', 'postgres', '--template=template0', '--encoding=UTF8', 'logical_restore_check')
    $null = Invoke-DockerChecked -Arguments @('cp', $dump, "${restoreName}:/tmp/logical.dump")
    $null = Invoke-DockerChecked -Arguments @('exec', $restoreName, 'pg_restore', '--exit-on-error', '--no-owner', '--no-privileges', '-U', 'postgres', '-d', 'logical_restore_check', '/tmp/logical.dump')
    $logical = (Invoke-DockerChecked -Arguments @('exec', $restoreName, 'psql', '-X', '-U', 'postgres', '-d', 'logical_restore_check', '-Atc', "SELECT current_database()||'/'||(SELECT count(*) FROM catalog_images)||'/'||(SELECT count(*) FROM catalog_images WHERE image_url IS NOT NULL)||'/'||(SELECT count(*) FROM migrations)||'/'||(SELECT count(*) FROM auth_mfa_fixture)||'/'||(SELECT count(*) FROM recovery_fixture)||'/'||(SELECT epoch FROM restore_fence_fixture)")) -join ''
    $expectedLogical = "logical_restore_check/108/85/28/1/2/$runId"
    if ($logical -cne $expectedLogical) { throw 'Logical pg_restore did not preserve the synthetic invariants.' }
    Write-Output "Disposable PG18 backup/restore PASS; physical volumes=5 ($($archiveHashes.Count) SHA256s); logical dump SHA256=$dumpHash; rows/URLs=108/85; auth/MFA and epoch fingerprints preserved."
} finally {
    foreach ($container in $containers) {
        $inspect = Invoke-DockerChecked -Arguments @('inspect', $container) 2>$null
        if ($LASTEXITCODE -eq 0 -and $inspect) {
            Assert-TestLabel -ContainerName $container
            $null = Invoke-DockerChecked -Arguments @('rm', '-f', $container)
        }
    }
    foreach ($volume in $allVolumes) {
        $details = Invoke-DockerChecked -Arguments @('volume', 'inspect', $volume) 2>$null
        if ($LASTEXITCODE -eq 0 -and $details) {
            $metadata = ($details -join "`n") | ConvertFrom-Json
            if ($metadata[0].Labels.'telmanfit.local-upgrade-test' -ne $runId) { throw "Refusing to remove non-test volume $volume" }
            $null = Invoke-DockerChecked -Arguments @('volume', 'rm', $volume)
        }
    }
    Remove-Item -LiteralPath $work -Recurse -Force
}
