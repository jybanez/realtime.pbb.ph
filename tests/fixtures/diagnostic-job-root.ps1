param([ValidateSet('natural-child','timeout-tree','large-output')][string]$Case)
if ($Case -eq 'large-output') {
    [Console]::Out.Write([string]::new('x', 20000))
    [Console]::Error.Write([string]::new('y', 20000))
    exit 0
}
$taskChildInfo = [Diagnostics.ProcessStartInfo]::new()
$taskChildInfo.FileName = (Get-Process -Id $PID).Path
$taskChildInfo.UseShellExecute = $false
$taskChildInfo.CreateNoWindow = $true
foreach ($taskArgument in @('-NoProfile','-Command','Start-Sleep -Seconds 60')) { $taskChildInfo.ArgumentList.Add($taskArgument) }
$taskChild = [Diagnostics.Process]::Start($taskChildInfo)
if ($null -eq $taskChild -or $taskChild.HasExited) { throw 'Fixture child not running' }
[Console]::Out.WriteLine('fixture.child.started:' + $taskChild.Id)
if ($Case -eq 'timeout-tree') { Start-Sleep -Seconds 60 }
exit 0
