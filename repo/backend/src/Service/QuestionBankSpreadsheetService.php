<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\ApiValidationException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class QuestionBankSpreadsheetService
{
    /**
     * @return array{format: 'csv'|'xlsx', rows: list<list<string>>}
     */
    public function parseImportFile(UploadedFile $file): array
    {
        $realPath = $file->getRealPath();
        if (!is_string($realPath) || $realPath === '' || !is_file($realPath)) {
            throw new ApiValidationException('Unable to read import file.', [['field' => 'file', 'issue' => 'unreadable']]);
        }

        $extension = strtolower(pathinfo((string) $file->getClientOriginalName(), PATHINFO_EXTENSION));
        if ($extension === 'csv') {
            return [
                'format' => 'csv',
                'rows' => $this->parseCsv($realPath),
            ];
        }

        if ($extension === 'xlsx') {
            return [
                'format' => 'xlsx',
                'rows' => $this->parseXlsx($realPath),
            ];
        }

        throw new ApiValidationException('Import file must be CSV or XLSX.', [['field' => 'file', 'issue' => 'unsupported_format']]);
    }

    /**
     * @param list<list<string>> $rows
     * @return array{content: string, contentType: string, extension: string}
     */
    public function exportRows(array $rows, string $format): array
    {
        if ($format === 'excel') {
            return [
                'content' => $this->buildXlsx($rows),
                'contentType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'extension' => 'xlsx',
            ];
        }

        if ($format === 'csv') {
            return [
                'content' => $this->buildCsv($rows),
                'contentType' => 'text/csv; charset=utf-8',
                'extension' => 'csv',
            ];
        }

        throw new ApiValidationException('Export format must be csv or excel.', [['field' => 'format', 'issue' => 'invalid']]);
    }

    /** @return list<list<string>> */
    private function parseCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new ApiValidationException('Unable to open CSV file.', [['field' => 'file', 'issue' => 'open_failed']]);
        }

        $rows = [];
        try {
            while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                if (!is_array($row)) {
                    continue;
                }

                $rows[] = array_map(static fn ($value): string => is_scalar($value) ? trim((string) $value) : '', $row);
            }
        } finally {
            fclose($handle);
        }

        return $rows;
    }

    /** @return list<list<string>> */
    private function parseXlsx(string $path): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new ApiValidationException('Unable to parse XLSX file.', [['field' => 'file', 'issue' => 'invalid_xlsx']]);
        }

        try {
            $workbookXml = $zip->getFromName('xl/workbook.xml');
            $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
            if (!is_string($workbookXml) || !is_string($relsXml)) {
                throw new ApiValidationException('XLSX workbook structure is invalid.', [['field' => 'file', 'issue' => 'workbook_missing']]);
            }

            $sheetPath = $this->resolveFirstSheetPath($workbookXml, $relsXml);
            $sheetXml = $zip->getFromName($sheetPath);
            if (!is_string($sheetXml)) {
                throw new ApiValidationException('XLSX worksheet payload is missing.', [['field' => 'file', 'issue' => 'worksheet_missing']]);
            }

            $sharedStringsXml = $zip->getFromName('xl/sharedStrings.xml');
            $sharedStrings = is_string($sharedStringsXml) ? $this->parseSharedStrings($sharedStringsXml) : [];

            return $this->parseSheetRows($sheetXml, $sharedStrings);
        } finally {
            $zip->close();
        }
    }

    private function resolveFirstSheetPath(string $workbookXml, string $relsXml): string
    {
        $workbook = $this->loadDom($workbookXml, 'workbook');
        $workbookXPath = new \DOMXPath($workbook);
        $sheet = $workbookXPath->query('/*[local-name()="workbook"]/*[local-name()="sheets"]/*[local-name()="sheet"][1]')->item(0);
        if (!$sheet instanceof \DOMElement) {
            throw new ApiValidationException('XLSX workbook contains no worksheets.', [['field' => 'file', 'issue' => 'sheet_missing']]);
        }

        $relationshipId = $sheet->getAttribute('r:id');
        if ($relationshipId === '') {
            foreach ($sheet->attributes ?? [] as $attribute) {
                if ($attribute instanceof \DOMAttr && str_ends_with($attribute->name, ':id')) {
                    $relationshipId = $attribute->value;
                    break;
                }
            }
        }

        if ($relationshipId === '') {
            throw new ApiValidationException('Unable to resolve worksheet relationship.', [['field' => 'file', 'issue' => 'sheet_relation_missing']]);
        }

        $rels = $this->loadDom($relsXml, 'workbook relationships');
        $relsXPath = new \DOMXPath($rels);
        $relation = $relsXPath->query(sprintf('/*[local-name()="Relationships"]/*[local-name()="Relationship"][@Id="%s"]', $relationshipId))->item(0);
        if (!$relation instanceof \DOMElement) {
            throw new ApiValidationException('Worksheet relationship target is missing.', [['field' => 'file', 'issue' => 'sheet_target_missing']]);
        }

        $target = $relation->getAttribute('Target');
        if ($target === '') {
            throw new ApiValidationException('Worksheet target path is invalid.', [['field' => 'file', 'issue' => 'sheet_target_invalid']]);
        }

        $normalizedTarget = str_replace('\\', '/', $target);
        if (str_starts_with($normalizedTarget, '/')) {
            return ltrim($normalizedTarget, '/');
        }

        if (str_starts_with($normalizedTarget, 'worksheets/')) {
            return 'xl/'.$normalizedTarget;
        }

        if (str_starts_with($normalizedTarget, '../')) {
            return ltrim(str_replace('../', '', $normalizedTarget), '/');
        }

        return 'xl/'.$normalizedTarget;
    }

    /** @return list<string> */
    private function parseSharedStrings(string $xml): array
    {
        $doc = $this->loadDom($xml, 'shared strings');
        $xpath = new \DOMXPath($doc);
        $items = $xpath->query('/*[local-name()="sst"]/*[local-name()="si"]');

        $shared = [];
        foreach ($items as $item) {
            if (!$item instanceof \DOMElement) {
                continue;
            }

            $textParts = [];
            foreach ($xpath->query('.//*[local-name()="t"]', $item) as $textNode) {
                if ($textNode instanceof \DOMElement || $textNode instanceof \DOMText) {
                    $textParts[] = $textNode->textContent;
                }
            }

            $shared[] = trim(implode('', $textParts));
        }

        return $shared;
    }

    /** @param list<string> $sharedStrings @return list<list<string>> */
    private function parseSheetRows(string $sheetXml, array $sharedStrings): array
    {
        $doc = $this->loadDom($sheetXml, 'worksheet');
        $xpath = new \DOMXPath($doc);
        $rowNodes = $xpath->query('/*[local-name()="worksheet"]/*[local-name()="sheetData"]/*[local-name()="row"]');

        $rows = [];
        foreach ($rowNodes as $rowNode) {
            if (!$rowNode instanceof \DOMElement) {
                continue;
            }

            $cellsByIndex = [];
            $maxIndex = 0;
            foreach ($xpath->query('./*[local-name()="c"]', $rowNode) as $cellNode) {
                if (!$cellNode instanceof \DOMElement) {
                    continue;
                }

                $reference = strtoupper($cellNode->getAttribute('r'));
                preg_match('/^[A-Z]+/', $reference, $matches);
                $letters = $matches[0] ?? '';
                if ($letters === '') {
                    continue;
                }

                $columnIndex = $this->columnLettersToIndex($letters);
                if ($columnIndex <= 0) {
                    continue;
                }
                $maxIndex = max($maxIndex, $columnIndex);

                $type = $cellNode->getAttribute('t');
                $valueNode = $xpath->query('./*[local-name()="v"]', $cellNode)->item(0);
                $value = '';

                if ($type === 's' && $valueNode instanceof \DOMNode) {
                    $sharedIndex = (int) trim($valueNode->textContent);
                    $value = $sharedStrings[$sharedIndex] ?? '';
                } elseif ($type === 'inlineStr') {
                    $inlineTextNode = $xpath->query('./*[local-name()="is"]/*[local-name()="t"]', $cellNode)->item(0);
                    $value = $inlineTextNode instanceof \DOMNode ? trim($inlineTextNode->textContent) : '';
                } elseif ($valueNode instanceof \DOMNode) {
                    $value = trim($valueNode->textContent);
                }

                $cellsByIndex[$columnIndex] = $value;
            }

            if ($maxIndex === 0) {
                continue;
            }

            $row = [];
            for ($index = 1; $index <= $maxIndex; ++$index) {
                $row[] = $cellsByIndex[$index] ?? '';
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /** @param list<list<string>> $rows */
    private function buildCsv(array $rows): string
    {
        $stream = fopen('php://temp', 'r+');
        if (!is_resource($stream)) {
            throw new \RuntimeException('Unable to allocate CSV export stream.');
        }

        foreach ($rows as $row) {
            fputcsv($stream, $row, ',', '"', '\\');
        }

        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        return is_string($content) ? $content : '';
    }

    /** @param list<list<string>> $rows */
    private function buildXlsx(array $rows): string
    {
        $sharedStrings = [];
        $sharedMap = [];
        $sheetRowsXml = [];

        foreach ($rows as $rowIndex => $row) {
            $rowXml = [];
            foreach ($row as $columnIndex => $cellValue) {
                $value = (string) $cellValue;
                if (!array_key_exists($value, $sharedMap)) {
                    $sharedMap[$value] = count($sharedStrings);
                    $sharedStrings[] = $value;
                }

                $cellRef = $this->columnIndexToLetters($columnIndex + 1).($rowIndex + 1);
                $sharedIndex = $sharedMap[$value];
                $rowXml[] = sprintf('<c r="%s" t="s"><v>%d</v></c>', $cellRef, $sharedIndex);
            }

            $sheetRowsXml[] = sprintf('<row r="%d">%s</row>', $rowIndex + 1, implode('', $rowXml));
        }

        $sharedStringsXml = sprintf(
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="%1$d" uniqueCount="%1$d">%2$s</sst>',
            count($sharedStrings),
            implode('', array_map(static fn (string $item): string => '<si><t>'.htmlspecialchars($item, ENT_XML1 | ENT_COMPAT, 'UTF-8').'</t></si>', $sharedStrings)),
        );

        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheetData>'.implode('', $sheetRowsXml).'</sheetData>'
            .'</worksheet>';

        $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="Questions" sheetId="1" r:id="rId1"/></sheets>'
            .'</workbook>';

        $workbookRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
            .'</Relationships>';

        $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
            .'<borders count="1"><border/></borders>'
            .'<cellStyleXfs count="1"><xf/></cellStyleXfs>'
            .'<cellXfs count="1"><xf xfId="0"/></cellXfs>'
            .'</styleSheet>';

        $rootRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';

        $contentTypesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
            .'</Types>';

        $tempPath = tempnam(sys_get_temp_dir(), 'qbank_xlsx_');
        if (!is_string($tempPath) || $tempPath === '') {
            throw new \RuntimeException('Unable to allocate temporary XLSX path.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($tempPath, \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Unable to open temporary XLSX archive.');
        }

        try {
            $zip->addFromString('[Content_Types].xml', $contentTypesXml);
            $zip->addFromString('_rels/.rels', $rootRelsXml);
            $zip->addFromString('xl/workbook.xml', $workbookXml);
            $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRelsXml);
            $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
            $zip->addFromString('xl/styles.xml', $stylesXml);
            $zip->addFromString('xl/sharedStrings.xml', $sharedStringsXml);
        } finally {
            $zip->close();
        }

        $binary = file_get_contents($tempPath);
        unlink($tempPath);

        if (!is_string($binary) || $binary === '') {
            throw new \RuntimeException('Unable to produce XLSX export payload.');
        }

        return $binary;
    }

    private function loadDom(string $xml, string $context): \DOMDocument
    {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = false;

        $loaded = @$doc->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
        if ($loaded !== true) {
            throw new ApiValidationException(sprintf('XLSX %s XML is invalid.', $context), [['field' => 'file', 'issue' => 'invalid_xml']]);
        }

        return $doc;
    }

    private function columnLettersToIndex(string $letters): int
    {
        $letters = strtoupper($letters);
        $index = 0;
        $length = strlen($letters);
        for ($i = 0; $i < $length; ++$i) {
            $code = ord($letters[$i]);
            if ($code < 65 || $code > 90) {
                return 0;
            }

            $index = ($index * 26) + ($code - 64);
        }

        return $index;
    }

    private function columnIndexToLetters(int $index): string
    {
        $letters = '';
        while ($index > 0) {
            $mod = ($index - 1) % 26;
            $letters = chr(65 + $mod).$letters;
            $index = (int) floor(($index - 1) / 26);
        }

        return $letters;
    }
}
