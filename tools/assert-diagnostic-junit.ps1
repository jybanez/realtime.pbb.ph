param([Parameter(Mandatory=$true)][string]$ReportPath)
$ErrorActionPreference = 'Stop'
$taskExpectedPath = Join-Path $PSScriptRoot '../tests/fixtures/diagnostic-expected-test-identities.txt'
if ((Get-Item -LiteralPath $taskExpectedPath).Length -gt 8192) { throw 'Expected identities exceed cap' }
$taskExpected = @(Get-Content -LiteralPath $taskExpectedPath | Where-Object { $_ -ne '' })
if ($taskExpected.Count -ne 14 -or @($taskExpected | Sort-Object -Unique).Count -ne 14) { throw 'Expected identity set invalid' }
if ((Get-Item -LiteralPath $ReportPath).Length -gt 65536) { throw 'JUnit report exceeds cap' }
$taskSettings = [System.Xml.XmlReaderSettings]::new()
$taskSettings.DtdProcessing = [System.Xml.DtdProcessing]::Prohibit
$taskSettings.XmlResolver = $null
$taskSettings.MaxCharactersInDocument = 65536
$taskReader = [System.Xml.XmlReader]::Create([IO.Path]::GetFullPath($ReportPath), $taskSettings)
try {
    $taskDocument = [System.Xml.XmlDocument]::new()
    $taskDocument.XmlResolver = $null
    $taskDocument.Load($taskReader)
} finally { $taskReader.Dispose() }
if ($taskDocument.DocumentElement.Name -notin @('testsuites','testsuite')) { throw 'JUnit root invalid' }
if ($taskDocument.SelectNodes('//failure|//error|//skipped|//incomplete|//warning').Count -ne 0) { throw 'JUnit contains unsuccessful cases' }
$taskCases = @($taskDocument.SelectNodes('//testcase'))
if ($taskCases.Count -ne 14) { throw 'JUnit must contain exactly 14 executed cases' }
$taskActual = foreach ($taskCase in $taskCases) {
    $taskClass = $taskCase.GetAttribute('class')
    $taskClassName = $taskCase.GetAttribute('classname')
    if ($taskClass -and $taskClassName -and $taskClass -ne $taskClassName) { throw 'Conflicting JUnit class identity' }
    if (!$taskClass) { $taskClass = $taskClassName }
    if (!$taskClass -or !$taskCase.GetAttribute('name')) { throw 'Missing JUnit identity' }
    $taskClass + '::' + $taskCase.GetAttribute('name')
}
if (@($taskActual | Sort-Object -Unique).Count -ne 14 -or @(Compare-Object $taskExpected $taskActual).Count -ne 0) { throw 'JUnit identity set mismatch' }
