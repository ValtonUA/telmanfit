#requires -Version 7.0
[CmdletBinding()]
param([switch] $Run)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$root = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$project = 'telmanfit-e2e-' + [guid]::NewGuid().ToString('N').Substring(0, 12)
$port = Get-Random -Minimum 20000 -Maximum 59999
$tempRoot = Join-Path ([IO.Path]::GetTempPath()) 'TelmanFit-E2E'
$overrideDirectory = Join-Path $tempRoot 'overrides'
$overridePath = Join-Path $overrideDirectory "$project.yaml"
$testState = Join-Path $tempRoot $project
$backupDirectory = Join-Path $testState 'backups'
$logDirectory = Join-Path $testState 'updates'
$workingVolumes = @('telmanfit-local_database', 'telmanfit-local_database-secrets', 'telmanfit-local_local-state', 'telmanfit-local_epoch', 'telmanfit-local_storage')
$testVolumeKinds = @('database', 'database-secrets', 'local-state', 'epoch', 'storage')
$testVolumes = @($testVolumeKinds | ForEach-Object { "${project}_$_" })
$imageNames = @("$project-app", "$project-web", "$project-database")
$compose = @('-p', $project, '--project-directory', $root, '-f', (Join-Path $root 'compose.yaml'), '-f', $overridePath)
$resourcesCreated = $false
$previousWebPort = $env:TELMANFIT_LOCAL_WEB_PORT

function Invoke-Checked {
    param([Parameter(Mandatory)][string] $Command, [Parameter(Mandatory)][string[]] $Arguments, [switch] $AllowFailure)
    $output = @(& $Command @Arguments 2>&1)
    $code = $LASTEXITCODE
    if ($code -ne 0 -and -not $AllowFailure) { throw "$Command exited ${code}: $($output -join [Environment]::NewLine)" }
    [pscustomobject]@{ code = $code; output = $output }
}

function Invoke-Docker {
    param([Parameter(Mandatory)][string[]] $Arguments, [switch] $AllowFailure)
    Invoke-Checked -Command docker -Arguments $Arguments -AllowFailure:$AllowFailure
}

function Invoke-Compose {
    param([Parameter(Mandatory)][string[]] $Arguments, [switch] $AllowFailure)
    Invoke-Docker -Arguments (@('compose') + $compose + $Arguments) -AllowFailure:$AllowFailure
}

function Get-Text {
    param($Result)
    return (@($Result.output | ForEach-Object { [string]$_ }) -join "`n").Trim()
}

function Convert-JsonOutput {
    param([Parameter(Mandatory)][string] $Text)
    $line = @($Text -split '\r?\n' | Where-Object { $_.TrimStart().StartsWith('{') } | Select-Object -Last 1)
    if (-not $line.Count) { throw "Command output did not include a JSON result (length=$($Text.Length))." }
    return ($line[0] | ConvertFrom-Json)
}

