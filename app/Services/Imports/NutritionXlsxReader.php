<?php

namespace App\Services\Imports;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/** Читає лише скалярні дані XLSX. Формули, DTD та зовнішні relationships заборонені. */
final class NutritionXlsxReader
{
    private const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    public function read(string $path, array $headers): array
    {
        if (! is_file($path) || filesize($path) > 20_000_000 || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'xlsx') {
            throw new RuntimeException('Потрібний локальний XLSX до 20 MB.');
        }
        $hash = hash_file('sha256', $path);
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Не вдалося прочитати XLSX.');
        }
        try {
            $size = 0;
            if ($zip->numFiles > 1500) {
                throw new RuntimeException('Забагато складових XLSX.');
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $size += $stat['size'];
                if ($size > 80_000_000 || preg_match('~vbaProject|^xl/externalLinks/~i', $stat['name'])) {
                    throw new RuntimeException('Макроси, зовнішні посилання або завеликий XLSX не підтримуються.');
                }
            }
            $strings = [];
            if ($zip->locateName('xl/sharedStrings.xml') !== false) {
                foreach ($this->xml($zip, 'xl/sharedStrings.xml')->children(self::NS)->si as $si) {
                    $strings[] = $this->text($si);
                }
            }
            $targets = [];
            foreach ($this->xml($zip, 'xl/_rels/workbook.xml.rels')->children() as $r) {
                $attrs = $r->attributes();
                if ((string) $attrs['TargetMode'] === 'External') {
                    throw new RuntimeException('Зовнішні relationships заборонені.');
                }
                $targets[(string) $attrs['Id']] = (string) $attrs['Target'];
            }
            $sheets = [];
            foreach ($this->xml($zip, 'xl/workbook.xml')->children(self::NS)->sheets->sheet as $sheet) {
                $name = (string) $sheet->attributes()['name'];
                if (str_starts_with($name, '_') && $name !== '_external_refs') {
                    continue;
                }
                if (! isset($headers[$name]) && $name !== '_external_refs') {
                    throw new RuntimeException("Невідомий аркуш імпорту: $name");
                }
                if (isset($sheets[$name])) {
                    throw new RuntimeException("Повторний аркуш: $name");
                }
                $id = (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
                $target = $targets[$id] ?? '';
                $target = str_starts_with($target, '/') ? substr($target, 1) : 'xl/'.$target;
                if (! preg_match('~^xl/worksheets/[a-zA-Z0-9_.-]+\.xml$~D', $target)) {
                    throw new RuntimeException('Неприпустимий шлях аркуша.');
                }
                $expected = $headers[$name] ?? ['entity', 'local_id', 'db_id', 'label'];
                $rows = [];
                $headerSeen = false;
                $seenRows = [];
                foreach ($this->xml($zip, $target)->children(self::NS)->sheetData->row as $row) {
                    $n = (int) $row->attributes()['r'];
                    if ($n < 1 || isset($seenRows[$n])) {
                        throw new RuntimeException('Повторний або неприпустимий номер рядка.');
                    }
                    $seenRows[$n] = true;
                    $values = [];
                    foreach ($row->c as $cell) {
                        $attrs = $cell->attributes();
                        if (isset($cell->f)) {
                            throw new RuntimeException("$name!{$attrs['r']}: формули не імпортуються.");
                        }
                        if ((string) $attrs['t'] === 'e') {
                            throw new RuntimeException("$name!{$attrs['r']}: помилка Excel.");
                        }
                        $address = (string) $attrs['r'];
                        if (! preg_match('/^([A-Z]+)[1-9][0-9]*$/D', $address, $m)) {
                            throw new RuntimeException('Неприпустима адреса клітинки.');
                        }
                        $index = 0;
                        foreach (str_split($m[1]) as $letter) {
                            $index = $index * 26 + ord($letter) - 64;
                        }
                        if (array_key_exists($index - 1, $values)) {
                            throw new RuntimeException('Повторна клітинка.');
                        }
                        $type = (string) $attrs['t'];
                        $value = match ($type) {
                            's' => $strings[(int) $cell->v] ?? throw new RuntimeException('Невідомий shared string.'),
                            'inlineStr' => $this->text($cell->is),
                            'b' => (string) $cell->v === '1' ? 'TRUE' : 'FALSE',
                            default => isset($cell->v) ? (string) $cell->v : '',
                        };
                        $value = trim($value);
                        if (strlen($value) > 32767) {
                            throw new RuntimeException('Завелика клітинка.');
                        }
                        if ($name === '_external_refs' && $index > 4) {
                            continue; // Список дозволених entity у F — довідкова частина.
                        }
                        if ($index > count($expected) && $value !== '') {
                            throw new RuntimeException("$name: зайва колонка.");
                        }
                        $values[$index - 1] = $value === '' ? null : $value;
                    }
                    if (! array_filter($values, fn ($v) => $v !== null)) {
                        continue;
                    }
                    $ordered = array_map(fn ($i) => $values[$i] ?? null, range(0, count($expected) - 1));
                    if ($n === 1) {
                        if ($ordered !== $expected) {
                            throw new RuntimeException("$name: заголовки не відповідають контракту.");
                        }
                        $headerSeen = true;
                    } else {
                        if ($n > 501) {
                            throw new RuntimeException("$name: підтримується до 500 записів.");
                        }
                        $rows[] = array_combine($expected, $ordered) + ['_row' => $n];
                    }
                }
                if (! $headerSeen) {
                    throw new RuntimeException("$name: відсутній рядок заголовків.");
                }
                $sheets[$name] = $rows;
            }
            foreach ($headers as $name => $unused) {
                if (! array_key_exists($name, $sheets)) {
                    throw new RuntimeException("Відсутній аркуш $name.");
                }
            }
            if (! hash_equals($hash, hash_file('sha256', $path))) {
                throw new RuntimeException('Файл змінився під час читання.');
            }

            return ['sha256' => $hash, 'sheets' => $sheets];
        } finally {
            $zip->close();
        }
    }

    private function xml(ZipArchive $zip, string $name): SimpleXMLElement
    {
        $data = $zip->getFromName($name);
        if ($data === false || strlen($data) > 20_000_000 || preg_match('/<!DOCTYPE|<!ENTITY/i', $data)) {
            throw new RuntimeException("Неприпустимий XML: $name");
        }
        $old = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($data, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
            if ($xml === false) {
                throw new RuntimeException("Пошкоджений XML: $name");
            }

            return $xml;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($old);
        }
    }

    private function text(SimpleXMLElement $node): string
    {
        $node->registerXPathNamespace('m', self::NS);

        return implode('', array_map(fn ($t) => (string) $t, $node->xpath('.//m:t')));
    }
}
