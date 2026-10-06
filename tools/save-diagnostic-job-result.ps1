function Save-DiagnosticJobResult($Result, [string]$Directory, [string]$Scope) {
    [IO.File]::WriteAllText((Join-Path $Directory 'stdout'), $Result.Stdout.Text)
    [IO.File]::WriteAllText((Join-Path $Directory 'stderr'), $Result.Stderr.Text)
    @{ scope=$Scope; pid=$Result.Pid; timeout=$Result.Timeout; root_reaped=$Result.RootReaped; root_exit=$Result.RootExit; job_assigned=$Result.Assigned; tree_cleanup_verified=$Result.TreeCleanupVerified; termination_requested=$Result.TerminationRequested; failure=$Result.Failure; stdout_complete=$Result.Stdout.Complete; stdout_truncated=$Result.Stdout.Truncated; stdout_failed=$Result.Stdout.Failed; stderr_complete=$Result.Stderr.Complete; stderr_truncated=$Result.Stderr.Truncated; stderr_failed=$Result.Stderr.Failed; exit=$Result.ExitCode; retention_char_limit=8192 } |
        ConvertTo-Json | Set-Content -LiteralPath (Join-Path $Directory 'supervisor.json')
}
