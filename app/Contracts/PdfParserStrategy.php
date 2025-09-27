<?php

namespace App\Contracts;

interface PdfParserStrategy
{
    public function canHandle(array $lines): bool;

    public function parse(array $lines, ?string $file): array;
}