function Assert-Preflight {
    if ($project -eq 'telmanfit-local' -or $project -notmatch '^telmanfit-e2e-[a-f0-9]{12}$') { throw 'Invalid test project namespace.' }
    if ($port -eq 8098 -or $port -lt 20000 -or $port -gt 59999) { throw 'Invalid isolated host port.' }
    if (@($testVolumes | Where-Object { $_ -in $workingVolumes }).Count) { throw 'Test volume collides with a working volume.' }
    if (Test-Path -LiteralPath $testState) { throw "Unique test state path already exists: $testState" }

    $context = Get-Text (Invoke-Docker -Arguments @('context', 'show'))
    $os = Get-Text (Invoke-Docker -Arguments @('info', '--format', '{{.OSType}}'))
    if ($context -ne 'desktop-linux' -or $os -ne 'linux') { throw 'Expected the local desktop-linux Docker daemon.' }

    $configText = Get-Text (Invoke-Compose -Arguments @('config', '--format', 'json'))
    $config = $configText | ConvertFrom-Json
    if ($config.name -cne $project) { throw 'Effective Compose project name mismatch.' }
    if ($config.networks.local.internal -ne $true) { throw 'E2E Compose network must be isolated from external networks.' }
    if (@($config.services.PSObject.Properties.Name | Sort-Object) -join ',' -cne 'app,database,scheduler,setup,web,worker') { throw 'Unexpected Compose service inventory.' }
    if (@($config.volumes.PSObject.Properties.Name | Sort-Object) -join ',' -cne 'database,database-secrets,epoch,local-state,storage') { throw 'Unexpected Compose volumes.' }
    $dbPortsProperty = $config.services.PSObject.Properties['database'].Value.PSObject.Properties['ports']
    if ($dbPortsProperty -and @($dbPortsProperty.Value).Count) { throw 'Test PostgreSQL must not publish a host port.' }
    $webService = $config.services.PSObject.Properties['web'].Value
    $portsProperty = $webService.PSObject.Properties['ports']
    if (-not $portsProperty) { throw "Effective web service has no ports property; keys=$($webService.PSObject.Properties.Name -join ',')" }
    $webPorts = @($portsProperty.Value)
    if ($webPorts.Count -ne 1) { throw "Expected exactly one published test web port; effective value=$($portsProperty.Value | ConvertTo-Json -Compress -Depth 4); override=$yaml" }
    $webPort = $webPorts[0]
    if ([string]$webPort.published -ne [string]$port -or $webPort.host_ip -ne '127.0.0.1') { throw 'Test HTTP binding is not unique loopback-only.' }
    foreach ($service in @('app', 'database', 'web', 'setup', 'worker', 'scheduler')) {
        $expectedImage = if ($service -in @('setup', 'worker', 'scheduler')) { "$project-app" } else { "$project-$service" }
        if ($config.services.$service.image -cne $expectedImage) { throw "Non-unique image name for $service." }
    }
    foreach ($service in @('app', 'worker', 'scheduler')) {
        $sources = @($config.services.$service.volumes | ForEach-Object source | Sort-Object)
        if ($sources -join ',' -cne 'epoch,local-state,storage') { throw "Unexpected $service volume mapping." }
    }
    $dbSources = @($config.services.database.volumes | ForEach-Object source | Sort-Object)
    if ($dbSources -join ',' -cne 'database,database-secrets') { throw 'Unexpected database volume mapping.' }

    $existingProject = Invoke-Docker -Arguments @('ps', '-aq', '--filter', "label=com.docker.compose.project=$project")
    if ((Get-Text $existingProject)) { throw 'Unique test Compose project already exists.' }
    $volumeText = Get-Text (Invoke-Docker -Arguments @('volume', 'ls', '-q'))
    $volumes = @($volumeText -split '\r?\n' | Where-Object { $_ })
    foreach ($volume in $testVolumes) { if ($volume -in $volumes) { throw "Test volume already exists: $volume" } }
    foreach ($volume in $workingVolumes) { if ($volume -notin $volumes) { throw "Expected working volume missing during safety comparison: $volume (seen=$($volumes -join ','))." } }
    $imageText = Get-Text (Invoke-Docker -Arguments @('image', 'ls', '--format', '{{.Repository}}:{{.Tag}}'))
    $images = @($imageText -split '\r?\n' | Where-Object { $_ })
    foreach ($image in $imageNames) { if (@($images | Where-Object { $_ -like "$image`:*" }).Count) { throw "Unique test image tag already exists: $image" } }
    $portUsers = Get-Text (Invoke-Docker -Arguments @('ps', '-q', '--filter', "publish=$port"))
    if ($portUsers) { throw "Unique test port is already in use: $port" }
    $namesText = Get-Text (Invoke-Docker -Arguments @('ps', '-aq', '--format', '{{.Names}}'))
    $allNames = @($namesText -split '\r?\n' | Where-Object { $_ })
    foreach ($service in @('setup', 'database', 'app', 'worker', 'scheduler', 'web')) {
        if ("$project-$service-1" -in $allNames) { throw "Test container name already exists: $project-$service-1" }
    }
    $networkText = Get-Text (Invoke-Docker -Arguments @('network', 'ls', '-q', '--filter', "name=^${project}_local$"))
    $networks = @($networkText -split '\r?\n' | Where-Object { $_ })
    if ($networks.Count) { throw 'Test network name already exists.' }
    foreach ($volume in $workingVolumes) {
        $details = (Get-Text (Invoke-Docker -Arguments @('volume', 'inspect', $volume)) | ConvertFrom-Json)[0]
        if ($details.Labels.'com.docker.compose.project' -cne 'telmanfit-local') { throw "Working volume owner mismatch: $volume" }
    }
    $listener = $null
    try { $listener = [Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback, $port); $listener.Start() }
    catch { throw "Test port $port is occupied by a host process." }
    finally { if ($listener) { $listener.Stop() } }

    Write-Output "ISOLATION PREFLIGHT PASS project=$project port=$port volumes=$($testVolumes -join ',') images=$($imageNames -join ',')"
}

