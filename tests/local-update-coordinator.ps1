#requires -Version 5.1
$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$script:LocalUpdateLibraryOnly = $true
. (Join-Path $root 'scripts/local-update.ps1')

$script:assertions = 0
function Assert-True {
    param([bool] $Condition, [string] $Message)
    $script:assertions++
    if (-not $Condition) { throw "FAIL: $Message" }
}

function Assert-Throws {
    param([scriptblock] $Action, [string] $Message)
    $script:assertions++
    try { & $Action } catch { return }
    throw "FAIL: expected refusal: $Message"
}

function New-Snapshot {
    param([string[]] $Pending = @())
    $tables = [pscustomobject]@{
        users = [pscustomobject]@{ rows = 2; fingerprint = 'users-fingerprint' }
        user_totp_factors = [pscustomobject]@{ rows = 1; fingerprint = 'totp-fingerprint' }
        mfa_recovery_codes = [pscustomobject]@{ rows = 2; fingerprint = 'recovery-fingerprint' }
        migrations = [pscustomobject]@{ rows = 28; fingerprint = 'migration-fingerprint' }
    }
    [pscustomobject]@{
        status = 'PASS'
        restore_fence = 'PASS'
        identity = [pscustomobject]@{ database = 'telmanfit_local_0123456789abcdef'; postgres_major = 18 }
        migrations = [pscustomobject]@{ ran = @('old_migration'); pending = $Pending }
        schema = [pscustomobject]@{ tables = @('users', 'meal_plan_versions', 'meal_plan_days', 'meals', 'meal_items', 'recipe_versions', 'recipe_ingredients', 'recipe_batch_formats', 'recipe_batch_quantities', 'client_meal_plan_assignments', 'meal_plans', 'recipes'); functions = @(); triggers = @(); views = @() }
        tables = $tables
        catalog = [pscustomobject]@{ rows = 108; urls = 85; fingerprint = 'catalog-fingerprint' }
    }
}

$allowlist = Get-Allowlist -Root $root
Assert-True ($allowlist.migrations.Count -eq 2) 'allowlist contains only the two explicitly approved migrations'
Assert-True (($allowlist.migrations.name -join ',') -eq '2026_10_28_000000_create_cooking_templates,2026_10_28_010000_add_cooking_selection') 'allowlist contains the reviewed cooking migration names only'
$empty = New-Snapshot
$emptyPlan = Get-PendingPlan -Snapshot $empty -Allowlist $allowlist -Root $root
Assert-True ($emptyPlan.Count -eq 0) 'no pending migrations produce an empty plan'

$names = @($allowlist.migrations | Sort-Object order | ForEach-Object { $_.name })
$pendingSnapshot = New-Snapshot -Pending $names
$plan = Get-PendingPlan -Snapshot $pendingSnapshot -Allowlist $allowlist -Root $root
Assert-True ($plan.Count -eq 2 -and $plan[0].order -eq 1 -and $plan[1].order -eq 2) 'approved migrations are planned in declared order'

$unknownSnapshot = New-Snapshot -Pending @($names + '2026_10_29_000000_unknown_change')
Assert-Throws { Get-PendingPlan -Snapshot $unknownSnapshot -Allowlist $allowlist -Root $root } 'unknown migration is refused'

$changedFileAllowlist = $allowlist | ConvertTo-Json -Depth 20 | ConvertFrom-Json
$changedFileAllowlist.migrations[0].sha256 = ('0' * 64)
Assert-Throws { Get-PendingPlan -Snapshot $pendingSnapshot -Allowlist $changedFileAllowlist -Root $root } 'changed migration file is refused'

$changedSqlAllowlist = $allowlist | ConvertTo-Json -Depth 20 | ConvertFrom-Json
$changedSqlAllowlist.migrations[0].sources[0].sha256 = ('0' * 64)
Assert-Throws { Get-PendingPlan -Snapshot $pendingSnapshot -Allowlist $changedSqlAllowlist -Root $root } 'changed SQL dependency is refused'

