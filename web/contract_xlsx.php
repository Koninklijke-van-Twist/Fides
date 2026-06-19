<?php

/**
 * Minimal XLSX writer with Excel table (sort/filter) support.
 */

class ContractXlsxWriter
{
    public const STYLE_DEFAULT = 0;
    public const STYLE_LIGHT_BLUE = 1;
    public const STYLE_DARK_BLUE = 2;

    /** @var list<list<array{value:mixed,styleId:?int}>> */
    private array $rows = [];

    /** @var list<array{headerRow:int,endRow:int,startCol:int,columnNames:list<string>}> */
    private array $tables = [];

    /** @var array<int,float> */
    private array $columnWidths = [];

    public function setColumnWidths(array $widths): void
    {
        foreach ($widths as $columnNumber => $width) {
            $columnNumber = (int) $columnNumber;
            $width = (float) $width;
            if ($columnNumber < 1 || $width <= 0) {
                continue;
            }
            $this->columnWidths[$columnNumber] = $width;
        }
    }

    public function addRow(array $cells, ?int $colorStyleId = null, int $blockColumns = 0, int $tableColumns = 0): int
    {
        $normalized = [];
        foreach (array_values($cells) as $cell) {
            $normalized[] = ['value' => $cell, 'styleId' => null];
        }

        if ($blockColumns > count($normalized)) {
            while (count($normalized) < $blockColumns) {
                $normalized[] = ['value' => '', 'styleId' => null];
            }
        }

        if ($colorStyleId !== null && $blockColumns > 0) {
            $padFrom = max(count(array_values($cells)), $tableColumns);
            for ($index = $padFrom; $index < $blockColumns; $index++) {
                $normalized[$index]['styleId'] = $colorStyleId;
            }
        }

        $this->rows[] = $normalized;

        return count($this->rows);
    }

    public function addBackgroundRow(?int $styleId, int $blockColumns = 7): int
    {
        $cells = [];
        for ($index = 0; $index < $blockColumns; $index++) {
            $cells[] = ['value' => '', 'styleId' => $styleId];
        }

        $this->rows[] = $cells;

        return count($this->rows);
    }

    public function addEmptyRow(): int
    {
        $this->rows[] = [];

        return count($this->rows);
    }

    public function registerTable(int $headerRow, int $endRow, int $startCol, array $columnNames): void
    {
        $columnNames = array_values(array_filter(array_map(static function ($name): string {
            return trim((string) $name);
        }, $columnNames), static function (string $name): bool {
            return $name !== '';
        }));

        if ($columnNames === [] || $endRow < $headerRow) {
            return;
        }

        $this->tables[] = [
            'headerRow' => $headerRow,
            'endRow' => $endRow,
            'startCol' => max(1, $startCol),
            'columnNames' => $columnNames,
        ];
    }

