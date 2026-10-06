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
if ($taskDocument.SelectNodes('//*[namespace-uri() != ""]').Count -ne 0) { throw 'Unsupported JUnit namespace' }
if ($taskDocument.SelectNodes('//testcase/testcase|//testcase/testsuites|//testcase/testsuite').Count -ne 0) { throw 'Nested testcase structure invalid' }
foreach ($taskSuite in $taskDocument.SelectNodes('//testsuite|/testsuites')) {
    $taskSummaryNames = @('tests','failures','errors','skipped')
    $taskHasSummary = $taskSuite.Name -eq 'testsuite' -or @($taskSummaryNames | Where-Object { $taskSuite.HasAttribute($_) }).Count -ne 0
    if (!$taskHasSummary) { continue }
    foreach ($taskCounter in $taskSummaryNames) {
        $taskValue = $taskSuite.GetAttribute($taskCounter)
        if ($taskValue -notmatch '^(0|[1-9][0-9]{0,4})$') { throw 'Missing or invalid JUnit counter' }
        $taskRequired = if ($taskCounter -eq 'tests') { $taskSuite.SelectNodes('.//testcase').Count } else { 0 }
        if ([int]$taskValue -ne $taskRequired) { throw 'JUnit summary and testcase counters disagree' }
    }
    foreach ($taskOptionalCounter in @('incomplete','warnings')) {
        if ($taskSuite.HasAttribute($taskOptionalCounter) -and $taskSuite.GetAttribute($taskOptionalCounter) -ne '0') { throw 'JUnit unsuccessful summary counter' }
    }
}
foreach ($taskCaseNode in $taskDocument.SelectNodes('//testcase')) {
    if ($taskCaseNode.ParentNode.Name -ne 'testsuite') { throw 'Testcase must belong directly to a suite' }
}
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