$first = $allowlist.migrations[0]
Assert-MigrationPreconditions -Snapshot $pendingSnapshot -Migration $first
$badPreconditionSnapshot = New-Snapshot -Pending $names
$badPreconditionSnapshot.schema.tables = @('users')
Assert-Throws { Assert-MigrationPreconditions -Snapshot $badPreconditionSnapshot -Migration $first } 'missing prerequisite table is refused'

$post = [pscustomobject]@{ schema = [pscustomobject]@{ tables = @('cooking_templates'); functions = @(); triggers = @(); views = @('cooking_output_balances') } }
$viewRequirement = [pscustomobject]@{ name = 'test'; postconditions = [pscustomobject]@{ required_tables = @('cooking_templates'); required_functions = @(); required_triggers = @(); required_views = @('cooking_output_balances') } }
Assert-MigrationPostconditions -Snapshot $post -Migration $viewRequirement
$post.schema.views = @()
Assert-Throws { Assert-MigrationPostconditions -Snapshot $post -Migration $viewRequirement } 'missing view is refused'

$before = New-Snapshot
$same = New-Snapshot
Assert-SnapshotUnchanged -Before $before -After $same
$same.catalog.urls = 84
Assert-Throws { Assert-SnapshotUnchanged -Before $before -After $same } 'catalog URL loss is detected'
$authChanged = New-Snapshot
$authChanged.tables.user_totp_factors.fingerprint = 'changed-totp'
Assert-Throws { Assert-SnapshotUnchanged -Before $before -After $authChanged } 'MFA data mutation is detected'
$fenceChanged = New-Snapshot
$fenceChanged.restore_fence = 'FAIL'
Assert-Throws { Assert-SnapshotUnchanged -Before $before -After $fenceChanged } 'RestoreFence failure is detected'

$testRoot = Join-Path ([IO.Path]::GetTempPath()) "telmanfit-coordinator-lock-$([guid]::NewGuid().ToString('N'))"
$lockPath = Join-Path $testRoot 'update.lock'
$lock = Enter-UpdateLock -LockPath $lockPath
try { Assert-Throws { Enter-UpdateLock -LockPath $lockPath } 'concurrent coordinator lock is rejected' } finally { $lock.Dispose() }
Remove-Item -LiteralPath $testRoot -Recurse -Force

$script:RunId = 'unit-test'
$script:RepositoryHead = 'synthetic'
$script:BackupDirectory = $null
$journalDirectory = Join-Path ([IO.Path]::GetTempPath()) "telmanfit-coordinator-journal-$([guid]::NewGuid().ToString('N'))"
$null = New-Item -ItemType Directory -Path $journalDirectory
$script:JournalPath = Join-Path $journalDirectory 'unit-test.json'
$script:LogPath = $null
Set-Phase -Name 'backup' -State 'started'
$journal = Get-Content -LiteralPath $script:JournalPath -Raw | ConvertFrom-Json
Assert-True ($journal.phase -eq 'backup' -and $journal.state -eq 'started') 'phase journal records an interrupted operation'
Assert-Throws { Assert-NoUnresolvedJournals -Directory $journalDirectory } 'interrupted journal blocks a new upgrade'
Assert-NoUnresolvedJournals -Directory $journalDirectory -AcknowledgedRunId 'unit-test'
Assert-NoUnresolvedJournals -Directory $journalDirectory
Remove-Item -LiteralPath $journalDirectory -Recurse -Force

Assert-Throws { Invoke-Native -Command 'cmd.exe' -Arguments @('/c', 'exit', '29') } 'backup command failure propagates as fatal error'
Assert-Throws { Invoke-Native -Command 'cmd.exe' -Arguments @('/c', 'exit', '30') } 'migration command failure propagates as fatal error'
$coordinatorSource = Get-Content -LiteralPath (Join-Path $root 'scripts/local-update.ps1') -Raw
Assert-True ($coordinatorSource -notmatch 'migrate:rollback|db:wipe|docker\s+compose\s+down\s+-v|git\s+reset\b|git\s+clean\b|--force-push') 'coordinator contains no automatic destructive recovery/reset path'

Write-Output "Local coordinator unit checks: PASS ($script:assertions assertions)"
