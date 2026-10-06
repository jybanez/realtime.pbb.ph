param([Parameter(Mandatory=$true)][string]$PythonPath)
# Prepared read-only runtime probe. Only after independent plan release, under outer identity phase.
$ErrorActionPreference = 'Stop'
$taskShell = (Get-Process -Id $PID).Path
$taskPythonIdentity = & $PythonPath -I -S -c 'import sys,struct,json; print(json.dumps({"version":sys.version,"bits":struct.calcsize("P")*8,"executable":sys.executable}))'
if ($LASTEXITCODE -ne 0) { throw 'Python identity failed' }
$taskPythonText = $taskPythonIdentity -join "`n"
if ($taskPythonText.Length -gt 2048) { throw 'Python identity exceeded cap' }
$taskPython = $taskPythonText | ConvertFrom-Json
$taskCore = [System.Runtime.InteropServices.RuntimeInformation]
[ordered]@{
    powershell_path = $taskShell
    powershell_version = $PSVersionTable.PSVersion.ToString()
    powershell_sha256 = (Get-FileHash -LiteralPath $taskShell -Algorithm SHA256).Hash.ToLowerInvariant()
    process_architecture = $taskCore::ProcessArchitecture.ToString()
    os_architecture = $taskCore::OSArchitecture.ToString()
    windows_description = $taskCore::OSDescription
    dotnet_description = $taskCore::FrameworkDescription
    dotnet_core_path = [object].Assembly.Location
    dotnet_core_sha256 = (Get-FileHash -LiteralPath ([object].Assembly.Location) -Algorithm SHA256).Hash.ToLowerInvariant()
    python_path = [IO.Path]::GetFullPath($PythonPath)
    python_sha256 = (Get-FileHash -LiteralPath $PythonPath -Algorithm SHA256).Hash.ToLowerInvariant()
    python_version = $taskPython.version
    python_bits = $taskPython.bits
    python_reported_executable = $taskPython.executable
} | ConvertTo-Json -Compress