    public function sendDownload(string $filename): void
    {
        $binary = $this->build();
        $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) ?: 'export.xlsx';

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $safeName . '"');
        header('Content-Length: ' . (string) strlen($binary));
        header('Cache-Control: no-store, no-cache, must-revalidate');

        echo $binary;
    }

    private function build(): string
    {
        $sheetXml = $this->buildSheetXml();
        $tableRelsXml = '';
        $tableFiles = [];

        foreach ($this->tables as $index => $table) {
            $tableNumber = $index + 1;
            $tableFiles['xl/tables/table' . $tableNumber . '.xml'] = $this->buildTableXml($table, $tableNumber);
            $tableRelsXml .= '<Relationship Id="rId' . (string) ($index + 2)
                . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/table"'
                . ' Target="../tables/table' . $tableNumber . '.xml"/>';
        }

        if ($tableFiles !== []) {
            $tablePartsXml = '';
            foreach ($this->tables as $index => $table) {
                $tablePartsXml .= '<tablePart r:id="rId' . (string) ($index + 2) . '"/>';
            }
            $sheetXml = str_replace(
                '</worksheet>',
                '<tableParts count="' . count($this->tables) . '">' . $tablePartsXml . '</tableParts></worksheet>',
                $sheetXml
            );
        }

        $files = [
            '[Content_Types].xml' => $this->buildContentTypesXml(count($this->tables)),
            '_rels/.rels' => $this->buildRootRelsXml(),
            'xl/workbook.xml' => $this->buildWorkbookXml(),
            'xl/_rels/workbook.xml.rels' => $this->buildWorkbookRelsXml(),
            'xl/styles.xml' => $this->buildStylesXml(),
            'xl/worksheets/sheet1.xml' => $sheetXml,
            'xl/worksheets/_rels/sheet1.xml.rels' => $this->buildSheetRelsXml(count($this->tables), $tableRelsXml),
        ] + $tableFiles;

        uksort($files, static function (string $left, string $right): int {
            if ($left === '[Content_Types].xml') {
                return -1;
            }
            if ($right === '[Content_Types].xml') {
                return 1;
            }

            return strcmp($left, $right);
        });

        if (class_exists('ZipArchive')) {
            return $this->buildWithZipArchive($files);
        }

        return contract_xlsx_pack_store_zip($files);
    }

    private function buildWithZipArchive(array $files): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'contract_xlsx_');
        if ($tempFile === false) {
            throw new RuntimeException('Kon geen tijdelijk bestand aanmaken voor export.');
        }

        $zipPath = $tempFile . '.xlsx';
        @unlink($tempFile);

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Kon XLSX-archief niet openen.');
        }

        foreach ($files as $path => $contents) {
            $zip->addFromString($path, $contents);
        }

        $zip->close();

        $binary = (string) file_get_contents($zipPath);
        @unlink($zipPath);

        return $binary;
    }

    private function buildSheetXml(): string
    {
        $maxCol = 1;
        foreach ($this->rows as $row) {
            $maxCol = max($maxCol, count($row));
        }

        $sheetData = '';
        foreach ($this->rows as $rowIndex => $row) {
            $rowNumber = $rowIndex + 1;
            if ($row === []) {
                $sheetData .= '<row r="' . $rowNumber . '"/>';
                continue;
            }

            $cellsXml = '';
            foreach ($row as $colIndex => $cell) {
                if (!$this->shouldWriteCell($cell['value'], $cell['styleId'] ?? null)) {
                    continue;
                }

                $cellRef = contract_xlsx_cell_ref($rowNumber, $colIndex + 1);
                $cellsXml .= $this->buildCellXml(
                    $cellRef,
                    $cell['value'],
                    $cell['styleId'] ?? null
                );
            }
            if ($cellsXml === '') {
                $sheetData .= '<row r="' . $rowNumber . '"/>';
                continue;
            }
            $sheetData .= '<row r="' . $rowNumber . '">' . $cellsXml . '</row>';
        }

        $dimension = 'A1:' . contract_xlsx_column_letter($maxCol) . max(1, count($this->rows));
        $colsXml = $this->buildColsXml($maxCol);

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<dimension ref="' . $dimension . '"/>'
            . '<sheetViews><sheetView workbookViewId="0"/></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="15"/>'
            . $colsXml
            . '<sheetData>' . $sheetData . '</sheetData>'
            . '</worksheet>';
    }

    private function buildColsXml(int $maxCol): string
    {
        if ($this->columnWidths === []) {
            return '';
        }

        $colsXml = '';
        for ($columnNumber = 1; $columnNumber <= $maxCol; $columnNumber++) {
            if (!isset($this->columnWidths[$columnNumber])) {
                continue;
            }

            $width = $this->columnWidths[$columnNumber];
            $colsXml .= '<col min="' . $columnNumber . '" max="' . $columnNumber . '" width="'
                . contract_xlsx_xml_escape((string) $width) . '" customWidth="1"/>';
        }

        if ($colsXml === '') {
            return '';
        }

        return '<cols>' . $colsXml . '</cols>';
    }

    private function shouldWriteCell(mixed $value, ?int $styleId): bool
    {
        if ($styleId !== null && $styleId > 0) {
            return true;
        }

        if (is_array($value) && ($value[0] ?? '') === 'hyperlink') {
            return true;
        }

        if (is_int($value) || is_float($value)) {
            return true;
        }

        return contract_xlsx_sanitize_text((string) $value) !== '';
    }

    private function buildCellXml(string $cellRef, mixed $value, ?int $styleId = null): string
    {
        $styleAttr = $styleId !== null && $styleId > 0
            ? ' s="' . (string) $styleId . '"'
            : '';

        if (is_array($value) && ($value[0] ?? '') === 'hyperlink') {
            $url = (string) ($value[1] ?? '');
            $label = (string) ($value[2] ?? 'Link');
            $formula = 'HYPERLINK("' . str_replace('"', '""', $url) . '","' . str_replace('"', '""', $label) . '")';

            return '<c r="' . $cellRef . '" t="str"' . $styleAttr . '><f>' . contract_xlsx_xml_escape($formula) . '</f><v>' . contract_xlsx_xml_escape($label) . '</v></c>';
        }

        if (is_int($value) || is_float($value)) {
            return '<c r="' . $cellRef . '"' . $styleAttr . '><v>' . contract_xlsx_xml_escape((string) $value) . '</v></c>';
        }

        $text = contract_xlsx_sanitize_text((string) $value);
        if ($text === '' && $styleAttr !== '') {
            return '<c r="' . $cellRef . '"' . $styleAttr . '/>';
        }

        $escapedText = contract_xlsx_xml_escape($text);

        return '<c r="' . $cellRef . '" t="inlineStr"' . $styleAttr . '><is><t xml:space="preserve">' . $escapedText . '</t></is></c>';
    }

    private function buildTableXml(array $table, int $tableNumber): string
    {
        $startCol = (int) $table['startCol'];
        $headerRow = (int) $table['headerRow'];
        $endRow = (int) $table['endRow'];
        $columnNames = $table['columnNames'];
        $endCol = $startCol + count($columnNames) - 1;
        $ref = contract_xlsx_cell_ref($headerRow, $startCol) . ':' . contract_xlsx_cell_ref($endRow, $endCol);
        $tableName = 'Table' . $tableNumber;

        $columnsXml = '';
        foreach ($columnNames as $index => $name) {
            $columnsXml .= '<tableColumn id="' . (string) ($index + 1) . '" name="' . contract_xlsx_xml_escape(contract_xlsx_sanitize_text($name)) . '"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<table xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" id="' . $tableNumber . '" name="' . $tableName . '" displayName="' . $tableName . '" ref="' . $ref . '" totalsRowShown="0">'
            . '<autoFilter ref="' . $ref . '"/>'
            . '<tableColumns count="' . count($columnNames) . '">' . $columnsXml . '</tableColumns>'
            . '<tableStyleInfo name="TableStyleMedium2" showFirstColumn="0" showLastColumn="0" showRowStripes="1" showColumnStripes="0"/>'
            . '</table>';
    }

    private function buildContentTypesXml(int $tableCount): string
    {
        $overrides = ''
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';

        for ($index = 1; $index <= $tableCount; $index++) {
            $overrides .= '<Override PartName="/xl/tables/table' . $index . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.table+xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . $overrides
            . '</Types>';
    }

    private function buildRootRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private function buildWorkbookXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Export" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
    }

    private function buildWorkbookRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

    private function buildSheetRelsXml(int $tableCount, string $tableRelsXml): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . $tableRelsXml
            . '</Relationships>';
    }

    private function buildStylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="1"><font><sz val="11"/><name val="Calibri"/><family val="2"/></font></fonts>'
            . '<fills count="4">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFEEF3FA"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFD6E4F0"/><bgColor indexed="64"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="3">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="0" fillId="2" borderId="0" xfId="0" applyFill="1"/>'
            . '<xf numFmtId="0" fontId="0" fillId="3" borderId="0" xfId="0" applyFill="1"/>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }
}

