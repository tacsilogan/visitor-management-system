<?php
/*
 * Writes formatted Excel workbooks (.xlsx) for the admin's exports, so a downloaded report
 * opens organized: a title, a coloured header row that stays in view, columns wide enough
 * for their contents, real dates and numbers that sort and filter, and status colours.
 *
 * It needs nothing beyond zlib, which every PHP build has: the ZIP container is written
 * here, so neither local XAMPP nor the server image needs the zip extension.
 *
 *   $book = new XlsxBook("Visitor Analytics Report");
 *   $sheet = $book->sheet("Visits");
 *   $sheet->title("Visitor Analytics Report", "This month · generated Oct 13, 2026");
 *   $sheet->columns([["Visitor", "text"], ["Check in", "datetime"], ["Minutes", "number"]]);
 *   $sheet->row(["Juan Dela Cruz", "2026-10-13 09:15:00", 42]);
 *   $book->send("visitor-analytics.xlsx");
 *
 * Column types: text, wrap (long text), number, minutes, datetime, date, time, status.
 * A status cell takes a [label, tone] pair; tones: good, info, warn, danger, muted.
 */

final class XlsxBook
{
    /** @var XlsxSheet[] */
    private array $sheets = [];
    private XlsxStyles $styles;

    public function __construct(private string $title, private string $author = "ISATU Visitor Management")
    {
        $this->styles = new XlsxStyles();
    }

    public function sheet(string $name): XlsxSheet
    {
        // Excel sheet names: at most 31 characters, none of : \ / ? * [ ].
        $clean = trim(preg_replace('/[:\\\\\/?*\[\]]/', " ", $name)) ?: "Sheet" . (count($this->sheets) + 1);
        $sheet = new XlsxSheet(mb_substr($clean, 0, 31), $this->styles);
        $this->sheets[] = $sheet;
        return $sheet;
    }

    /** Sends the workbook as a download and ends the request. */
    public function send(string $filename): never
    {
        $bytes = $this->build();
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', "-", $filename);
        header("Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet");
        header('Content-Disposition: attachment; filename="' . $safe . '"');
        header("Content-Length: " . strlen($bytes));
        header("Cache-Control: no-store");
        echo $bytes;
        exit;
    }

