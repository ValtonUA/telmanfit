#requires -Version 5.1
<#
.SYNOPSIS
    Runs the TelmanFit PostgreSQL schema integration tests against the isolated test database.

.DESCRIPTION
    Executes tests/pg/t0*.sql with psql under the unprivileged role telmanfit_test_runner.
    Every test script runs in one transaction and ends with ROLLBACK, so no data is persisted
    and no cleanup of telmanfit_test or telmanfit_dev is ever required.

    The script never touches telmanfit_dev and never runs Laravel migrations or seeds.

    Credentials come from the environment only (pgpass.conf or PGPASSWORD); none are stored in the
    repository. psql is invoked with -w, so a missing password fails fast instead of prompting.

    This file is intentionally ASCII-only: Windows PowerShell 5.1 reads .ps1 files without a BOM
    as ANSI, so non-ASCII text inside a script breaks the parser depending on the code page.

.PARAMETER Database
    Test database name. Default: telmanfit_test. Verified before any test runs.

.PARAMETER DbUser
    Login role name. Default: telmanfit_test_runner. Verified before any test runs.

.PARAMETER ExpectedTableCount
    Number of business tables expected in schema public. Default: 37.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File tests/pg/run-pg-tests.ps1

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File tests/pg/run-pg-tests.ps1 -Port 5433 -OutputDir "$env:TEMP\pg-out"

.NOTES
    Exit codes: 0 ok, 1 unexpected error, 2 wrong database or role, 3 schema not applied,
    4 SQL error inside a test, 5 FAIL results present.
#>
[CmdletBinding()]
param(
    [string]$DbHost = '127.0.0.1',
    [int]$Port = 5432,
    [string]$Database = 'telmanfit_test',
    [string]$DbUser = 'telmanfit_test_runner',
    [string]$PsqlPath = '',
    [string]$OutputDir = '',
    [int]$ExpectedTableCount = 37
)

Set-StrictMode -Version Latest
# ErrorActionPreference is deliberately 'Continue':
# psql writes NOTICEs/WARNINGs to stderr, and with 'Stop' a harmless server notice would abort the run.
# All stop conditions below therefore use explicit exit codes (Write-Error does not terminate here).
$ErrorActionPreference = 'Continue'

$scriptDir = $PSScriptRoot
if ([string]::IsNullOrWhiteSpace($scriptDir)) { $scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path }
if ([string]::IsNullOrWhiteSpace($OutputDir)) { $OutputDir = Join-Path $scriptDir 'out' }

function Resolve-PsqlPath {
    param([string]$Explicit)
    if (-not [string]::IsNullOrWhiteSpace($Explicit)) {
        if (-not (Test-Path $Explicit)) { throw "psql not found at: $Explicit" }
        return $Explicit
    }
    $cmd = Get-Command psql -ErrorAction SilentlyContinue
    if ($null -ne $cmd) { return $cmd.Source }
    $candidates = @(
        Get-ChildItem 'C:\Program Files\PostgreSQL' -Directory -ErrorAction SilentlyContinue |
            Sort-Object Name -Descending |
            ForEach-Object { Join-Path $_.FullName 'bin\psql.exe' } |
            Where-Object { Test-Path $_ }
    )
    if ($candidates.Count -gt 0) { return $candidates[0] }
    throw 'psql not found. Pass -PsqlPath "C:\Program Files\PostgreSQL\18\bin\psql.exe".'
}

$psql = Resolve-PsqlPath -Explicit $PsqlPath
New-Item -ItemType Directory -Force -Path $OutputDir | Out-Null

$env:PGCLIENTENCODING = 'UTF8'
$env:PGOPTIONS = '-c statement_timeout=300000'

$baseArgs = @('-h', $DbHost, '-p', "$Port", '-U', $DbUser, '-d', $Database, '-w', '-X', '-P', 'pager=off', '-F', ' | ')

function Invoke-PsqlQuery {
    param([string]$Sql)
    $a = $baseArgs + @('-q', '-A', '-t', '-c', $Sql)
    $raw = & $psql @a 2>&1
    return [pscustomobject]@{ ExitCode = $LASTEXITCODE; Text = ($raw | Out-String) }
}

Write-Host '== TelmanFit: PostgreSQL schema integration tests =='
Write-Host ("psql   : {0}" -f $psql)
Write-Host ("target : {0}@{1}:{2}/{3}" -f $DbUser, $DbHost, $Port, $Database)
Write-Host ("logs   : {0}" -f $OutputDir)
Write-Host ''