function contract_xlsx_column_letter(int $columnNumber): string
{
    $columnNumber = max(1, $columnNumber);
    $letters = '';
    while ($columnNumber > 0) {
        $columnNumber--;
        $letters = chr(65 + ($columnNumber % 26)) . $letters;
        $columnNumber = intdiv($columnNumber, 26);
    }

    return $letters;
}

function contract_xlsx_cell_ref(int $rowNumber, int $columnNumber): string
{
    return contract_xlsx_column_letter($columnNumber) . max(1, $rowNumber);
}

function contract_xlsx_xml_escape(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function contract_xlsx_sanitize_text(string $value): string
{
    return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
}

function contract_xlsx_crc32_binary(string $data): string
{
    return pack('V', crc32($data) & 0xffffffff);
}

function contract_xlsx_pack_store_zip(array $files): string
{
    $centralDirectory = '';
    $contents = '';
    $offset = 0;
    $fileCount = count($files);

    foreach ($files as $path => $data) {
        $path = str_replace('\\', '/', (string) $path);
        $data = (string) $data;
        $pathLength = strlen($path);
        $dataLength = strlen($data);
        $crcBinary = contract_xlsx_crc32_binary($data);

        $localHeader = pack('V', 0x04034b50)
            . pack('v', 20)
            . pack('v', 0)
            . pack('v', 0)
            . pack('v', 0)
            . pack('v', 0)
            . $crcBinary
            . pack('V', $dataLength)
            . pack('V', $dataLength)
            . pack('v', $pathLength)
            . pack('v', 0)
            . $path;

        $centralDirectory .= pack('V', 0x02014b50)
            . pack('v', 20)
            . pack('v', 20)
            . pack('v', 0)
            . pack('v', 0)
            . pack('v', 0)
            . pack('v', 0)
            . $crcBinary
            . pack('V', $dataLength)
            . pack('V', $dataLength)
            . pack('v', $pathLength)
            . pack('v', 0)
            . pack('v', 0)
            . pack('v', 0)
            . pack('v', 0)
            . pack('V', 0)
            . pack('V', $offset)
            . $path;

        $contents .= $localHeader . $data;
        $offset += strlen($localHeader) + $dataLength;
    }

    $centralDirectoryLength = strlen($centralDirectory);
    $end = pack('V', 0x06054b50)
        . pack('v', 0)
        . pack('v', 0)
        . pack('v', $fileCount)
        . pack('v', $fileCount)
        . pack('V', $centralDirectoryLength)
        . pack('V', $offset)
        . pack('v', 0);

    return $contents . $centralDirectory . $end;
}

function contract_xlsx_format_date(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return $value;
    }

    return date('d-m-Y', $timestamp);
}