function Invoke-SyntheticBaseline {
    $null = Invoke-Compose -Arguments @('build', 'database', 'app', 'web')
    $null = Invoke-Compose -Arguments @('up', '-d', 'setup', 'database')
    $healthy = $false
    for ($i = 0; $i -lt 90; $i++) {
        $status = Get-Text (Invoke-Docker -Arguments @('inspect', '--format', '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}', "$project-database-1"))
        if ($status -eq 'healthy') { $healthy = $true; break }
        Start-Sleep -Seconds 2
    }
    if (-not $healthy) { throw 'Disposable PostgreSQL 18 did not become healthy.' }

    $migrationFiles = @(Get-ChildItem (Join-Path $root 'database/migrations') -Filter '*.php' -File | Sort-Object Name)
    $pendingNames = @('2026_10_28_000000_create_cooking_templates', '2026_10_28_010000_add_cooking_selection')
    foreach ($file in $migrationFiles) {
        $migration = [IO.Path]::GetFileNameWithoutExtension($file.Name)
        if ($migration -in $pendingNames) { continue }
        $null = Invoke-Compose -Arguments @('run', '--rm', '--no-deps', '--user', 'www-data', '--entrypoint', 'php', 'app', '-d', 'auto_prepend_file=/var/www/html/docker/local/load-env.php', 'artisan', 'migrate', "--path=database/migrations/$($file.Name)", '--force', '--no-interaction')
    }

    $databaseName = Get-Text (Invoke-Compose -Arguments @('exec', '-T', 'database', 'psql', '-X', '-U', 'postgres', '-Atc', "SELECT datname FROM pg_database WHERE datname LIKE 'telmanfit_local_%' AND datallowconn AND datname <> 'postgres' ORDER BY datname LIMIT 1"))
    if ($databaseName -notmatch '^telmanfit_local_[a-f0-9]{16}$') { throw 'Disposable DB identity is missing or malformed.' }
    $epochOutput = Get-Text (Invoke-Compose -Arguments @('run', '--rm', '--no-deps', '--entrypoint', 'cat', 'app', '/run/telmanfit-epoch/epoch'))
    $epochMatch = [regex]::Match($epochOutput, '(?i)(?<![0-9a-f])[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-8[0-9a-f]{3}-[0-9a-f]{12}(?![0-9a-f])')
    if (-not $epochMatch.Success) { throw "Disposable volume did not provide a valid independent RestoreFence epoch (captured output length=$($epochOutput.Length))." }
    $epoch = $epochMatch.Value.ToLowerInvariant()
    $hashOutput = Get-Text (Invoke-Compose -Arguments @('run', '--rm', '--no-deps', '--entrypoint', 'php', 'app', '-r', "echo password_hash('synthetic-e2e-recovery', PASSWORD_ARGON2ID);"))
    $hashMatch = [regex]::Match($hashOutput, '\$argon2id\$v=19\$m=[0-9]+,t=[0-9]+,p=[0-9]+\$[A-Za-z0-9+/]{16,}\$[A-Za-z0-9+/]{32,}')
    if (-not $hashMatch.Success) { throw "Could not create synthetic Argon2id recovery fixture; output did not contain a valid hash (length=$($hashOutput.Length))." }
    $hash = $hashMatch.Value
    $selector = [guid]::NewGuid().ToString()
    $batch = [guid]::NewGuid().ToString()
    $sql = @'
WITH new_foods AS (
  INSERT INTO foods (food_type) SELECT 'ingredient' FROM generate_series(1,47) RETURNING id
)
INSERT INTO ingredients (food_id,name,kcal_per_100g,protein_per_100g,fat_per_100g,carbs_per_100g,image_url)
SELECT id,'Synthetic ingredient '||row_number() OVER (),1,1,1,1,'https://example.invalid/e2e/ingredient/'||row_number() OVER ()||'.jpg' FROM new_foods;
INSERT INTO kitchen_equipment (name,image_url)
SELECT 'Synthetic equipment '||n,'https://example.invalid/e2e/equipment/'||n||'.jpg' FROM generate_series(1,37) n;
INSERT INTO recipes (title,image_url)
SELECT 'Synthetic recipe '||n,CASE WHEN n=1 THEN 'https://example.invalid/e2e/recipe/1.jpg' ELSE NULL END FROM generate_series(1,24) n;
INSERT INTO users (name,email,password,created_at,updated_at)
VALUES ('Synthetic E2E User','synthetic-e2e@example.invalid','not-a-login-credential',now(),now());
INSERT INTO user_totp_factors (user_id,secret_ciphertext,key_version,confirmed_at,created_at,updated_at)
SELECT id,repeat('synthetic-ciphertext-',6),'e2e-v1',now(),now(),now() FROM users WHERE email='synthetic-e2e@example.invalid';
INSERT INTO mfa_recovery_codes (user_id,selector,code_hash,batch_id,created_at)
SELECT user_id,'__SELECTOR__'::uuid,'__HASH__','__BATCH__'::uuid,now() FROM user_totp_factors;
INSERT INTO auth_security_runtime (id,restore_epoch,state,last_reconciled_at,issuance_not_before)
VALUES (1,'__EPOCH__'::uuid,'ready',clock_timestamp(),clock_timestamp());
'@
    $sql = $sql.Replace('__SELECTOR__', $selector).Replace('__HASH__', $hash).Replace('__BATCH__', $batch).Replace('__EPOCH__', $epoch)
    $null = Invoke-Compose -Arguments @('exec', '-T', 'database', 'psql', '-X', '-U', 'postgres', '-d', $databaseName, '-v', 'ON_ERROR_STOP=1', '-c', $sql)
    # Synthetic setup marker only: keeps first-run bootstrap from attempting migrations while cooking migrations remain pending.
    $null = Invoke-Compose -Arguments @('run', '--rm', '--no-deps', '--user', 'root', '--entrypoint', 'php', 'app', '-r', "file_put_contents('/tmp/telmanfit-auth4-compose/database-ready','1');")
    $null = Invoke-Compose -Arguments @('create', 'app', 'worker', 'scheduler', 'web')

    $probeOutput = Get-Text (Invoke-Compose -Arguments @('run', '--rm', '--no-deps', '--user', 'www-data', '--entrypoint', 'php', 'app', '-d', 'auto_prepend_file=/var/www/html/docker/local/load-env.php', 'docker/local/upgrade-check.php'))
    $probe = Convert-JsonOutput -Text $probeOutput
    if ($probe.status -ne 'PASS' -or $probe.restore_fence -ne 'PASS' -or $probe.identity.postgres_major -ne 18 -or $probe.identity.privileged -or $probe.identity.cluster_proof -notmatch '^telmanfit-compose:[a-f0-9]{16}$' -or $probe.catalog.rows -ne 108 -or $probe.catalog.urls -ne 85) {
        throw 'Synthetic pre-upgrade identity, RestoreFence or catalog data does not match the E2E fixture.'
    }
    if ($probe.tables.user_totp_factors.rows -ne 1 -or $probe.tables.mfa_recovery_codes.rows -ne 1) { throw 'Synthetic auth/MFA fixture was not created.' }
    if (@($pendingNames | Where-Object { $_ -notin $probe.migrations.pending }).Count) { throw 'Cooking migrations are not pending in synthetic baseline.' }
    return $probe
}

