$sourcePath = 'C:\Users\spencer\OneDrive\Desktop\K9\JCSO K9.accdb'
$outputPath = Join-Path $PSScriptRoot 'k9_access_export.json'

Add-Type -AssemblyName System.Data

$connection = New-Object System.Data.OleDb.OleDbConnection("Provider=Microsoft.ACE.OLEDB.16.0;Data Source=$sourcePath;Persist Security Info=False;")
$connection.Open()

function Read-AccessTable {
    param (
        [string] $Sql
    )

    $adapter = New-Object System.Data.OleDb.OleDbDataAdapter($Sql, $connection)
    $data = New-Object System.Data.DataTable
    [void] $adapter.Fill($data)

    $rows = @()
    foreach ($row in $data.Rows) {
        $item = [ordered] @{}
        foreach ($column in $data.Columns) {
            if ($row[$column.ColumnName] -is [DBNull]) {
                $item[$column.ColumnName] = $null
            } else {
                $item[$column.ColumnName] = $row[$column.ColumnName]
            }
        }
        $rows += [pscustomobject] $item
    }

    return $rows
}

$export = [ordered] @{
    training = Read-AccessTable 'SELECT * FROM [K9Training] ORDER BY [CanineLogID]'
    medical = Read-AccessTable 'SELECT * FROM [K9Medical] ORDER BY [K9MedicalID]'
}

$json = $export | ConvertTo-Json -Depth 6
[System.IO.File]::WriteAllText($outputPath, $json, [System.Text.UTF8Encoding]::new($false))

$connection.Close()
Write-Output $outputPath
