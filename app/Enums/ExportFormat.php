<?php

namespace App\Enums;

/**
 * File formats of the bulk employee export.
 */
enum ExportFormat: string
{
    case Csv = 'csv';
    case Xlsx = 'xlsx';
    case Pdf = 'pdf';

    /**
     * The requested format; anything unknown or non-scalar means CSV (the export's default).
     */
    public static function fromInput(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom(strtolower($value)) ?? self::Csv) : self::Csv;
    }

    public function label(): string
    {
        return match ($this) {
            self::Csv => 'CSV',
            self::Xlsx => 'Excel (XLSX)',
            self::Pdf => 'PDF',
        };
    }

    public function extension(): string
    {
        return $this->value;
    }

    public function mimeType(): string
    {
        return match ($this) {
            self::Csv => 'text/csv',
            self::Xlsx => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            self::Pdf => 'application/pdf',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Csv => 'bi-filetype-csv text-success',
            self::Xlsx => 'bi-file-earmark-excel text-success',
            self::Pdf => 'bi-file-earmark-pdf text-danger',
        };
    }
}