function Invoke-Coordinator {
    param([Parameter(Mandatory)][string] $Head)
    $coordinatorPath = Join-Path $root 'scripts/local-update.ps1'
    $coordinatorSource = [IO.File]::ReadAllText($coordinatorPath).Replace('$PSScriptRoot', "'$(Split-Path $coordinatorPath -Parent)'")
    $coordinatorScript = [scriptblock]::Create($coordinatorSource)
    $coordinatorArguments = @('-NoGitSync', '-ExpectedHead', $Head, '-IsolatedTestMode', '-TestProjectName', $project, '-TestPort', [string]$port, '-TestComposeOverride', $overridePath)
    & $coordinatorScript @coordinatorArguments
}

function Assert-ProjectHttp {
    foreach ($path in @('/up', '/app', '/admin/login')) {
        $status = Get-Text (Invoke-Checked -Command 'curl.exe' -Arguments @('-k', '-sS', '-o', 'NUL', '-w', '%{http_code}', "http://127.0.0.1:$port$path"))
        if ($status -notin @('200', '302')) { throw "HTTP endpoint $path returned $status." }
    }
}

function Remove-TestResources {
    $idsText = Get-Text (Invoke-Docker -Arguments @('ps', '-aq', '--filter', "label=com.docker.compose.project=$project"))
    $ids = @($idsText -split '\r?\n' | Where-Object { $_ })
    foreach ($id in $ids) {
        $details = (Get-Text (Invoke-Docker -Arguments @('inspect', $id)) | ConvertFrom-Json)[0]
        if ($details.Config.Labels.'com.docker.compose.project' -cne $project -or $details.Name.TrimStart('/') -notlike "$project-*") { throw "Refusing cleanup of unowned container $id." }
        foreach ($mount in @($details.Mounts | Where-Object { $_.Type -eq 'volume' })) {
            if ($mount.Name -in $workingVolumes -or $mount.Name -notin $testVolumes) { throw "Refusing cleanup: unexpected volume attachment $($mount.Name)." }
        }
        $null = Invoke-Docker -Arguments @('rm', '-f', $id)
    }
    foreach ($volume in $testVolumes) {
        $detailsResult = Invoke-Docker -Arguments @('volume', 'inspect', $volume) -AllowFailure
        if ($detailsResult.code -ne 0) { continue }
        $details = (Get-Text $detailsResult | ConvertFrom-Json)[0]
        if ($details.Labels.'com.docker.compose.project' -cne $project -or $volume -in $workingVolumes) { throw "Refusing cleanup of unowned volume $volume." }
        $attached = Get-Text (Invoke-Docker -Arguments @('ps', '-aq', '--filter', "volume=$volume"))
        if ($attached) { throw "Refusing cleanup: volume remains attached: $volume" }
        $null = Invoke-Docker -Arguments @('volume', 'rm', $volume)
    }
    $network = "${project}_local"
    $networkResult = Invoke-Docker -Arguments @('network', 'inspect', $network) -AllowFailure
    if ($networkResult.code -eq 0) {
        $details = (Get-Text $networkResult | ConvertFrom-Json)[0]
        if ($details.Labels.'com.docker.compose.project' -cne $project) { throw "Refusing cleanup of unowned network $network." }
        $null = Invoke-Docker -Arguments @('network', 'rm', $network)
    }
    foreach ($image in $imageNames) {
        $result = Invoke-Docker -Arguments @('image', 'inspect', $image) -AllowFailure
        if ($result.code -eq 0) {
            $details = (Get-Text $result | ConvertFrom-Json)[0]
            if ($details.Config.Labels.'telmanfit.e2e.project' -cne $project) { throw "Refusing cleanup of unowned image $image." }
            $null = Invoke-Docker -Arguments @('image', 'rm', $image)
        }
    }
    if (Test-Path -LiteralPath $testState) { Remove-Item -LiteralPath $testState -Recurse -Force }
}

