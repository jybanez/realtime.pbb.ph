param([Parameter(Mandatory=$true)][string]$EvidenceDirectory)
# Prepared fixture driver only. Requires separately reviewed outer containment before execution.
$ErrorActionPreference = 'Stop'
if (Test-Path -LiteralPath $EvidenceDirectory) { throw 'New fixture evidence required' }
New-Item -ItemType Directory -Path $EvidenceDirectory | Out-Null
$taskRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$taskExpected = @(Get-Content -LiteralPath (Join-Path $PSScriptRoot 'diagnostic-expected-test-identities.txt'))
foreach ($taskCase in @('valid','zero','duplicate','mismatch','unsuccessful','inconsistent-counter')) {
    $taskDoc = [xml]'<testsuites><testsuite name="prepared" tests="14" failures="0" errors="0" skipped="0"/></testsuites>'
    $taskSuite = $taskDoc.DocumentElement.FirstChild
    foreach ($taskIdentity in $taskExpected) {
        $taskParts = $taskIdentity -split '::', 2
        $taskNode = $taskDoc.CreateElement('testcase')
        $taskNode.SetAttribute('class',$taskParts[0]); $taskNode.SetAttribute('name',$taskParts[1])
        $null = $taskSuite.AppendChild($taskNode)
    }
    switch ($taskCase) {
        'zero' { $taskSuite.InnerXml = ''; $taskSuite.SetAttribute('tests','0') }
        'duplicate' { $taskSuite.LastChild.SetAttribute('class',$taskSuite.FirstChild.GetAttribute('class')); $taskSuite.LastChild.SetAttribute('name',$taskSuite.FirstChild.GetAttribute('name')) }
        'mismatch' { $taskSuite.LastChild.SetAttribute('class','Tests\Unit\UnexpectedTest') }
        'unsuccessful' { $null = $taskSuite.FirstChild.AppendChild($taskDoc.CreateElement('failure')); $taskSuite.SetAttribute('failures','1') }
        'inconsistent-counter' { $taskSuite.SetAttribute('errors','1') }
    }
    $taskPath = Join-Path $EvidenceDirectory ($taskCase+'.xml')
    $taskDoc.Save($taskPath)
    $taskAccepted = $false
    try { & (Join-Path $taskRoot 'tools/assert-diagnostic-junit.ps1') -ReportPath $taskPath; $taskAccepted = $true } catch { $taskAccepted = $false }
    [ordered]@{case=$taskCase;accepted=$taskAccepted;expected_acceptance=($taskCase -eq 'valid')} | ConvertTo-Json | Set-Content -LiteralPath (Join-Path $EvidenceDirectory ($taskCase+'.json')) -Encoding utf8
    if ($taskAccepted -ne ($taskCase -eq 'valid')) { throw 'JUnit fixture expectation failed: stop' }
}
exit 0