function contract_xlsx_hyperlink(string $url, string $label): array
{
    return ['hyperlink', $url, $label];
}

function contract_xlsx_default_column_widths(): array
{
    return [
        1 => 17.85546875,
        2 => 26.140625,
        3 => 20.42578125,
        4 => 18.42578125,
        5 => 6.7109375,
        6 => 6.7109375,
        7 => 3.0,
    ];
}

function contract_build_contract_export_xlsx(string $company, array $contractDetail): ContractXlsxWriter
{
    $writer = new ContractXlsxWriter();
    $writer->setColumnWidths(contract_xlsx_default_column_widths());
    $blockColumns = 7;
    $contract = is_array($contractDetail['contract'] ?? null) ? $contractDetail['contract'] : [];
    $components = is_array($contractDetail['components'] ?? null) ? $contractDetail['components'] : [];

    $metaHeaderRow = $writer->addRow([
        LOC('contract.export.col.field'),
        LOC('contract.export.col.value'),
    ]);
    $metaEndRow = $metaHeaderRow;
    $metaRows = [
        [LOC('contract.label.company'), $company],
        [LOC('contract.export.col.contract_no'), (string) ($contract['contract_no'] ?? '')],
        [LOC('contract.meta.customer'), (string) ($contract['customer_name'] ?? '')],
        [LOC('contract.meta.status'), (string) ($contract['status'] ?? '')],
        [LOC('contract.meta.type'), (string) ($contract['contract_type'] ?? '')],
        [LOC('contract.export.col.period_from'), contract_xlsx_format_date((string) ($contract['starting_date'] ?? ''))],
        [LOC('contract.export.col.period_to'), contract_xlsx_format_date((string) ($contract['end_date'] ?? ''))],
    ];
    foreach ($metaRows as $metaRow) {
        $metaEndRow = $writer->addRow($metaRow);
    }
    $writer->registerTable($metaHeaderRow, $metaEndRow, 1, [
        LOC('contract.export.col.field'),
        LOC('contract.export.col.value'),
    ]);

    $componentIndex = 0;
    foreach ($components as $group) {
        if (!is_array($group)) {
            continue;
        }

        $componentNo = (string) ($group['component_no'] ?? '');
        $features = is_array($group['features'] ?? null) ? $group['features'] : [];
        $workorders = array_values(array_filter(
            is_array($group['workorders'] ?? null) ? $group['workorders'] : [],
            static function ($workorder): bool {
                return is_array($workorder) && trim((string) ($workorder['no'] ?? '')) !== '';
            }
        ));

        if ($componentNo === '' || $workorders === []) {
            continue;
        }

        $blockStyle = ($componentIndex % 2 === 0)
            ? ContractXlsxWriter::STYLE_LIGHT_BLUE
            : ContractXlsxWriter::STYLE_DARK_BLUE;
        $componentIndex++;

        $writer->addBackgroundRow($blockStyle, $blockColumns);

        $featureHeaderRow = $writer->addRow([
            LOC('contract.col.component'),
            LOC('contract.export.col.feature_code'),
            LOC('contract.export.col.feature_value'),
        ], $blockStyle, $blockColumns, 3);
        $featureEndRow = $featureHeaderRow;
        if ($features === []) {
            $featureEndRow = $writer->addRow([$componentNo, '', ''], $blockStyle, $blockColumns, 3);
        } else {
            foreach ($features as $feature) {
                if (!is_array($feature)) {
                    continue;
                }
                $featureEndRow = $writer->addRow([
                    $componentNo,
                    (string) ($feature['feature_code'] ?? ''),
                    (string) ($feature['value'] ?? ''),
                ], $blockStyle, $blockColumns, 3);
            }
        }
        $writer->registerTable($featureHeaderRow, $featureEndRow, 1, [
            LOC('contract.col.component'),
            LOC('contract.export.col.feature_code'),
            LOC('contract.export.col.feature_value'),
        ]);

        $writer->addBackgroundRow($blockStyle, $blockColumns);

        $workorderHeaderRow = $writer->addRow([
            LOC('contract.col.component'),
            LOC('contract.col.workorder'),
            LOC('contract.col.task'),
            LOC('contract.col.status'),
            LOC('contract.link.pdf'),
            LOC('contract.link.excel'),
        ], $blockStyle, $blockColumns, 6);
        $workorderEndRow = $workorderHeaderRow;
        foreach ($workorders as $workorder) {
            if (!is_array($workorder)) {
                continue;
            }
            $pdfUrl = trim((string) ($workorder['pdf_url'] ?? ''));
            $excelUrl = trim((string) ($workorder['excel_url'] ?? ''));
            $workorderEndRow = $writer->addRow([
                $componentNo,
                (string) ($workorder['no'] ?? ''),
                (string) ($workorder['task_code'] ?? ''),
                (string) ($workorder['status'] ?? ''),
                $pdfUrl !== '' ? contract_xlsx_hyperlink($pdfUrl, LOC('contract.link.pdf')) : '',
                $excelUrl !== '' ? contract_xlsx_hyperlink($excelUrl, LOC('contract.link.excel')) : '',
            ], $blockStyle, $blockColumns, 6);
        }
        $writer->registerTable($workorderHeaderRow, $workorderEndRow, 1, [
            LOC('contract.col.component'),
            LOC('contract.col.workorder'),
            LOC('contract.col.task'),
            LOC('contract.col.status'),
            LOC('contract.link.pdf'),
            LOC('contract.link.excel'),
        ]);

        $writer->addBackgroundRow($blockStyle, $blockColumns);
    }

    return $writer;
}