try {
    New-Item -ItemType Directory -Path $overrideDirectory -Force | Out-Null
    $yaml = @"
services:
  setup:
    image: $project-app
  app:
    image: $project-app
    build:
      labels:
        telmanfit.e2e.project: $project
  worker:
    image: $project-app
  scheduler:
    image: $project-app
  database:
    image: $project-database
    build:
      labels:
        telmanfit.e2e.project: $project
  web:
    image: $project-web
    build:
      labels:
        telmanfit.e2e.project: $project
networks:
  local:
    internal: true
"@
    Set-Content -LiteralPath $overridePath -Value $yaml -Encoding utf8NoBOM
    $env:TELMANFIT_LOCAL_WEB_PORT = [string]$port
    Assert-Preflight
    if (-not $Run) { Write-Output "Read-only preflight only. Re-run with -Run for disposable E2E."; return }
    $resourcesCreated = $true
    $head = (Invoke-Checked -Command git -Arguments @('-C', $root, 'rev-parse', 'HEAD')) | ForEach-Object { Get-Text $_ }
    $status = Get-Text (Invoke-Checked -Command git -Arguments @('-C', $root, 'status', '--porcelain=v1'))
    if ($status) { throw 'E2E requires a clean committed checkout so the coordinator expected-head guard can run.' }
    $before = Invoke-SyntheticBaseline
    Invoke-Coordinator -Head $head
    Assert-ProjectHttp
    $snapshotArgs = @('run', '--rm', '--no-deps', '--user', 'www-data', '--entrypoint', 'php', 'app', '-d', 'auto_prepend_file=/var/www/html/docker/local/load-env.php', 'docker/local/upgrade-check.php')
    $after = Convert-JsonOutput -Text (Get-Text (Invoke-Compose -Arguments $snapshotArgs))
    if ($after.status -ne 'PASS' -or $after.restore_fence -ne 'PASS' -or $after.catalog.rows -ne 108 -or $after.catalog.urls -ne 85) { throw 'Post-upgrade synthetic data or RestoreFence check failed.' }
    foreach ($table in @('users', 'user_totp_factors', 'mfa_recovery_codes')) {
        if ($before.tables.$table.fingerprint -cne $after.tables.$table.fingerprint) { throw "Auth/MFA table changed: $table" }
    }
    if ($before.identity.cluster_proof -cne $after.identity.cluster_proof) { throw 'Post-upgrade DB cluster proof changed.' }
    $backupCount = @(Get-ChildItem -LiteralPath $backupDirectory -Directory -ErrorAction SilentlyContinue).Count
    if ($backupCount -ne 1) { throw "Expected one pre-upgrade backup set; found $backupCount." }
    $firstFingerprint = $after.catalog.fingerprint
    Invoke-Coordinator -Head $head
    Assert-ProjectHttp
    $rerun = Convert-JsonOutput -Text (Get-Text (Invoke-Compose -Arguments $snapshotArgs))
    if ($rerun.catalog.fingerprint -cne $firstFingerprint -or @($rerun.migrations.pending).Count -ne 0) { throw 'No-pending rerun changed catalog data or left pending migrations.' }
    $backupCountAfter = @(Get-ChildItem -LiteralPath $backupDirectory -Directory -ErrorAction SilentlyContinue).Count
    if ($backupCountAfter -ne $backupCount) { throw 'No-pending rerun created an unnecessary backup.' }
    Write-Output "ISOLATED COORDINATOR E2E PASS project=$project urls=$($rerun.catalog.urls) backup_sets=$backupCount RestoreFence=PASS"
} finally {
    try { if ($resourcesCreated) { Remove-TestResources } }
    finally {
        if (Test-Path -LiteralPath $overridePath) { Remove-Item -LiteralPath $overridePath -Force }
        $env:TELMANFIT_LOCAL_WEB_PORT = $previousWebPort
    }
}

