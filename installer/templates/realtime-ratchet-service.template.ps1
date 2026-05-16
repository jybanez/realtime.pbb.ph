$PhpBinary = "{{PHP_BINARY}}"
$InstallPath = "{{INSTALL_PATH}}"
$ServiceName = "{{SERVICE_NAME}}"
$LogPath = "{{LOG_PATH}}"

$Command = "set `"RATCHET_DISABLE_XDEBUG_WARN=1`" && `"$PhpBinary`" artisan realtime:serve"

Write-Host "Register the following startup command for PBB Realtime:"
Write-Host $Command
Write-Host ""
Write-Host "Suggested working directory:"
Write-Host $InstallPath
Write-Host ""
Write-Host "Suggested stdout/stderr log path:"
Write-Host $LogPath
Write-Host ""
Write-Host "Recommended production behavior:"
Write-Host "- automatic start"
Write-Host "- restart on failure"
Write-Host "- logs persisted"
Write-Host "- process isolated from interactive shells"