# --- 1) verify database and role, stop on mismatch ---
$id = Invoke-PsqlQuery -Sql 'SELECT current_database(), current_user, session_user;'
if ($id.ExitCode -ne 0) {
    Write-Host $id.Text
    Write-Error 'Cannot connect to the test database. Check pgpass.conf/PGPASSWORD and server availability (see README).'
    exit 2
}
$parts = @(($id.Text.Trim() -split '\|') | ForEach-Object { $_.Trim() })
if ($parts.Count -ne 3) {
    Write-Host $id.Text
    Write-Error 'Unexpected server response for the connection check.'
    exit 1
}
if ($parts[0] -ne $Database -or $parts[1] -ne $DbUser -or $parts[2] -ne $DbUser) {
    Write-Error ("STOP: unexpected connection. current_database='{0}', current_user='{1}', session_user='{2}'; expected '{3}' / '{4}'." -f $parts[0], $parts[1], $parts[2], $Database, $DbUser)
    exit 2
}
Write-Host ("[ok] connection: {0} / {1}" -f $parts[0], $parts[1])

# --- 2) schema must already be applied (docs/01...04 in order) ---
$tbl = Invoke-PsqlQuery -Sql "SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' AND c.relkind='r';"
$tableCount = 0
if ($tbl.ExitCode -eq 0) { $tableCount = [int]$tbl.Text.Trim() }
if ($tableCount -ne $ExpectedTableCount) {
    Write-Error ("STOP: schema public of {0} has {1} tables, expected {2}. Apply docs/01..04 first (separate approved step)." -f $Database, $tableCount, $ExpectedTableCount)
    exit 3
}
Write-Host ("[ok] schema present: {0} tables" -f $tableCount)
Write-Host ''
# --- 3) run tests in order (t01 -> t06) ---
$testFiles = @(Get-ChildItem -Path $scriptDir -Filter 't0*.sql' -File | Sort-Object Name)
if ($testFiles.Count -eq 0) { Write-Error 'No test files (t0*.sql) found next to this script.'; exit 1 }

$results = @()
$scriptError = $false

foreach ($file in $testFiles) {
    Write-Host ("--- {0} ---" -f $file.Name)
    $a = $baseArgs + @('-q', '-A', '-v', 'ON_ERROR_STOP=1', '-v', 'VERBOSITY=verbose', '-f', $file.FullName)
    $raw = & $psql @a 2>&1
    $code = $LASTEXITCODE
    $text = ($raw | Out-String)
    $logPath = Join-Path $OutputDir ($file.BaseName + '.out')
    $text | Out-File -FilePath $logPath -Encoding utf8

    $counts = @{ PASS = 0; FAIL = 0; GAP = 0 }
    foreach ($line in ($text -split "`r?`n")) {
        if ($line -match '^(PASS|FAIL|GAP)\s*\|\s*(\d+)\s*$') {
            $counts[$Matches[1]] = $counts[$Matches[1]] + [int]$Matches[2]
        }
        elseif ($line -match '\|\s*(FAIL|GAP)\s*\|') {
            Write-Host ("    {0}" -f $line.Trim())
        }
    }
    $results += [pscustomobject]@{ File = $file.Name; Exit = $code; Pass = $counts.PASS; Fail = $counts.FAIL; Gap = $counts.GAP }
    Write-Host ("    PASS={0} FAIL={1} GAP={2} (exit={3})" -f $counts.PASS, $counts.FAIL, $counts.GAP, $code)

    if ($code -ne 0) {
        # SQL error inside the test: stop the remaining tests and show diagnostics
        Write-Host ("    diagnostics (last 25 lines; full log: {0}):" -f $logPath)
        @($text -split "`r?`n" | Select-Object -Last 25) | ForEach-Object { Write-Host ("      {0}" -f $_) }
        $scriptError = $true
        break
    }
}

# --- 4) summary ---
Write-Host ''
Write-Host '== summary =='
$results | Format-Table File, Exit, Pass, Fail, Gap -AutoSize | Out-String | Write-Host

$totalPass = ($results | Measure-Object -Property Pass -Sum).Sum
$totalFail = ($results | Measure-Object -Property Fail -Sum).Sum
$totalGap  = ($results | Measure-Object -Property Gap -Sum).Sum
Write-Host ("total: PASS={0} FAIL={1} GAP={2} (files run: {3} of {4})" -f $totalPass, $totalFail, $totalGap, $results.Count, $testFiles.Count)
if ($totalGap -gt 0) {
    Write-Host 'GAP = documented DB-level gaps (see tests/pg/README.md); tests never change the schema.'
}

if ($scriptError) { exit 4 }
if ($totalFail -gt 0) { exit 5 }
exit 0
