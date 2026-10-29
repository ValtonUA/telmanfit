[CmdletBinding()]
param(
    [Parameter(Mandatory)][string] $Feature,
    [ValidateSet('start', 'update', 'fresh', 'stop', 'resume', 'list', 'remove', 'access', 'mail', 'logs')][string] $Action = 'start',
    [string] $Project,
    [ValidatePattern('^[a-f0-9]{40}$')][string] $ExpectedHead
)
$ErrorActionPreference = 'Stop'
$arguments = @((Join-Path $PSScriptRoot 'feature_env.py'), $Feature, '--action', $Action)
if ($Project) { $arguments += @('--project', $Project) }
if ($ExpectedHead) { $arguments += @('--expected-head', $ExpectedHead) }
if (Get-Command py -ErrorAction SilentlyContinue) {
    & py -3 -X utf8 @arguments
} elseif (Get-Command python3 -ErrorAction SilentlyContinue) {
    & python3 -X utf8 @arguments
} else {
    throw 'Потрібен Python 3.10+ (py -3), Git, GitHub CLI та Docker Desktop (Linux containers).'
}
exit $LASTEXITCODE