    /** The .xlsx file's bytes. */
    public function build(): string
    {
        if (!$this->sheets) {
            $this->sheet("Sheet1");
        }
        $zip = new XlsxZip();
        $sheetCount = count($this->sheets);

        $overrides = "";
        $workbookRels = "";
        $sheetEntries = "";
        $definedNames = "";
        foreach ($this->sheets as $index => $sheet) {
            $number = $index + 1;
            $zip->add("xl/worksheets/sheet{$number}.xml", $sheet->xml());
            $overrides .= '<Override PartName="/xl/worksheets/sheet' . $number . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $workbookRels .= '<Relationship Id="rId' . $number . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $number . '.xml"/>';
            $sheetEntries .= '<sheet name="' . xlsx_escape($sheet->name()) . '" sheetId="' . $number . '" r:id="rId' . $number . '"/>';
            $quoted = "'" . str_replace("'", "''", $sheet->name()) . "'";
            if ($filter = $sheet->filterRange()) {
                $definedNames .= '<definedName name="_xlnm._FilterDatabase" localSheetId="' . $index . '" hidden="1">' . xlsx_escape($quoted . "!" . $filter) . '</definedName>';
            }
            if ($titles = $sheet->printTitleRows()) {
                $definedNames .= '<definedName name="_xlnm.Print_Titles" localSheetId="' . $index . '">' . xlsx_escape($quoted . "!" . $titles) . '</definedName>';
            }
        }
        $workbookRels .= '<Relationship Id="rId' . ($sheetCount + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

        $zip->add("[Content_Types].xml", '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . $overrides
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            . '</Types>');
        $zip->add("_rels/.rels", '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            . '</Relationships>');
        $created = gmdate("Y-m-d\TH:i:s\Z");
        $zip->add("docProps/core.xml", '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>' . xlsx_escape($this->title) . '</dc:title>'
            . '<dc:creator>' . xlsx_escape($this->author) . '</dc:creator>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $created . '</dcterms:created>'
            . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $created . '</dcterms:modified>'
            . '</cp:coreProperties>');
        $zip->add("docProps/app.xml", '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>ISATU Visitor Management</Application></Properties>');
        $zip->add("xl/workbook.xml", '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<bookViews><workbookView xWindow="0" yWindow="0" windowWidth="28800" windowHeight="15600" activeTab="0"/></bookViews>'
            . '<sheets>' . $sheetEntries . '</sheets>'
            . ($definedNames !== "" ? '<definedNames>' . $definedNames . '</definedNames>' : "")
            . '</workbook>');
        $zip->add("xl/_rels/workbook.xml.rels", '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $workbookRels . '</Relationships>');
        // Styles last: the sheets register the styles they use while being written.
        $zip->add("xl/styles.xml", $this->styles->xml());
        return $zip->bytes();
    }
}

final class XlsxSheet
{
    private const TONES = ["good", "info", "warn", "danger", "muted"];

    private array $columns = [];
    /** Row XML by row number. */
    private array $rows = [];
    /** Rows across the whole table (titles, headings, notes), written once the width is known. */
    private array $wideRows = [];
    private int $rowNumber = 0;
    private int $headerRow = 0;
    private int $lastDataRow = 0;
    private bool $zebra = false;
    private bool $tableTools = true;
    /** Longest text seen per column, for the column widths. */
    private array $widths = [];
    private int $span = 1;

    public function __construct(private string $name, private XlsxStyles $styles)
    {
    }

    public function name(): string
    {
        return $this->name;
    }

    /** A summary sheet: no frozen header, filter buttons or repeated print titles. */
    public function withoutTableTools(): void
    {
        $this->tableTools = false;
    }

    /** A big title and an optional line under it, across the table's width. */
    public function title(string $title, string $subtitle = ""): void
    {
        $this->wide(++$this->rowNumber, $title, ["font" => "title", "valign" => "center"], 30);
        if ($subtitle !== "") {
            $this->wide(++$this->rowNumber, $subtitle, ["font" => "subtitle", "valign" => "center", "wrap" => true], 18);
        }
        $this->rowNumber++; // a blank line before the table
    }

    /** A heading for a section of a summary sheet. */
    public function section(string $heading): void
    {
        if ($this->rowNumber > 0) {
            $this->rowNumber++;
        }
        $this->wide(++$this->rowNumber, $heading, ["font" => "section", "border" => "underline", "valign" => "center"], 22);
    }

    /** A line of plain text under a table, for example "Showing the first 5,000 entries". */
    public function note(string $text): void
    {
        $this->rowNumber += 2;
        $this->wide($this->rowNumber, $text, ["font" => "subtitle", "wrap" => true], 16);
    }

    /**
     * Starts a table. Each column: [header, type] or [header, type, width]. Without a
     * width the column fits its longest value (within limits).
     */
    public function columns(array $columns): void
    {
        $this->columns = [];
        foreach (array_values($columns) as $index => $column) {
            $this->columns[$index + 1] = ["header" => (string) $column[0], "type" => (string) ($column[1] ?? "text"), "width" => $column[2] ?? null];
        }
        $this->span = max($this->span, count($this->columns));
        $this->rowNumber++;
        $cells = [];
        foreach ($this->columns as $number => $column) {
            $cells[] = $this->cellXml($number, $column["header"], $this->styles->id([
                "font" => "header", "fill" => "header", "border" => "thin", "valign" => "center", "wrap" => true,
                "align" => in_array($column["type"], ["number", "minutes"], true) ? "right" : "left",
            ]));
            $this->measure($number, $column["header"]);
        }
        $this->rows[$this->rowNumber] = $this->rowXml($this->rowNumber, $cells, 24);
        if ($this->headerRow === 0) {
            $this->headerRow = $this->rowNumber;
        }
        $this->zebra = false;
    }

    /** One row of the current table. */
    public function row(array $values): void
    {
        $this->rowNumber++;
        $cells = [];
        $wrapped = [];
        $fill = $this->zebra ? "zebra" : null;
        foreach (array_values($values) as $index => $value) {
            $number = $index + 1;
            $type = $this->columns[$number]["type"] ?? "text";
            [$xml, $text] = $this->typedCell($number, $type, $value, $fill);
            $cells[] = $xml;
            $this->measure($number, $text);
            if ($type === "wrap" && $text !== "") {
                $wrapped[$number] = $text;
            }
        }
        // Wrapped text needs a taller row; the height is worked out once the widths are final.
        $this->rows[$this->rowNumber] = [$cells, $wrapped];
        $this->lastDataRow = $this->rowNumber;
        $this->zebra = !$this->zebra;
    }

    /** Label/value pairs, like the summary's key figures. Each: [label, value, type]. */
    public function facts(array $facts): void
    {
        foreach ($facts as $fact) {
            [$label, $value, $type] = array_pad(array_values($fact), 3, "text");
            $this->rowNumber++;
            $cells = [$this->cellXml(1, (string) $label, $this->styles->id(["font" => "bold", "fill" => "label", "border" => "thin", "valign" => "center"]))];
            [$valueXml, $text] = $this->typedCell(2, (string) $type, $value, null, ["font" => "figure", "align" => "left", "valign" => "center"]);
            $cells[] = $valueXml;
            $this->measure(1, (string) $label);
            $this->measure(2, $text);
            $this->span = max($this->span, 2);
            $this->rows[$this->rowNumber] = $this->rowXml($this->rowNumber, $cells, 21);
        }
    }

    public function filterRange(): ?string
    {
        if (!$this->tableTools || $this->headerRow === 0 || $this->lastDataRow <= $this->headerRow || count($this->columns) === 0) {
            return null;
        }
        return '$A$' . $this->headerRow . ':$' . xlsx_column(count($this->columns)) . '$' . $this->lastDataRow;
    }

    public function printTitleRows(): ?string
    {
        return $this->tableTools && $this->headerRow > 0 ? '$' . $this->headerRow . ':$' . $this->headerRow : null;
    }

    public function xml(): string
    {
        $span = max(2, $this->span, count($this->columns));
        $cols = "";
        $total = 0.0;
        for ($number = 1; $number <= $span; $number++) {
            $width = $this->columnWidth($number);
            $total += $width;
            $cols .= '<col min="' . $number . '" max="' . $number . '" width="' . $width . '" customWidth="1"/>';
        }

        $rows = [];
        foreach ($this->rows as $number => $row) {
            if (is_string($row)) {
                $rows[$number] = $row;
                continue;
            }
            [$cells, $wrapped] = $row;
            $lines = 1;
            foreach ($wrapped as $column => $text) {
                $lines = max($lines, min(8, (int) ceil(mb_strlen($text) * 1.1 / max(1, $this->columnWidth($column) - 1))));
            }
            $rows[$number] = $this->rowXml($number, $cells, $lines > 1 ? 14 * $lines + 3 : null);
        }
        $merges = [];
        foreach ($this->wideRows as $number => [$text, $style, $height]) {
            $id = $this->styles->id($style);
            $cells = [$this->cellXmlAt("A" . $number, $text, $id)];
            for ($column = 2; $column <= $span; $column++) {
                $cells[] = '<c r="' . xlsx_column($column) . $number . '" s="' . $id . '"/>';
            }
            if (!empty($style["wrap"])) {
                $height = max($height, 15 * (int) ceil(mb_strlen($text) * 1.1 / max(1.0, $total)));
            }
            $rows[$number] = $this->rowXml($number, $cells, $height);
            $merges[] = '<mergeCell ref="A' . $number . ':' . xlsx_column($span) . $number . '"/>';
        }
        ksort($rows);

        $pane = $this->tableTools && $this->headerRow > 0 && $this->lastDataRow > $this->headerRow
            ? '<pane ySplit="' . $this->headerRow . '" topLeftCell="A' . ($this->headerRow + 1) . '" activePane="bottomLeft" state="frozen"/>'
                . '<selection pane="bottomLeft" activeCell="A' . ($this->headerRow + 1) . '" sqref="A' . ($this->headerRow + 1) . '"/>'
            : "";
        $filter = $this->filterRange();
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>'
            . '<sheetViews><sheetView workbookViewId="0" showGridLines="0">' . $pane . '</sheetView></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="16"/>'
            . '<cols>' . $cols . '</cols>'
            . '<sheetData>' . implode("", $rows) . '</sheetData>'
            . ($filter ? '<autoFilter ref="' . str_replace("$", "", $filter) . '"/>' : "")
            . ($merges ? '<mergeCells count="' . count($merges) . '">' . implode("", $merges) . '</mergeCells>' : "")
            . '<pageMargins left="0.4" right="0.4" top="0.6" bottom="0.6" header="0.3" footer="0.3"/>'
            . '<pageSetup orientation="landscape" fitToWidth="1" fitToHeight="0"/>'
            . '<headerFooter><oddFooter>' . xlsx_escape("&L&8ISATU Visitor Management&R&8Page &P of &N") . '</oddFooter></headerFooter>'
            . '</worksheet>';
    }

    private function wide(int $number, string $text, array $style, float $height): void
    {
        $this->wideRows[$number] = [$text, $style, $height];
    }

    /** @return array{0: string, 1: string} the cell's XML and its text, for the column width */
    private function typedCell(int $number, string $type, mixed $value, ?string $fill, array $extra = []): array
    {
        $base = $extra + ["border" => "thin", "valign" => "top"] + ($fill ? ["fill" => $fill] : []);
        $ref = xlsx_column($number) . $this->rowNumber;
        if ($type === "status") {
            [$label, $tone] = is_array($value) ? [(string) ($value[0] ?? ""), (string) ($value[1] ?? "muted")] : [(string) $value, "muted"];
            if ($label === "") {
                return [$this->cellXmlAt($ref, "", $this->styles->id($base)), ""];
            }
            $tone = in_array($tone, self::TONES, true) ? $tone : "muted";
            $style = $this->styles->id(["border" => "thin", "valign" => "top", "align" => "center", "font" => "tone_" . $tone, "fill" => "tone_" . $tone]);
            return [$this->cellXmlAt($ref, $label, $style), $label];
        }
        if ($value === null || $value === "") {
            return [$this->cellXmlAt($ref, "", $this->styles->id($base)), ""];
        }
        switch ($type) {
            case "number":
            case "minutes":
                if (is_numeric($value)) {
                    $style = $this->styles->id($base + ["numFmt" => $type === "minutes" ? "minutes" : "integer", "align" => "right"]);
                    $shown = number_format((float) $value) . ($type === "minutes" ? " min" : "");
                    return ['<c r="' . $ref . '" s="' . $style . '"><v>' . (0 + $value) . '</v></c>', $shown];
                }
                break;
            case "datetime":
            case "date":
            case "time":
                $serial = xlsx_serial((string) $value);
                if ($serial !== null) {
                    $style = $this->styles->id($base + ["numFmt" => $type]);
                    $shown = ["datetime" => "Mmm 00, 0000 00:00 AM", "date" => "Mmm 00, 0000", "time" => "00:00:00 AM"][$type];
                    return ['<c r="' . $ref . '" s="' . $style . '"><v>' . $serial . '</v></c>', $shown];
                }
                break;
        }
        $style = $this->styles->id($base + ($type === "wrap" ? ["wrap" => true] : []));
        return [$this->cellXmlAt($ref, (string) $value, $style), (string) $value];
    }

    private function cellXml(int $number, string $text, int $style): string
    {
        return $this->cellXmlAt(xlsx_column($number) . $this->rowNumber, $text, $style);
    }

    private function cellXmlAt(string $ref, string $text, int $style): string
    {
        if ($text === "") {
            return '<c r="' . $ref . '" s="' . $style . '"/>';
        }
        return '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . xlsx_escape($text) . '</t></is></c>';
    }

    private function rowXml(int $number, array $cells, ?float $height): string
    {
        $attributes = $height !== null ? ' ht="' . $height . '" customHeight="1"' : "";
        return '<row r="' . $number . '"' . $attributes . '>' . implode("", $cells) . '</row>';
    }

    private function measure(int $number, string $text): void
    {
        $longest = 0;
        foreach (preg_split('/\R/u', $text) ?: [""] as $line) {
            $longest = max($longest, mb_strlen($line));
        }
        $this->widths[$number] = max($this->widths[$number] ?? 0, $longest);
    }

    private function columnWidth(int $number): float
    {
        $set = $this->columns[$number]["width"] ?? null;
        if ($set !== null) {
            return (float) $set;
        }
        $type = $this->columns[$number]["type"] ?? "text";
        $limit = $type === "wrap" ? 60 : 45;
        return (float) max(10, min($limit, ($this->widths[$number] ?? 8) + 3));
    }
}

/** Fonts, fills, borders and number formats, each registered once and shared by the sheets. */
final class XlsxStyles
{
    private const FONTS = [
        "normal" => '<font><sz val="10"/><color rgb="FF1F2933"/><name val="Calibri"/><family val="2"/></font>',
        "bold" => '<font><b/><sz val="10"/><color rgb="FF1F2933"/><name val="Calibri"/><family val="2"/></font>',
        "header" => '<font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Calibri"/><family val="2"/></font>',
        "title" => '<font><b/><sz val="16"/><color rgb="FF0B3A75"/><name val="Calibri"/><family val="2"/></font>',
        "subtitle" => '<font><i/><sz val="10"/><color rgb="FF5F6B7A"/><name val="Calibri"/><family val="2"/></font>',
        "section" => '<font><b/><sz val="12"/><color rgb="FF0755B5"/><name val="Calibri"/><family val="2"/></font>',
        "figure" => '<font><b/><sz val="12"/><color rgb="FF0B3A75"/><name val="Calibri"/><family val="2"/></font>',
        "tone_good" => '<font><b/><sz val="10"/><color rgb="FF166534"/><name val="Calibri"/><family val="2"/></font>',
        "tone_info" => '<font><b/><sz val="10"/><color rgb="FF1E40AF"/><name val="Calibri"/><family val="2"/></font>',
        "tone_warn" => '<font><b/><sz val="10"/><color rgb="FF92400E"/><name val="Calibri"/><family val="2"/></font>',
        "tone_danger" => '<font><b/><sz val="10"/><color rgb="FF991B1B"/><name val="Calibri"/><family val="2"/></font>',
        "tone_muted" => '<font><b/><sz val="10"/><color rgb="FF475569"/><name val="Calibri"/><family val="2"/></font>',
    ];
    private const FILLS = [
        "header" => "FF0755B5",
        "zebra" => "FFF3F6FA",
        "label" => "FFEAF1FB",
        "tone_good" => "FFDCFCE7",
        "tone_info" => "FFDBEAFE",
        "tone_warn" => "FFFEF3C7",
        "tone_danger" => "FFFEE2E2",
        "tone_muted" => "FFF1F5F9",
    ];
    private const BORDERS = [
        "thin" => '<border><left style="thin"><color rgb="FFD5DCE4"/></left><right style="thin"><color rgb="FFD5DCE4"/></right><top style="thin"><color rgb="FFD5DCE4"/></top><bottom style="thin"><color rgb="FFD5DCE4"/></bottom><diagonal/></border>',
        "underline" => '<border><left/><right/><top/><bottom style="medium"><color rgb="FF0755B5"/></bottom><diagonal/></border>',
    ];
    private const NUMBER_FORMATS = [
        "datetime" => [164, "mmm d, yyyy h:mm AM/PM"],
        "date" => [165, "mmm d, yyyy"],
        "time" => [166, "h:mm:ss AM/PM"],
        "integer" => [3, null],
        "minutes" => [167, '#,##0" min"'],
    ];

    private array $fonts = ["normal" => 0];
    private array $fills = [];
    private array $borders = [];
    /** @var string[] cellXfs entries; 0 is the default */
    private array $formats = ['<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'];
    private array $formatIds = [];

    /** The cell format id for a style such as ["font" => "bold", "fill" => "zebra", "border" => "thin"]. */
    public function id(array $style): int
    {
        ksort($style);
        $key = json_encode($style);
        if (isset($this->formatIds[$key])) {
            return $this->formatIds[$key];
        }
        $font = $this->index($this->fonts, (string) ($style["font"] ?? "normal"), 0);
        $fill = isset($style["fill"]) ? 2 + $this->index($this->fills, (string) $style["fill"], 0) : 0;
        $border = isset($style["border"]) ? 1 + $this->index($this->borders, (string) $style["border"], 0) : 0;
        $numFmt = isset($style["numFmt"]) ? self::NUMBER_FORMATS[$style["numFmt"]][0] : 0;
        $alignment = "";
        $attributes = [];
        if (!empty($style["align"])) {
            $attributes[] = 'horizontal="' . $style["align"] . '"';
        }
        if (!empty($style["valign"])) {
            $attributes[] = 'vertical="' . $style["valign"] . '"';
        }
        if (!empty($style["wrap"])) {
            $attributes[] = 'wrapText="1"';
        }
        if ($attributes) {
            $alignment = '<alignment ' . implode(" ", $attributes) . '/>';
        }
        $this->formats[] = '<xf numFmtId="' . $numFmt . '" fontId="' . $font . '" fillId="' . $fill . '" borderId="' . $border . '" xfId="0"'
            . ($font ? ' applyFont="1"' : "") . ($fill ? ' applyFill="1"' : "") . ($border ? ' applyBorder="1"' : "")
            . ($numFmt ? ' applyNumberFormat="1"' : "") . ($alignment !== "" ? ' applyAlignment="1">' . $alignment . '</xf>' : "/>");
        return $this->formatIds[$key] = count($this->formats) - 1;
    }

    public function xml(): string
    {
        $numFmts = "";
        foreach (self::NUMBER_FORMATS as [$id, $code]) {
            if ($code !== null) {
                $numFmts .= '<numFmt numFmtId="' . $id . '" formatCode="' . xlsx_escape($code) . '"/>';
            }
        }
        $fonts = "";
        foreach (array_keys($this->fonts) as $name) {
            $fonts .= self::FONTS[$name];
        }
        $fills = '<fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>';
        foreach (array_keys($this->fills) as $name) {
            $fills .= '<fill><patternFill patternType="solid"><fgColor rgb="' . self::FILLS[$name] . '"/><bgColor indexed="64"/></patternFill></fill>';
        }
        $borders = '<border><left/><right/><top/><bottom/><diagonal/></border>';
        foreach (array_keys($this->borders) as $name) {
            $borders .= self::BORDERS[$name];
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="' . substr_count($numFmts, "<numFmt ") . '">' . $numFmts . '</numFmts>'
            . '<fonts count="' . count($this->fonts) . '">' . $fonts . '</fonts>'
            . '<fills count="' . (2 + count($this->fills)) . '">' . $fills . '</fills>'
            . '<borders count="' . (1 + count($this->borders)) . '">' . $borders . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="' . count($this->formats) . '">' . implode("", $this->formats) . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '<dxfs count="0"/><tableStyles count="0" defaultTableStyle="TableStyleMedium2" defaultPivotStyle="PivotStyleLight16"/>'
            . '</styleSheet>';
    }

    /** The position of $name in $list, adding it if new; $offset is where the list starts in the file. */
    private function index(array &$list, string $name, int $offset): int
    {
        if (!isset($list[$name])) {
            $list[$name] = count($list);
        }
        return $list[$name] + $offset;
    }
}

/** A minimal ZIP writer (deflate), enough for an .xlsx package. */
final class XlsxZip
{
    private string $data = "";
    private string $directory = "";
    private int $entries = 0;

    public function add(string $path, string $content): void
    {
        $compressed = gzdeflate($content, 6);
        $method = 8;
        if ($compressed === false || strlen($compressed) >= strlen($content)) {
            $compressed = $content;
            $method = 0;
        }
        $crc = crc32($content);
        [$time, $date] = $this->dosTime();
        $offset = strlen($this->data);
        $common = pack("vvvvvVVVvv", 20, 0x0800, $method, $time, $date, $crc, strlen($compressed), strlen($content), strlen($path), 0);
        $this->data .= "PK\x03\x04" . $common . $path . $compressed;
        $this->directory .= "PK\x01\x02" . pack("v", 20) . substr($common, 0, 26) . pack("vvvVV", 0, 0, 0, 0, $offset) . $path;
        $this->entries++;
    }

    public function bytes(): string
    {
        return $this->data . $this->directory
            . "PK\x05\x06" . pack("vvvvVVv", 0, 0, $this->entries, $this->entries, strlen($this->directory), strlen($this->data), 0);
    }

    /** @return array{0: int, 1: int} the current time in the format ZIP files use */
    private function dosTime(): array
    {
        $now = getdate();
        $time = ($now["hours"] << 11) | ($now["minutes"] << 5) | intdiv($now["seconds"], 2);
        $date = ((max(1980, $now["year"]) - 1980) << 9) | ($now["mon"] << 5) | $now["mday"];
        return [$time, $date];
    }
}

/** 1 → "A", 27 → "AA". */
function xlsx_column(int $number): string
{
    $name = "";
    while ($number > 0) {
        $number--;
        $name = chr(65 + $number % 26) . $name;
        $number = intdiv($number, 26);
    }
    return $name;
}

/** Text made safe for XML, without the control characters XML does not allow. */
function xlsx_escape(string $text): string
{
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', "", $text) ?? "";
    return htmlspecialchars($text, ENT_QUOTES | ENT_XML1, "UTF-8");
}

/** "2026-10-13 09:15:00" (or a date, or a time) as an Excel date number; null when it is not one. */
function xlsx_serial(string $value): ?float
{
    $value = trim($value);
    $utc = new DateTimeZone("UTC");
    foreach (["Y-m-d H:i:s", "Y-m-d H:i", "Y-m-d"] as $format) {
        $date = DateTimeImmutable::createFromFormat("!" . $format, $value, $utc);
        if ($date && $date->format($format) === $value) {
            return round($date->getTimestamp() / 86400 + 25569, 8);
        }
    }
    if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $value, $match)) {
        return round(((int) $match[1] * 3600 + (int) $match[2] * 60 + (int) ($match[3] ?? 0)) / 86400, 8);
    }
    return null;
}
