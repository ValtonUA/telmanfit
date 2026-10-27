<?php

namespace Tests\Unit;

use App\Services\Imports\NutritionXlsxReader;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

class NutritionXlsxReaderTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir().'/nutrition-reader-'.bin2hex(random_bytes(8)).'.xlsx';
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }
    }

    private function archive(string $cell, bool $external = false): void
    {
        $zip = new ZipArchive;
        $zip->open($this->file, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="uoms" r:id="r1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="r1" Target="worksheets/sheet1.xml"'.($external ? ' TargetMode="External"' : '').'/></Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>id</t></is></c></row><row r="2">'.$cell.'</row></sheetData></worksheet>');
        $zip->close();
    }

    public function test_text_ids_and_zero_are_not_coerced_to_missing_values(): void
    {
        $this->archive('<c r="A2" t="inlineStr"><is><t>001</t></is></c>');
        $reader = new NutritionXlsxReader;
        $this->assertSame('001', $reader->read($this->file, ['uoms' => ['id']])['sheets']['uoms'][0]['id']);
        $this->archive('<c r="A2"><v>0</v></c>');
        $this->assertSame('0', $reader->read($this->file, ['uoms' => ['id']])['sheets']['uoms'][0]['id']);
    }

    public function test_formula_with_cached_value_is_rejected(): void
    {
        $this->archive('<c r="A2"><f>1+1</f><v>2</v></c>');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('формули');
        (new NutritionXlsxReader)->read($this->file, ['uoms' => ['id']]);
    }

    public function test_external_relationship_is_rejected(): void
    {
        $this->archive('<c r="A2"><v>1</v></c>', true);
        $this->expectExceptionMessage('Зовнішні relationships');
        (new NutritionXlsxReader)->read($this->file, ['uoms' => ['id']]);
    }
}
