[CmdletBinding()]
param(
    [switch] $NoGitSync,
    [string] $ExpectedHead,
    [string] $AcknowledgeJournal,
    [switch] $IsolatedTestMode,
    [string] $TestProjectName,
    [int] $TestPort,
    [string] $TestComposeOverride
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$script:ProjectName = 'telmanfit-local'
$script:ProjectPrefix = 'telmanfit-local'
$script:PublishedPort = '8098'
$script:TestMode = $false

function Initialize-IsolationMode {
    if (-not $IsolatedTestMode) {
        if ($TestProjectName -or $TestPort -or $TestComposeOverride) { throw 'Test-only parameters require -IsolatedTestMode.' }
        return
    }
    if (-not $NoGitSync -or -not $ExpectedHead) { throw 'Isolated mode requires -NoGitSync and -ExpectedHead.' }
    $context = New-IsolatedTestContext -ProjectName $TestProjectName -Port $TestPort
    if (-not $TestComposeOverride) { throw 'Isolated mode requires a test Compose override.' }
    $tempRoot = [IO.Path]::GetFullPath((Join-Path ([IO.Path]::GetTempPath()) 'TelmanFit-E2E')).TrimEnd('\')
    $override = [IO.Path]::GetFullPath($TestComposeOverride)
    if (-not $override.StartsWith($tempRoot + '\', [StringComparison]::OrdinalIgnoreCase)) {
        throw 'Isolated Compose override must be inside the private TelmanFit-E2E temp directory.'
    }
    if (-not (Test-Path -LiteralPath $override -PathType Leaf)) { throw 'Isolated Compose override does not exist.' }
    $script:ProjectName = $context.project
    $script:ProjectPrefix = $context.project
    $script:PublishedPort = [string] $context.port
    $env:TELMANFIT_LOCAL_WEB_PORT = $script:PublishedPort
    $script:TestMode = $true
}

function Get-ContainerName {
    param([Parameter(Mandatory)][string] $Service)
    return "$($script:ProjectName)-$Service-1"
}

function Get-VolumeName {
    param([Parameter(Mandatory)][string] $Volume)
    return "$($script:ProjectName)_$Volume"
}

function Get-ImageName {
    param([Parameter(Mandatory)][string] $Service)
    if ($script:TestMode) { return "$($script:ProjectName)-$Service" }
    return "telmanfit-local-$Service"
}

function New-IsolatedTestContext {
    param([Parameter(Mandatory)][string] $ProjectName, [Parameter(Mandatory)][int] $Port)
    if ($ProjectName -cnotmatch '^telmanfit-e2e-[a-f0-9]{12}$' -or $ProjectName -ceq 'telmanfit-local') {
        throw 'Ізольований проєкт має формат telmanfit-e2e-<12 lowercase hex>.'
    }
    if ($Port -lt 20000 -or $Port -gt 59999 -or $Port -eq 8098) { throw 'Ізольований порт має бути в діапазоні 20000..59999.' }
    $kinds = @('database', 'database-secrets', 'local-state', 'epoch', 'storage')
    $workingVolumes = @($kinds | ForEach-Object { "telmanfit-local_$_" })
    $testVolumes = @($kinds | ForEach-Object { "${ProjectName}_$_" })
    if (@($testVolumes | Where-Object { $_ -in $workingVolumes }).Count -ne 0) { throw 'Ізольований volume name зіставився з робочим TelmanFit volume.' }
    $services = @('setup', 'database', 'app', 'worker', 'scheduler', 'web')
    $imageNames = @('app', 'web', 'database')
    [pscustomobject]@{
        project = $ProjectName
        port = $Port
        containers = @($services | ForEach-Object { "$ProjectName-$_-1" })
        volumes = $testVolumes
        images = @($imageNames | ForEach-Object { "$ProjectName-$_" })
        backup_root = Join-Path (Join-Path ([IO.Path]::GetTempPath()) 'TelmanFit-E2E') $ProjectName
    }
}

function Get-IsolatedTestResourceNames {
    param([Parameter(Mandatory)][string] $ProjectName)
    $services = @('setup', 'database', 'app', 'worker', 'scheduler', 'web')
    [pscustomobject]@{ containers = @($services | ForEach-Object { "$ProjectName-$_-1" }) }
}

function Write-UpdateLog {
    param([Parameter(Mandatory)][string] $Message)

    $line = '{0} {1}' -f (Get-Date).ToUniversalTime().ToString('o'), $Message
    if ($script:LogPath) {
        Add-Content -LiteralPath $script:LogPath -Value $line -Encoding utf8
    }
    Write-Host $line
}

function ConvertTo-NativeArgument {
    param([Parameter(Mandatory)][AllowEmptyString()][string] $Value)

    # ProcessStartInfo.Arguments is the only argument API available on Windows PowerShell 5.1.
    # Quote according to the Windows C runtime rules, including embedded/trailing backslashes.
    $builder = New-Object System.Text.StringBuilder
    [void] $builder.Append('"')
    $backslashes = 0
    foreach ($character in $Value.ToCharArray()) {
        if ($character -eq [char] 92) {
            $backslashes++
            continue
        }
        if ($character -eq [char] 34) {
            if ($backslashes -gt 0) { [void] $builder.Append(('\' * (2 * $backslashes))) }
            [void] $builder.Append('\')
            [void] $builder.Append('"')
        } else {
            if ($backslashes -gt 0) { [void] $builder.Append(('\' * $backslashes)) }
            [void] $builder.Append($character)
        }
        $backslashes = 0
    }
    if ($backslashes -gt 0) { [void] $builder.Append(('\' * (2 * $backslashes))) }
    [void] $builder.Append('"')
    return $builder.ToString()
}

function ConvertFrom-NativeOutput {
    param([Parameter(Mandatory)][AllowEmptyString()][string] $Text)

    if (-not $Text.Length) { return }
    return $Text -split "`r`n|`n|`r"
}

function Invoke-Native {
    param(
        [Parameter(Mandatory)][string] $Command,
        [Parameter(Mandatory)][string[]] $Arguments,
        [switch] $AllowFailure
    )

    $application = Get-Command -Name $Command -CommandType Application -ErrorAction SilentlyContinue | Select-Object -First 1
    if (-not $application) { throw "Native command '$Command' is unavailable." }

    $startInfo = New-Object System.Diagnostics.ProcessStartInfo
    $startInfo.FileName = $application.Source
    $startInfo.Arguments = (@($Arguments | ForEach-Object { ConvertTo-NativeArgument -Value ([string] $_) }) -join ' ')
    $startInfo.UseShellExecute = $false
    $startInfo.CreateNoWindow = $true
    $startInfo.RedirectStandardOutput = $true
    $startInfo.RedirectStandardError = $true

    $process = New-Object System.Diagnostics.Process
    $process.StartInfo = $startInfo
    try {
        if (-not $process.Start()) { throw "Native command '$Command' did not start." }
        # Drain both pipes concurrently to avoid deadlocks when either stream is large.
        $stdoutTask = $process.StandardOutput.ReadToEndAsync()
        $stderrTask = $process.StandardError.ReadToEndAsync()
        $process.WaitForExit()
        $stdout = $stdoutTask.GetAwaiter().GetResult()
        $stderr = $stderrTask.GetAwaiter().GetResult()
        $exitCode = $process.ExitCode
    } catch {
        throw "Native command '$Command' could not be completed."
    } finally {
        $process.Dispose()
    }

    $stdoutLines = @(ConvertFrom-NativeOutput -Text $stdout)
    $stderrLines = @(ConvertFrom-NativeOutput -Text $stderr)
    if ($exitCode -ne 0 -and -not $AllowFailure) {
        # Do not copy command output into the coordinator log; CLI diagnostics may contain secrets.
        throw "Native command '$Command' failed with exit code $exitCode."
    }
    return [pscustomobject]@{
        ExitCode = $exitCode
        # Preserve existing consumers as stdout-only; diagnostics stay in StandardError.
        Output = $stdoutLines
        StandardOutput = $stdoutLines
        StandardError = $stderrLines
    }
}

function Invoke-Git {
    param([Parameter(Mandatory)][string[]] $Arguments, [switch] $AllowFailure)
    return Invoke-Native -Command 'git' -Arguments $Arguments -AllowFailure:$AllowFailure
}

function Invoke-Docker {
    param([Parameter(Mandatory)][string[]] $Arguments, [switch] $AllowFailure)
    return Invoke-Native -Command 'docker' -Arguments $Arguments -AllowFailure:$AllowFailure
}

function Get-OutputText {
    param([Parameter(Mandatory)] $Result)
    return (@($Result.Output | ForEach-Object { [string] $_ }) -join [Environment]::NewLine).Trim()
}

function Invoke-Compose {
    param([Parameter(Mandatory)][string[]] $Arguments, [switch] $AllowFailure)
    return Invoke-Docker -Arguments (@('compose') + $script:ComposeArguments + $Arguments) -AllowFailure:$AllowFailure
}

function Set-PrivateDirectoryAcl {
    param([Parameter(Mandatory)][string] $Path)

    New-Item -ItemType Directory -Path $Path -Force | Out-Null
    $sid = [System.Security.Principal.WindowsIdentity]::GetCurrent().User.Value
    $null = Invoke-Native -Command 'icacls' -Arguments @($Path, '/inheritance:r', '/grant:r', "*${sid}:(OI)(CI)F", '*S-1-5-18:(OI)(CI)F')
}

function Enter-UpdateLock {
    param([Parameter(Mandatory)][string] $LockPath)

    New-Item -ItemType Directory -Path (Split-Path -Parent $LockPath) -Force | Out-Null
    try {
        $stream = [System.IO.File]::Open($LockPath, [System.IO.FileMode]::OpenOrCreate, [System.IO.FileAccess]::ReadWrite, [System.IO.FileShare]::None)
    } catch {
        throw "Інший local-update процес уже працює або lock недоступний: $LockPath"
    }
    $stream.SetLength(0)
    $bytes = [Text.Encoding]::UTF8.GetBytes("pid=$PID`nstarted_utc=$((Get-Date).ToUniversalTime().ToString('o'))`n")
    $stream.Write($bytes, 0, $bytes.Length)
    $stream.Flush()
    return $stream
}

function Test-ExpectedRemote {
    param([Parameter(Mandatory)][string] $Remote)

    return $Remote -match '^(https://github\.com/ValtonUA/telmanfit(?:\.git)?|git@github\.com:ValtonUA/telmanfit\.git)$'
}

function Assert-SafeGitTree {
    param([Parameter(Mandatory)][string] $Root)

    $status = Invoke-Git -Arguments @('-C', $Root, 'status', '--porcelain=v1', '--untracked-files=all')
    foreach ($lineObject in $status.Output) {
        $line = [string] $lineObject
        if ($line -eq '?? compose.r2-runtime.yaml') {
            continue
        }
        if ($line.Trim()) {
            throw "Робоче дерево має локальні зміни; нічого не буде перезаписано: $line"
        }
    }
}

function Get-Allowlist {
    param([Parameter(Mandatory)][string] $Root)

    $path = Join-Path $Root 'scripts/local-migration-allowlist.json'
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) {
        throw 'Migration allowlist відсутній.'
    }
    $config = Get-Content -LiteralPath $path -Raw | ConvertFrom-Json
    if ($config.version -ne 1 -or $config.postgres_major -ne 18 -or @($config.migrations).Count -eq 0) {
        throw 'Migration allowlist має невідому версію або порожній перелік.'
    }
    $names = @{}
    $orders = @{}
    foreach ($item in $config.migrations) {
        if ($item.name -notmatch '^\d{4}_\d{2}_\d{2}_\d{6}_[a-z0-9_]+$' -or $item.sha256 -notmatch '^[A-Fa-f0-9]{64}$') {
            throw 'Migration allowlist містить неправильну назву або SHA256.'
        }
        if ($names.ContainsKey($item.name) -or $orders.ContainsKey([int] $item.order)) {
            throw 'Migration allowlist має повтор назви або порядку.'
        }
        $names[$item.name] = $item
        $orders[[int] $item.order] = $true
    }
    return $config
}

function Get-PendingPlan {
    param(
        [Parameter(Mandatory)] $Snapshot,
        [Parameter(Mandatory)] $Allowlist,
        [Parameter(Mandatory)][string] $Root
    )

    $migrationByName = @{}
    foreach ($migration in $Allowlist.migrations) { $migrationByName[$migration.name] = $migration }
    $plan = @()
    foreach ($name in @($Snapshot.migrations.pending)) {
        if (-not $migrationByName.ContainsKey($name)) {
            throw "Міграція '$name' не має явного дозволу; upgrade зупинено до зупинки сервісів."
        }
        $migration = $migrationByName[$name]
        $file = Join-Path $Root "database/migrations/$name.php"
        if (-not (Test-Path -LiteralPath $file -PathType Leaf)) {
            throw "Файл allowlisted міграції '$name' відсутній."
        }
        $actualHash = (Get-FileHash -LiteralPath $file -Algorithm SHA256).Hash
        if ($actualHash -ne $migration.sha256) {
            throw "SHA256 міграції '$name' не збігається з allowlist."
        }
        foreach ($source in @($migration.sources)) {
            if ($source.path -notmatch '^database/(?:sql|migrations)/[A-Za-z0-9_./-]+$' -or $source.path.Contains('..') -or $source.sha256 -notmatch '^[A-Fa-f0-9]{64}$') {
                throw "Allowlist для '$name' містить некоректний dependency hash."
            }
            $sourceFile = Join-Path $Root ($source.path -replace '/', [IO.Path]::DirectorySeparatorChar)
            if (-not (Test-Path -LiteralPath $sourceFile -PathType Leaf) -or (Get-FileHash -LiteralPath $sourceFile -Algorithm SHA256).Hash -cne $source.sha256) {
                throw "SHA256 залежності '$($source.path)' для '$name' не збігається з allowlist."
            }
        }
        $plan += $migration
    }

    $plan = @($plan | Sort-Object { [int] $_.order })
    $pendingNames = @($Snapshot.migrations.pending)
    $plannedNames = @($plan | ForEach-Object { $_.name })
    if (($pendingNames -join "`n") -cne ($plannedNames -join "`n")) {
        throw 'Pending-міграції не є точним впорядкованим піднабором allowlist.'
    }
    return ,$plan
}

function Assert-MigrationPreconditions {
    param([Parameter(Mandatory)] $Snapshot, [Parameter(Mandatory)] $Migration, [string[]] $PlannedTables = @())

    $tables = @($Snapshot.schema.tables)
    foreach ($name in @($Migration.preconditions.required_tables)) {
        if ($name -notin $tables -and $name -notin $PlannedTables) { throw "Передумова міграції $($Migration.name) відсутня: таблиця $name." }
    }
    foreach ($name in @($Migration.preconditions.absent_tables)) {
        if ($name -in $tables) { throw "Передумова міграції $($Migration.name) не виконана: таблиця $name вже існує." }
    }
}

function Assert-MigrationPostconditions {
    param([Parameter(Mandatory)] $Snapshot, [Parameter(Mandatory)] $Migration)

    foreach ($kind in @('required_tables', 'required_functions', 'required_triggers', 'required_views')) {
        $postcondition = $Migration.postconditions.PSObject.Properties[$kind]
        $required = @()
        if ($postcondition) { $required = @($postcondition.Value) }
        if (-not $required.Count) { continue }
        $actual = switch ($kind) {
            'required_tables' { @($Snapshot.schema.tables) }
            'required_functions' { @($Snapshot.schema.functions) }
            'required_triggers' { @($Snapshot.schema.triggers) }
            'required_views' { @($Snapshot.schema.views) }
        }
        foreach ($name in $required) {
            if ($name -notin $actual) { throw "Postcondition міграції $($Migration.name) не виконана: $kind '$name' відсутня." }
        }
    }
}

function Assert-SnapshotUnchanged {
    param([Parameter(Mandatory)] $Before, [Parameter(Mandatory)] $After)

    if ($Before.restore_fence -ne 'PASS' -or $After.restore_fence -ne 'PASS') {
        throw 'RestoreFence не пройшов до або після міграцій.'
    }
    if ($Before.catalog.rows -ne $After.catalog.rows -or $Before.catalog.urls -ne $After.catalog.urls -or $Before.catalog.fingerprint -cne $After.catalog.fingerprint) {
        throw 'Змінився каталог або його image_url під час міграцій.'
    }
    foreach ($table in $Before.tables.PSObject.Properties) {
        if ($table.Name -eq 'migrations') { continue }
        $afterTable = $After.tables.PSObject.Properties[$table.Name]
        if (-not $afterTable -or $table.Value.rows -ne $afterTable.Value.rows -or $table.Value.fingerprint -cne $afterTable.Value.fingerprint) {
            throw "Змінилися рядки наявної таблиці '$($table.Name)' під час міграцій."
        }
    }
}

function Assert-ComposeConfig {
    param([Parameter(Mandatory)] $Config)

    if ($Config.name -cne $script:ProjectName) { throw "Compose project name має бути '$script:ProjectName'." }
    $expectedServices = @('setup', 'database', 'app', 'worker', 'scheduler', 'web')
    $serviceNames = @($Config.services.PSObject.Properties.Name | Sort-Object)
    if (($serviceNames -join ',') -cne (($expectedServices | Sort-Object) -join ',')) {
        throw 'Compose service inventory не збігається з локальним TelmanFit.'
    }
    $expectedVolumes = @('database', 'database-secrets', 'epoch', 'local-state', 'storage')
    $volumeNames = @($Config.volumes.PSObject.Properties.Name | Sort-Object)
    if (($volumeNames -join ',') -cne (($expectedVolumes | Sort-Object) -join ',')) {
        throw 'Compose volume inventory не збігається з локальним TelmanFit.'
    }
    $databaseService = $Config.services.PSObject.Properties['database'].Value
    $databasePorts = $databaseService.PSObject.Properties['ports']
    if ($databasePorts -and @($databasePorts.Value).Count -ne 0) { throw 'PostgreSQL не повинен мати опублікованого порту.' }
    $webService = $Config.services.PSObject.Properties['web'].Value
    $webPortsProperty = $webService.PSObject.Properties['ports']
    if (-not $webPortsProperty) { throw 'Web service має містити один опублікований loopback port.' }
    $webPorts = @($webPortsProperty.Value)
    if ($webPorts.Count -ne 1 -or [string]$webPorts[0].published -ne $script:PublishedPort -or $webPorts[0].host_ip -ne '127.0.0.1') {
        throw 'Web port не відповідає loopback-only конфігурації.'
    }
    if ($script:TestMode) {
        if ($Config.networks.local.internal -ne $true) { throw 'Ізольована Compose network має бути internal.' }
        foreach ($service in @('setup', 'app', 'worker', 'scheduler', 'web', 'database')) {
            $expectedImageService = if ($service -in @('setup', 'app', 'worker', 'scheduler')) { 'app' } else { $service }
            if ($Config.services.$service.image -cne (Get-ImageName -Service $expectedImageService)) { throw "Ізольований образ сервісу $service має неунікальне ім’я." }
        }
    }
    foreach ($service in @('app', 'worker', 'scheduler')) {
        $sources = @($Config.services.$service.volumes | ForEach-Object { $_.source } | Sort-Object)
        if (($sources -join ',') -cne 'epoch,local-state,storage') { throw "Volumes сервісу $service не збігаються з очікуваними." }
    }
    $databaseSources = @($Config.services.database.volumes | ForEach-Object { $_.source } | Sort-Object)
    if (($databaseSources -join ',') -cne 'database,database-secrets') { throw 'Database volumes не збігаються з очікуваними.' }
}

function Get-DatabaseSnapshot {
    $result = Invoke-Compose -Arguments @('run', '--rm', '--no-deps', '--user', 'www-data', '--entrypoint', 'php', 'app', '-d', 'auto_prepend_file=/var/www/html/docker/local/load-env.php', 'docker/local/upgrade-check.php')
    $json = Get-OutputText $result
    $jsonLine = @($json -split '\r?\n' | Where-Object { $_.TrimStart().StartsWith('{') } | Select-Object -Last 1)
    if (-not $jsonLine.Count) { throw 'Read-only upgrade probe не повернув JSON результату.' }
    try { return ($jsonLine[0] | ConvertFrom-Json) } catch { throw 'Read-only upgrade probe повернув некоректний результат.' }
}

function Test-VolumeDetached {
    param([Parameter(Mandatory)][string] $Volume)
    $running = Invoke-Docker -Arguments @('ps', '-q', '--filter', "volume=$Volume")
    if ((Get-OutputText $running)) { throw "Volume '$Volume' усе ще приєднаний до running container." }
}

function Invoke-TarArchive {
    param(
        [Parameter(Mandatory)][string] $DatabaseImage,
        [Parameter(Mandatory)][string] $Volume,
        [Parameter(Mandatory)][string] $BackupDirectory,
        [Parameter(Mandatory)][string] $Archive,
        [switch] $Compare
    )

    Test-VolumeDetached -Volume $Volume
    $archivePath = Join-Path $BackupDirectory $Archive
    if ($Compare) {
        $arguments = @('run', '--rm', '--network', 'none', '--read-only', '--user', '0', '--entrypoint', 'tar', '--mount', "type=volume,source=$Volume,target=/source,readonly", '--mount', "type=bind,source=$BackupDirectory,target=/backup,readonly", $DatabaseImage, '--numeric-owner', '-dpf', "/backup/$Archive", '-C', '/source')
    } else {
        if (Test-Path -LiteralPath $archivePath) { throw "Відмова перезаписати існуючий архів $archivePath" }
        $arguments = @('run', '--rm', '--network', 'none', '--read-only', '--user', '0', '--entrypoint', 'tar', '--mount', "type=volume,source=$Volume,target=/source,readonly", '--mount', "type=bind,source=$BackupDirectory,target=/backup", $DatabaseImage, '--numeric-owner', '-cpf', "/backup/$Archive", '-C', '/source', '.')
    }
    $null = Invoke-Docker -Arguments $arguments
    if (-not $Compare -and -not (Test-Path -LiteralPath $archivePath -PathType Leaf)) { throw "Архів '$Archive' після tar відсутній." }
}

function Get-ArchiveInventory {
    param([Parameter(Mandatory)][string] $DatabaseImage, [Parameter(Mandatory)][string] $BackupDirectory, [Parameter(Mandatory)][string] $Archive)

    $result = Invoke-Docker -Arguments @('run', '--rm', '--network', 'none', '--read-only', '--entrypoint', 'tar', '--mount', "type=bind,source=$BackupDirectory,target=/backup,readonly", $DatabaseImage, '-tf', "/backup/$Archive")
    $entries = @($result.Output | ForEach-Object { [string] $_ } | Where-Object { $_.Trim() })
    if (-not $entries.Count) { throw "Архів '$Archive' порожній." }
    return $entries.Count
}

function Get-ProjectContainers {
    $result = Invoke-Docker -Arguments @('ps', '-aq', '--filter', "label=com.docker.compose.project=$script:ProjectName")
    return @($result.Output | ForEach-Object { ([string] $_).Trim() } | Where-Object { $_ })
}

function Assert-LocalProject {
    param([Parameter(Mandatory)][string] $Root)

    $context = Get-OutputText (Invoke-Docker -Arguments @('context', 'show'))
    if ($context -ne 'desktop-linux') { throw "Docker context має бути desktop-linux, отримано '$context'." }
    $osType = Get-OutputText (Invoke-Docker -Arguments @('info', '--format', '{{.OSType}}'))
    if ($osType -ne 'linux') { throw 'Потрібен локальний Docker Desktop з Linux containers.' }
    $containers = Get-ProjectContainers
    if (-not $containers.Count) { throw "Не знайдено попередньо створених контейнерів '$script:ProjectName'." }

    $databaseContainers = @()
    $expectedMounts = @{
        'setup' = @((Get-VolumeName database-secrets), (Get-VolumeName epoch), (Get-VolumeName local-state))
        'database' = @((Get-VolumeName database), (Get-VolumeName database-secrets))
        'app' = @((Get-VolumeName epoch), (Get-VolumeName local-state), (Get-VolumeName storage))
        'worker' = @((Get-VolumeName epoch), (Get-VolumeName local-state), (Get-VolumeName storage))
        'scheduler' = @((Get-VolumeName epoch), (Get-VolumeName local-state), (Get-VolumeName storage))
        'web' = @()
    }
    foreach ($id in $containers) {
        $inspect = (Get-OutputText (Invoke-Docker -Arguments @('inspect', $id)) | ConvertFrom-Json)[0]
        $labels = $inspect.Config.Labels
        if ($labels.'com.docker.compose.project' -cne $script:ProjectName) { throw 'Знайдено контейнер з неочікуваним Compose project label.' }
        if ([IO.Path]::GetFullPath($labels.'com.docker.compose.project.working_dir').TrimEnd('\') -ine $Root.TrimEnd('\')) {
            throw 'Compose container належить іншому робочому каталогу.'
        }
        $service = $labels.'com.docker.compose.service'
        if (-not $expectedMounts.ContainsKey($service)) { throw "Невідомий Compose service container: '$service'." }
        $mounts = @($inspect.Mounts | Where-Object { $_.Type -eq 'volume' } | ForEach-Object { $_.Name } | Sort-Object)
        $serviceMounts = @($expectedMounts[$service] | Sort-Object)
        if (($mounts -join ',') -cne ($serviceMounts -join ',')) { throw "Container '$service' має несподівані Docker volumes." }
        if ($service -eq 'database') { $databaseContainers += $inspect }
    }
    if ($databaseContainers.Count -ne 1) { throw "Очікується рівно один '$script:ProjectName' database container." }
    $database = $databaseContainers[0]
    if ($database.State.Running -ne $true -or $database.State.Health.Status -ne 'healthy') { throw 'Робочий PostgreSQL має бути healthy до оновлення.' }
    $mountVolumes = @($database.Mounts | Where-Object { $_.Type -eq 'volume' } | ForEach-Object { $_.Name } | Sort-Object)
    if (($mountVolumes -join ',') -cne "$(Get-VolumeName database),$(Get-VolumeName database-secrets)") {
        throw 'Database container не використовує очікувані TelmanFit volumes.'
    }
    $configFiles = @($database.Config.Labels.'com.docker.compose.project.config_files' -split ',')
    $expectedConfigCount = if ($script:TestMode) { 2 } else { 1 }
    if ($configFiles.Count -ne $expectedConfigCount -or [IO.Path]::GetFileName($configFiles[0]) -cne 'compose.yaml' -or ($script:TestMode -and [IO.Path]::GetFullPath($configFiles[1]) -ine [IO.Path]::GetFullPath($TestComposeOverride))) {
        throw 'Активний Compose використовує інші config files. Зупинено, щоб не змінити overlay або інший проєкт.'
    }
    return $database
}

function Assert-TestProjectDisjoint {
    if (-not $script:TestMode) { return }
    $workingProject = 'telmanfit-local'
    if ($script:ProjectName -ceq $workingProject -or $script:ProjectName -notmatch '^telmanfit-e2e-[a-f0-9]{12}$') {
        throw 'Ізольований Compose project name збігається з робочим або має недозволений формат.'
    }
    $volumeKinds = @('database', 'database-secrets', 'local-state', 'epoch', 'storage')
    $workingVolumes = @($volumeKinds | ForEach-Object { "${workingProject}_$_" })
    $testVolumes = @($volumeKinds | ForEach-Object { Get-VolumeName $_ })
    if (@($testVolumes | Where-Object { $_ -in $workingVolumes }).Count -gt 0) { throw 'Test volume name збігається з робочим TelmanFit volume.' }

    $volumeNames = @(Invoke-Docker -Arguments @('volume', 'ls', '-q')).Output | ForEach-Object { ([string]$_).Trim() } | Where-Object { $_ }
    foreach ($volume in $testVolumes) {
        if ($volume -notin $volumeNames) { throw "Ізольований volume '$volume' не створено runner-ом." }
        $details = (Get-OutputText (Invoke-Docker -Arguments @('volume', 'inspect', $volume)) | ConvertFrom-Json)[0]
        if ($details.Labels.'com.docker.compose.project' -cne $script:ProjectName) { throw "Volume '$volume' не має очікуваного Compose owner label." }
    }
    foreach ($volume in $workingVolumes) {
        if ($volume -in $testVolumes) { throw "Небезпечне зіставлення із робочим volume '$volume'." }
        $details = (Get-OutputText (Invoke-Docker -Arguments @('volume', 'inspect', $volume)) | ConvertFrom-Json)[0]
        if ($details.Labels.'com.docker.compose.project' -cne $workingProject) { throw "Робочий volume '$volume' не має очікуваного owner label." }
    }

    $ids = @(Invoke-Docker -Arguments @('ps', '-aq')).Output | ForEach-Object { ([string]$_).Trim() } | Where-Object { $_ }
    $expectedNames = @(Get-IsolatedTestResourceNames -ProjectName $script:ProjectName).containers
    foreach ($id in $ids) {
        $details = (Get-OutputText (Invoke-Docker -Arguments @('inspect', $id)) | ConvertFrom-Json)[0]
        $labels = $details.Config.Labels
        $containerName = $details.Name.TrimStart('/')
        if ($containerName -like "$($script:ProjectName)-*" -and $labels.'com.docker.compose.project' -cne $script:ProjectName) {
            throw "Test container name collision with a resource owned by another project: $containerName."
        }
        if ($labels.'com.docker.compose.project' -ceq $script:ProjectName) {
            if ($containerName -notlike "$($script:ProjectName)-*" -or ($containerName -notin $expectedNames -and $containerName -notmatch "^$([regex]::Escape($script:ProjectName))-app-run-")) { throw 'Test container має назву поза унікальним project namespace.' }
            foreach ($mount in @($details.Mounts | Where-Object { $_.Type -eq 'volume' })) {
                if ($mount.Name -in $workingVolumes) { throw "Ізольований container використовує робочий volume '$($mount.Name)'." }
            }
        }
    }
    foreach ($service in @('app', 'web', 'database')) {
        $imageName = Get-ImageName $service
        $imageResult = Invoke-Docker -Arguments @('image', 'inspect', $imageName) -AllowFailure
        if ($imageResult.ExitCode -eq 0) {
            $image = (Get-OutputText $imageResult | ConvertFrom-Json)[0]
            if ($image.Config.Labels.'telmanfit.e2e.project' -cne $script:ProjectName) { throw "Test image tag collision with a resource owned by another project: $imageName." }
        }
    }
    $networkName = "$($script:ProjectName)_local"
    $networkResult = Invoke-Docker -Arguments @('network', 'inspect', $networkName) -AllowFailure
    if ($networkResult.ExitCode -eq 0) {
        $network = (Get-OutputText $networkResult | ConvertFrom-Json)[0]
        if ($network.Labels.'com.docker.compose.project' -cne $script:ProjectName) { throw "Test network name collision with a resource owned by another project: $networkName." }
    }
    $portUsersResult = Invoke-Docker -Arguments @('ps', '-q', '--filter', "publish=$script:PublishedPort")
    $portUsers = @($portUsersResult.Output | ForEach-Object { ([string]$_).Trim() } | Where-Object { $_ })
    foreach ($id in $portUsers) {
        $labels = (Get-OutputText (Invoke-Docker -Arguments @('inspect', $id)) | ConvertFrom-Json)[0].Config.Labels
        if ($labels.'com.docker.compose.project' -cne $script:ProjectName) { throw "Test host port $script:PublishedPort зайнятий стороннім контейнером." }
    }
}

function Get-SafeComposeConfig {
    param([Parameter(Mandatory)][string] $Root)

    $result = Invoke-Compose -Arguments @('config', '--format', 'json')
    $text = Get-OutputText $result
    try { $config = $text | ConvertFrom-Json } catch { throw 'docker compose config повернув некоректну JSON конфігурацію.' }
    Assert-ComposeConfig -Config $config
    return $config
}

function Set-Phase {
    param([Parameter(Mandatory)][string] $Name, [Parameter(Mandatory)][string] $State)
    $script:Phase = $Name
    Write-UpdateLog "phase=$Name state=$State"
    if ($script:JournalPath) {
        $journal = [ordered]@{
            run_id = $script:RunId
            phase = $Name
            state = $State
            updated_utc = (Get-Date).ToUniversalTime().ToString('o')
            repository_sha = $script:RepositoryHead
            backup_directory = $script:BackupDirectory
        }
        $journal | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath $script:JournalPath -Encoding utf8
    }
}

function Assert-NoUnresolvedJournals {
    param([Parameter(Mandatory)][string] $Directory, [string] $AcknowledgedRunId)

    if (-not (Test-Path -LiteralPath $Directory -PathType Container)) { return }
    foreach ($path in Get-ChildItem -LiteralPath $Directory -Filter '*.json' -File) {
        if ($path.Name.EndsWith('.acknowledged.json')) { continue }
        try { $journal = Get-Content -LiteralPath $path.FullName -Raw | ConvertFrom-Json } catch { throw "Журнал upgrade некоректний; потрібна ручна перевірка: $($path.FullName)" }
        if ($journal.phase -eq 'complete' -and $journal.state -eq 'PASS') { continue }
        $acknowledgementPath = "$($path.FullName).acknowledged.json"
        if (Test-Path -LiteralPath $acknowledgementPath -PathType Leaf) {
            try { $acknowledgement = Get-Content -LiteralPath $acknowledgementPath -Raw | ConvertFrom-Json } catch { throw "Журнал підтвердження некоректний: $acknowledgementPath" }
            if ($acknowledgement.run_id -cne $journal.run_id) { throw "Журнал підтвердження не відповідає run_id: $acknowledgementPath" }
            continue
        }
        if ($AcknowledgedRunId -and $journal.run_id -ceq $AcknowledgedRunId) {
            [ordered]@{ run_id = $journal.run_id; acknowledged_utc = (Get-Date).ToUniversalTime().ToString('o') } | ConvertTo-Json | Set-Content -LiteralPath $acknowledgementPath -Encoding utf8
            Write-UpdateLog "prior_journal_acknowledged=$($journal.run_id) phase=$($journal.phase) state=$($journal.state)"
            continue
        }
        throw "Є незавершена/невдала операція upgrade ($($journal.run_id), phase=$($journal.phase), state=$($journal.state)). Перегляньте журнал '$($path.FullName)' та після ручної перевірки повторіть з -AcknowledgeJournal '$($journal.run_id)'. Автовідновлення не виконується."
    }
}

function Assert-BackupMetadata {
    param([Parameter(Mandatory)][string] $Path)

    $hash = (Get-FileHash -LiteralPath $Path -Algorithm SHA256).Hash
    $bytes = (Get-Item -LiteralPath $Path).Length
    if ($bytes -le 0) { throw "Backup file '$Path' is empty." }
    return [pscustomobject]@{ bytes = $bytes; sha256 = $hash }
}

function Start-LocalDatabase {
    $null = Invoke-Compose -Arguments @('up', '-d', '--no-deps', 'database')
    for ($i = 0; $i -lt 90; $i++) {
        $status = Get-OutputText (Invoke-Docker -Arguments @('inspect', '--format', '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}', (Get-ContainerName database)))
        if ($status -eq 'healthy') { return }
        Start-Sleep -Seconds 2
    }
    throw 'PostgreSQL не повернувся у healthy стан за 180 секунд.'
}

function Assert-WritersStopped {
    $running = Get-ProjectContainers
    foreach ($id in $running) {
        $inspect = (Get-OutputText (Invoke-Docker -Arguments @('inspect', $id)) | ConvertFrom-Json)[0]
        if (-not $inspect.State.Running) { continue }
        $service = $inspect.Config.Labels.'com.docker.compose.service'
        if ($service -in @('app', 'worker', 'scheduler', 'web', 'setup')) {
            throw "Writer-сервіс '$service' усе ще працює після docker compose stop."
        }
    }
    $query = 'SELECT count(*) FROM pg_stat_activity WHERE datname=current_database() AND pid<>pg_backend_pid()'
    $sessions = Get-OutputText (Invoke-Compose -Arguments @('exec', '-T', 'database', 'psql', '-X', '-U', 'postgres', '-d', $script:DatabaseName, '-Atc', $query))
    if ($sessions -ne '0') { throw "У БД залишились $sessions application sessions після зупинки writer-сервісів." }
}

function Invoke-BackupRestoreRehearsal {
    param(
        [Parameter(Mandatory)][string] $BackupDirectory,
        [Parameter(Mandatory)][string] $DatabaseImage,
        [Parameter(Mandatory)] $BeforeSnapshot,
        [Parameter(Mandatory)][string] $CustomDump
    )

    $suffix = [guid]::NewGuid().ToString('N').Substring(0, 12)
    $project = "telmanfit-restore-$suffix"
    $network = "${project}_isolated"
    $container = "${project}-database"
    $volumeNames = @('database', 'database-secrets', 'local-state', 'epoch', 'storage')
    $createdVolumes = @()
    $networkCreated = $false
    $containerCreated = $false
    try {
        $null = Invoke-Docker -Arguments @('network', 'create', '--internal', '--label', "telmanfit.local-update=$script:RunId", $network)
        $networkCreated = $true
        foreach ($volume in $volumeNames) {
            $name = "${project}_$volume"
            $null = Invoke-Docker -Arguments @('volume', 'create', '--label', "telmanfit.local-update=$script:RunId", $name)
            $createdVolumes += $name
            $archive = "$volume.tar"
            $null = Invoke-Docker -Arguments @('run', '--rm', '--network', 'none', '--read-only', '--user', '0', '--entrypoint', 'tar', '--mount', "type=volume,source=$name,target=/restore", '--mount', "type=bind,source=$BackupDirectory,target=/backup,readonly", $DatabaseImage, '--numeric-owner', '-xpf', "/backup/$archive", '-C', '/restore')
            $null = Invoke-Docker -Arguments @('run', '--rm', '--network', 'none', '--read-only', '--user', '0', '--entrypoint', 'tar', '--mount', "type=volume,source=$name,target=/restore,readonly", '--mount', "type=bind,source=$BackupDirectory,target=/backup,readonly", $DatabaseImage, '--numeric-owner', '-dpf', "/backup/$archive", '-C', '/restore')
        }
        $null = Invoke-Docker -Arguments @('run', '-d', '--name', $container, '--label', "telmanfit.local-update=$script:RunId", '--network', $network, '--network-alias', 'database', '--mount', "type=volume,source=${project}_database,target=/var/lib/postgresql", '--mount', "type=volume,source=${project}_database-secrets,target=/run/database-secrets,readonly", '-e', 'POSTGRES_PASSWORD_FILE=/run/database-secrets/password', $DatabaseImage)
        $containerCreated = $true
        $ready = $false
        for ($i = 0; $i -lt 60; $i++) {
            $probe = Invoke-Docker -Arguments @('exec', $container, 'pg_isready', '-U', 'postgres', '-d', $script:DatabaseName) -AllowFailure
            if ($probe.ExitCode -eq 0) { $ready = $true; break }
            Start-Sleep -Seconds 2
        }
        if (-not $ready) { throw 'Відновлений із volume backup PostgreSQL не став готовим.' }

        $restoreSnapshot = Invoke-Docker -Arguments @('run', '--rm', '--network', $network, '--mount', "type=volume,source=${project}_local-state,target=/tmp/telmanfit-auth4-compose,readonly", '--mount', "type=volume,source=${project}_epoch,target=/run/telmanfit-epoch,readonly", '--mount', "type=volume,source=${project}_storage,target=/var/www/html/storage,readonly", '--entrypoint', 'php', $script:AppImage, '-d', 'auto_prepend_file=/var/www/html/docker/local/load-env.php', 'docker/local/upgrade-check.php')
        $restored = (Get-OutputText $restoreSnapshot) | ConvertFrom-Json
        Assert-SnapshotUnchanged -Before $BeforeSnapshot -After $restored

        $dumpName = 'upgrade-recoverability-check.dump'
        $null = Invoke-Docker -Arguments @('cp', $CustomDump, "${container}:/tmp/$dumpName")
        $toc = Invoke-Docker -Arguments @('exec', $container, 'pg_restore', '--list', "/tmp/$dumpName")
        if (@($toc.Output).Count -lt 10) { throw 'pg_restore не прочитав TOC custom-format backup.' }
        $restoreDatabase = 'telmanfit_dump_restore_check'
        $null = Invoke-Docker -Arguments @('exec', $container, 'createdb', '-U', 'postgres', '--template=template0', '--encoding=UTF8', $restoreDatabase)
        $null = Invoke-Docker -Arguments @('exec', $container, 'pg_restore', '--exit-on-error', '--no-owner', '--no-privileges', '-U', 'postgres', '-d', $restoreDatabase, "/tmp/$dumpName")
        $sql = "SELECT (SELECT count(*) FILTER (WHERE image_url IS NOT NULL)||'/'||count(*) FROM (SELECT image_url FROM ingredients UNION ALL SELECT image_url FROM kitchen_equipment UNION ALL SELECT image_url FROM recipes) s)||'/'||(SELECT count(*) FROM migrations)"
        $dumpResult = Get-OutputText (Invoke-Docker -Arguments @('exec', $container, 'psql', '-X', '-U', 'postgres', '-d', $restoreDatabase, '-Atc', $sql))
        $expectedMigrationCount = @($BeforeSnapshot.migrations.ran).Count
        if ($dumpResult -cne "85/108/$expectedMigrationCount") {
            throw "Custom dump restore не підтвердив catalog URL або migration history (counts=$dumpResult)."
        }
        Write-UpdateLog 'backup_restore_rehearsal=PASS physical_volumes=5 custom_dump=PASS catalog_urls=85'
    } finally {
        if ($containerCreated) {
            $inspectResult = Invoke-Docker -Arguments @('inspect', $container) -AllowFailure
            if ($inspectResult.ExitCode -eq 0) {
                $details = (Get-OutputText $inspectResult | ConvertFrom-Json)[0]
                if ($details.Config.Labels.'telmanfit.local-update' -ne $script:RunId) { throw 'Відмова видалити не-позначений rehearsal container.' }
                $null = Invoke-Docker -Arguments @('stop', '--time', '30', $container) -AllowFailure
                $null = Invoke-Docker -Arguments @('rm', $container)
            }
        }
        foreach ($name in $createdVolumes) {
            $inspectResult = Invoke-Docker -Arguments @('volume', 'inspect', $name) -AllowFailure
            if ($inspectResult.ExitCode -eq 0) {
                $details = (Get-OutputText $inspectResult | ConvertFrom-Json)[0]
                if ($details.Labels.'telmanfit.local-update' -ne $script:RunId) { throw 'Відмова видалити не-позначений rehearsal volume.' }
                $attached = Invoke-Docker -Arguments @('ps', '-aq', '--filter', "volume=$name")
                if ((Get-OutputText $attached)) { throw "Rehearsal volume $name усе ще використовується." }
                $null = Invoke-Docker -Arguments @('volume', 'rm', $name)
            }
        }
        if ($networkCreated) {
            $inspectResult = Invoke-Docker -Arguments @('network', 'inspect', $network) -AllowFailure
            if ($inspectResult.ExitCode -eq 0) {
                $details = (Get-OutputText $inspectResult | ConvertFrom-Json)[0]
                if ($details.Labels.'telmanfit.local-update' -ne $script:RunId) { throw 'Відмова видалити не-позначену rehearsal network.' }
                $null = Invoke-Docker -Arguments @('network', 'rm', $network)
            }
        }
    }
}

function Invoke-LocalUpdate {
    Initialize-IsolationMode
    $root = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..')).TrimEnd('\')
    $actualRoot = Get-OutputText (Invoke-Git -Arguments @('-C', $root, 'rev-parse', '--show-toplevel'))
    if ([IO.Path]::GetFullPath($actualRoot).TrimEnd('\') -ine $root) { throw 'Скрипт має запускатися з кореня репозиторію.' }

    if (-not $env:LOCALAPPDATA) { throw 'LOCALAPPDATA не визначено; coordinator потребує локального приватного сховища backup/log.' }
    $stateRoot = if ($script:TestMode) { Join-Path (Join-Path $env:TEMP 'TelmanFit-E2E') $script:ProjectName } else { Join-Path $env:LOCALAPPDATA 'TelmanFit' }
    Set-PrivateDirectoryAcl -Path $stateRoot
    if ($script:TestMode) {
        $ownerMarker = Join-Path $stateRoot '.isolated-project'
        if (Test-Path -LiteralPath $ownerMarker -PathType Leaf) {
            if ((Get-Content -LiteralPath $ownerMarker -Raw).Trim() -cne $script:ProjectName) { throw 'Ізольований backup/log каталог має інший project marker.' }
        } elseif (@(Get-ChildItem -LiteralPath $stateRoot -Force).Count -gt 0) {
            throw 'Ізольований backup/log каталог уже містить немарковані дані.'
        } else {
            Set-Content -LiteralPath $ownerMarker -Value $script:ProjectName -NoNewline -Encoding ascii
        }
    }
    $lockStream = Enter-UpdateLock -LockPath (Join-Path $stateRoot 'local-update.lock')
    $script:RunId = '{0}-{1}' -f (Get-Date).ToUniversalTime().ToString('yyyyMMddTHHmmssZ'), ([guid]::NewGuid().ToString('N').Substring(0, 8))
    $script:LogPath = Join-Path (Join-Path $stateRoot 'updates') "$script:RunId.log"
    $script:JournalPath = Join-Path (Join-Path $stateRoot 'updates') "$script:RunId.json"
    Set-PrivateDirectoryAcl -Path (Split-Path -Parent $script:LogPath)
    $script:Phase = 'preflight'
    $script:BackupDirectory = $null
    $script:RepositoryHead = $null

    try {
        try {
            Assert-NoUnresolvedJournals -Directory (Split-Path -Parent $script:JournalPath) -AcknowledgedRunId $AcknowledgeJournal
        } catch {
            $script:Phase = 'journal_gate_blocked'
            throw
        }
        Write-UpdateLog "local update started; target=$script:ProjectName; isolated_test=$($script:TestMode.ToString().ToLowerInvariant()); no production credentials are read"
        $remote = Get-OutputText (Invoke-Git -Arguments @('-C', $root, 'remote', 'get-url', 'origin'))
        if (-not (Test-ExpectedRemote -Remote $remote)) { throw 'Git origin не відповідає ValtonUA/telmanfit.' }
        Assert-SafeGitTree -Root $root

        if ($NoGitSync) {
            $head = Get-OutputText (Invoke-Git -Arguments @('-C', $root, 'rev-parse', 'HEAD'))
            if ($ExpectedHead -and $head -cne $ExpectedHead) { throw 'HEAD змінився після синхронізації Git.' }
        } else {
            $branch = Get-OutputText (Invoke-Git -Arguments @('-C', $root, 'branch', '--show-current'))
            if ($branch -ne 'master') { $null = Invoke-Git -Arguments @('-C', $root, 'switch', 'master') }
            $scriptHashBefore = (Get-FileHash -LiteralPath $PSCommandPath -Algorithm SHA256).Hash
            $null = Invoke-Git -Arguments @('-C', $root, 'fetch', 'origin', 'master')
            $null = Invoke-Git -Arguments @('-C', $root, 'pull', '--ff-only', 'origin', 'master')
            Assert-SafeGitTree -Root $root
            $script:RepositoryHead = Get-OutputText (Invoke-Git -Arguments @('-C', $root, 'rev-parse', 'HEAD'))
            $scriptHashAfter = (Get-FileHash -LiteralPath $PSCommandPath -Algorithm SHA256).Hash
            if ($scriptHashBefore -cne $scriptHashAfter) {
                throw 'Сам coordinator оновився під час Git pull. Повторіть команду, щоб виконати нову перевірену версію.'
            }
        }
        if (-not $script:RepositoryHead) { $script:RepositoryHead = Get-OutputText (Invoke-Git -Arguments @('-C', $root, 'rev-parse', 'HEAD')) }
        Write-UpdateLog "git_head=$script:RepositoryHead"

        if (Test-Path -LiteralPath (Join-Path $root 'compose.r2-runtime.yaml')) {
            $status = Invoke-Git -Arguments @('-C', $root, 'status', '--porcelain=v1', '--untracked-files=all')
            $overlayStatus = @($status.Output | ForEach-Object { [string] $_ } | Where-Object { $_ -eq '?? compose.r2-runtime.yaml' })
            if ($overlayStatus.Count -gt 0) { Write-UpdateLog 'preserved untracked compose.r2-runtime.yaml; base compose.yaml remains selected' }
        }

        $context = Get-OutputText (Invoke-Docker -Arguments @('context', 'show'))
        if ($context -ne 'desktop-linux' -or (Get-OutputText (Invoke-Docker -Arguments @('info', '--format', '{{.OSType}}'))) -ne 'linux') {
            throw 'Docker Desktop desktop-linux/Linux containers не доступні.'
        }
        $script:ComposeArguments = @('-p', $script:ProjectName, '--project-directory', $root, '-f', (Join-Path $root 'compose.yaml'))
        if ($script:TestMode) { $script:ComposeArguments += @('-f', [IO.Path]::GetFullPath($TestComposeOverride)) }
        $composeConfig = Get-SafeComposeConfig -Root $root
        Assert-TestProjectDisjoint
        $databaseContainer = Assert-LocalProject -Root $root
        $script:DatabaseImage = $databaseContainer.Image
        $appInspect = Invoke-Docker -Arguments @('inspect', (Get-ContainerName app), '--format', '{{.Image}}') -AllowFailure
        $script:AppImage = if ($appInspect.ExitCode -eq 0) { Get-OutputText $appInspect } elseif ($script:TestMode) { Get-OutputText (Invoke-Docker -Arguments @('image', 'inspect', (Get-ImageName app), '--format', '{{.Id}}')) } else { $null }
        if (-not $script:AppImage) { throw 'Не вдалося визначити локальний app image.' }

        Set-Phase -Name 'build' -State 'started'
        $null = Invoke-Compose -Arguments @('build', 'app', 'web')
        Set-Phase -Name 'build' -State 'complete'
        $script:AppImage = Get-OutputText (Invoke-Docker -Arguments @('image', 'inspect', (Get-ImageName app), '--format', '{{.Id}}'))

        Set-Phase -Name 'identity_and_migrations' -State 'started'
        $before = Get-DatabaseSnapshot
        if ($before.status -ne 'PASS' -or $before.identity.database -notmatch '^telmanfit_local_[a-f0-9]{16}$' -or $before.identity.postgres_major -ne 18 -or $before.restore_fence -ne 'PASS') {
            throw 'Preflight DB identity або RestoreFence не пройшли.'
        }
        $script:DatabaseName = $before.identity.database
        $allowlist = Get-Allowlist -Root $root
        $plan = Get-PendingPlan -Snapshot $before -Allowlist $allowlist -Root $root
        if ($plan.Count -eq 0) {
            Write-UpdateLog 'pending_migrations=0 backup=skipped'
            Set-Phase -Name 'start_services' -State 'started'
            $null = Invoke-Compose -Arguments @('up', '-d', '--no-deps', 'app', 'worker', 'scheduler', 'web')
            Set-Phase -Name 'start_services' -State 'complete'
        } else {
            $plannedTables = @()
            foreach ($migration in $plan) {
                Assert-MigrationPreconditions -Snapshot $before -Migration $migration -PlannedTables $plannedTables
                $plannedTables += @($migration.postconditions.required_tables)
            }
            $backupRoot = Join-Path $stateRoot 'backups'
            Set-PrivateDirectoryAcl -Path $backupRoot
            $script:BackupDirectory = Join-Path $backupRoot "$script:RunId-$($script:RepositoryHead.Substring(0, 8))"
            if (Test-Path -LiteralPath $script:BackupDirectory) { throw 'Backup destination already exists; refusing overwrite.' }
            New-Item -ItemType Directory -Path $script:BackupDirectory | Out-Null
            Set-PrivateDirectoryAcl -Path $script:BackupDirectory

            Set-Phase -Name 'stop_writers' -State 'started'
            $null = Invoke-Compose -Arguments @('stop', '--timeout', '60', 'app', 'worker', 'scheduler', 'web')
            Assert-WritersStopped
            Set-Phase -Name 'stop_writers' -State 'complete'

            $dumpName = "postgres-$script:RunId.dump"
            $containerDump = "/tmp/$dumpName"
            Set-Phase -Name 'backup' -State 'started'
            $null = Invoke-Compose -Arguments @('exec', '-T', 'database', 'pg_dump', '--format=custom', '--no-owner', '--no-privileges', '-U', 'postgres', '-d', $script:DatabaseName, '-f', $containerDump)
            $dumpPath = Join-Path $script:BackupDirectory 'postgresql.dump'
            $null = Invoke-Docker -Arguments @('cp', "$(Get-ContainerName database):$containerDump", $dumpPath)
            $tocResult = Invoke-Compose -Arguments @('exec', '-T', 'database', 'pg_restore', '--list', $containerDump)
            if (@($tocResult.Output).Count -lt 10) { throw 'pg_restore не прочитав custom-format backup TOC.' }
            $null = Invoke-Compose -Arguments @('exec', '-T', 'database', 'rm', '-f', $containerDump)
            $dumpMetadata = Assert-BackupMetadata -Path $dumpPath

            $null = Invoke-Compose -Arguments @('stop', '--timeout', '60', 'database')
            foreach ($volume in @('database', 'database-secrets', 'local-state', 'epoch', 'storage')) {
                $volumeName = Get-VolumeName $volume
                $null = Invoke-Docker -Arguments @('volume', 'inspect', $volumeName)
                Invoke-TarArchive -DatabaseImage $script:DatabaseImage -Volume $volumeName -BackupDirectory $script:BackupDirectory -Archive "$volume.tar"
                Invoke-TarArchive -DatabaseImage $script:DatabaseImage -Volume $volumeName -BackupDirectory $script:BackupDirectory -Archive "$volume.tar" -Compare
            }

            $volumeManifest = @()
            foreach ($volume in @('database', 'database-secrets', 'local-state', 'epoch', 'storage')) {
                $archive = "$volume.tar"
                $metadata = Assert-BackupMetadata -Path (Join-Path $script:BackupDirectory $archive)
                $entries = Get-ArchiveInventory -DatabaseImage $script:DatabaseImage -BackupDirectory $script:BackupDirectory -Archive $archive
                $volumeManifest += [ordered]@{ volume = (Get-VolumeName $volume); archive = $archive; bytes = $metadata.bytes; sha256 = $metadata.sha256; entries = $entries }
            }
            $manifest = [ordered]@{
                format_version = 1
                run_id = $script:RunId
                created_utc = (Get-Date).ToUniversalTime().ToString('o')
                project = $script:ProjectName
                source_sha = $script:RepositoryHead
                database = $script:DatabaseName
                postgres_major = 18
                database_identity = $before.identity.cluster_proof
                app_image = $script:AppImage
                database_image = $script:DatabaseImage
                dump = [ordered]@{ archive = 'postgresql.dump'; bytes = $dumpMetadata.bytes; sha256 = $dumpMetadata.sha256; toc_entries = @($tocResult.Output).Count }
                volumes = $volumeManifest
                pre_upgrade = [ordered]@{ catalog_rows = $before.catalog.rows; image_urls = $before.catalog.urls; catalog_fingerprint = $before.catalog.fingerprint; migration_count = @($before.migrations.ran).Count; pending = @($before.migrations.pending) }
                verification = [ordered]@{ tar_compare = 'PASS'; pg_restore_list = 'PASS'; restore_rehearsal = 'pending' }
            }
            $manifestPath = Join-Path $script:BackupDirectory 'manifest.json'
            $manifest | ConvertTo-Json -Depth 12 | Set-Content -LiteralPath $manifestPath -Encoding utf8
            $sid = [System.Security.Principal.WindowsIdentity]::GetCurrent().User.Value
            $null = Invoke-Native -Command 'icacls' -Arguments @($manifestPath, '/inheritance:r', '/grant:r', "*${sid}:F", '*S-1-5-18:F')
            Set-Phase -Name 'backup' -State 'archives_complete'

            Invoke-BackupRestoreRehearsal -BackupDirectory $script:BackupDirectory -DatabaseImage $script:DatabaseImage -BeforeSnapshot $before -CustomDump $dumpPath
            $manifest.verification.restore_rehearsal = 'PASS'
            $manifest | ConvertTo-Json -Depth 12 | Set-Content -LiteralPath $manifestPath -Encoding utf8
            Set-Phase -Name 'backup' -State 'complete'

            Set-Phase -Name 'restart_database' -State 'started'
            Start-LocalDatabase
            $afterDatabase = Get-DatabaseSnapshot
            Assert-SnapshotUnchanged -Before $before -After $afterDatabase
            Set-Phase -Name 'restart_database' -State 'complete'

            $current = $afterDatabase
            foreach ($migration in $plan) {
                Assert-MigrationPreconditions -Snapshot $current -Migration $migration
                Set-Phase -Name "migration_$($migration.name)" -State 'started'
                $migrationPath = "--path=database/migrations/$($migration.name).php"
                $null = Invoke-Compose -Arguments @('run', '--rm', '--no-deps', '--user', 'www-data', '--entrypoint', 'php', 'app', '-d', 'auto_prepend_file=/var/www/html/docker/local/load-env.php', 'artisan', 'migrate', $migrationPath, '--force', '--no-interaction')
                Set-Phase -Name "migration_$($migration.name)" -State 'command_complete'
                $current = Get-DatabaseSnapshot
                if ($migration.name -notin @($current.migrations.ran)) { throw "Міграція $($migration.name) не записана у migrations." }
                Assert-MigrationPostconditions -Snapshot $current -Migration $migration
                Set-Phase -Name "migration_$($migration.name)" -State 'complete'
            }
            if (@($current.migrations.pending).Count -ne 0) { throw 'Після allowlisted міграцій залишилися pending migration files.' }
            Assert-SnapshotUnchanged -Before $before -After $current
            $manifest.verification.post_migration_snapshot = 'PASS'
            $manifest | ConvertTo-Json -Depth 12 | Set-Content -LiteralPath $manifestPath -Encoding utf8

            Set-Phase -Name 'start_services' -State 'started'
            $null = Invoke-Compose -Arguments @('up', '-d', '--no-deps', 'app', 'worker', 'scheduler', 'web')
            Set-Phase -Name 'start_services' -State 'started_waiting_health'
        }

        $deadline = (Get-Date).AddMinutes(3)
        $expected = @{
            'database' = 'healthy'
            'app' = 'healthy'
            'web' = 'healthy'
            'worker' = 'running'
            'scheduler' = 'running'
        }
        do {
            $states = @{}
            foreach ($service in $expected.Keys) {
                $name = Get-ContainerName $service
                $format = if ($service -in @('database', 'app', 'web')) { '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' } else { '{{.State.Status}}' }
                $result = Invoke-Docker -Arguments @('inspect', '--format', $format, $name) -AllowFailure
                $states[$service] = if ($result.ExitCode -eq 0) { Get-OutputText $result } else { 'missing' }
            }
            $allReady = $true
            foreach ($service in $expected.Keys) { if ($states[$service] -ne $expected[$service]) { $allReady = $false } }
            if ($allReady) { break }
            Start-Sleep -Seconds 3
        } while ((Get-Date) -lt $deadline)
        foreach ($service in $expected.Keys) {
            if ($states[$service] -ne $expected[$service]) { throw "Health gate: $service state=$($states[$service]); expected=$($expected[$service])." }
        }
        Set-Phase -Name 'complete' -State 'PASS'
        Write-UpdateLog "backup_directory=$script:BackupDirectory"
        Write-UpdateLog 'local update completed successfully'
    } catch {
        if ($script:Phase -eq 'journal_gate_blocked') {
            Write-UpdateLog "journal_gate_blocked=$($_.Exception.Message)"
            throw
        }
        Set-Phase -Name $(if ($script:Phase) { $script:Phase } else { 'unknown' }) -State 'FAILED'
        Write-UpdateLog "error=$($_.Exception.Message)"
        Write-UpdateLog 'Fail closed: no rollback, restore, volume removal, or service auto-resume was attempted.'
        throw
    } finally {
        if ($lockStream) { $lockStream.Dispose() }
    }
}

$libraryOnly = Get-Variable -Name LocalUpdateLibraryOnly -Scope Script -ValueOnly -ErrorAction SilentlyContinue
if ($MyInvocation.InvocationName -ne '.' -and -not $libraryOnly) {
    Invoke-LocalUpdate
}

