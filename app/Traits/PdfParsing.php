<?php

namespace App\Traits;

use App\GeonamesCountry;
use Illuminate\Support\Str;

trait PdfParsing
{
    protected function isValidated(array $lines): bool
    {
        $keywords = config('pdf_parsers.'.class_basename($this)) ?? [];
        $haystack = collect($lines)->map(fn($l) => strtoupper($l));

        foreach ($keywords as $keyword) {
            if ($haystack->contains(fn($l) => Str::contains($l, strtoupper($keyword)))) {
                return true;
            }
        }

        return false;
    }

    protected function getFileName(?string $file = null): array
    {
        return [mb_strtolower($file ?? 'unknown.pdf')];
    }

    protected function extractOrderReference(array $lines): ?string
    {
        $patterns = [
            'REF.:'       => fn($line, $lines, $i) => trim(Str::after($line, 'REF.:')),
            'Ziegler Ref' => fn($line, $lines, $i) => trim($lines[$i + 1] ?? ''),
            'REFERENCE :' => fn($line, $lines, $i) => trim($lines[$i + 1] ?? ''),
        ];

        foreach ($lines as $i => $line) {
            foreach ($patterns as $keyword => $extractor) {
                if (Str::contains($line, $keyword)) {
                    return $extractor($line, $lines, $i);
                }
            }
        }

        return null;
    }

    public function normalizeSingleTime(string $date, string $time): ?string
    {
        $time = strtolower(trim($time));
        $time = preg_replace('/(\d)(am|pm)/i', '$1 $2', $time);
        $formats = [
            '/^\d{4}$/'              => 'd/m/Y Hi',   // 0900
            '/^\d{1,2}\s?(am|pm)$/i' => 'd/m/Y g a', // 2 pm
            '/^\d{1,2}:\d{2}$/'      => 'd/m/Y H:i', // 09:00
        ];

        foreach ($formats as $regex => $format) {
            if (preg_match($regex, $time)) {
                return \Illuminate\Support\Carbon::createFromFormat($format, $date . ' ' . $time)->toISOString();
            }
        }

        return null;
    }

    protected function getSideName(string $name): string
    {
        return $name ?? 'unknown';
   }

    protected function getCompanyName(string $line = null): ?string
    {
        return   $line ?? null;
   }

    protected function getStreetAddress(array $lines = null): ?string
    {
        return implode(', ', array_slice($lines, 1, 3)) ?? null;
   }

    protected function extractCountry($lines)
    {
        if (preg_match('/\b([A-Z]{2,3})\b/', $lines[0], $m)) {
            $country = $m[1];
        }

        return GeonamesCountry::getIso($country);
    }

    protected function extractComment(array $lines): ?string
    {
        if (($obsIndex = array_find_key($lines, fn($l) => Str::contains(strtoupper($l), 'OBSERVATIONS'))) !== null) {
            $customsIndex = array_find_key($lines, fn($l) => Str::contains(strtoupper($l), 'CUSTOMS INSTRUCTIONS'));
            return collect(array_slice($lines, $obsIndex, ($customsIndex ?? count($lines)) - $obsIndex))
                ->map(fn($l) => Str::squish(str_replace('Observations :', '', $l)))
                ->filter()
                ->implode(' ') ?: null;
        }

        return collect($lines)
            ->filter(fn($l) => Str::startsWith(trim($l), '-'))
            ->map(fn($l) => Str::squish(ltrim($l, '-')))
            ->implode(' ') ?: null;
    }

    protected function extractPrice(array $lines): ?float
    {
        $checks = [
            'SHIPPING PRICE' => [1, [',', ' '], ['.', '']],
            'Rate'           => [1, ['€', ',', ' '], ['', '', '']],
        ];

        foreach ($checks as $label => [$offset, $search, $replace]) {
            if (($i = array_search($label, $lines)) !== false) {
                $priceLine = $lines[$i + $offset] ?? '';
                if ($priceLine !== '') {
                    return (float) str_replace($search, $replace, $priceLine);
                }
            }
        }

        return null;
    }

    protected function findRefNearby(array $lines, int $idx): ?string
    {
        for ($j = $idx; $j < $idx + 8; $j++) {
            if (isset($lines[$j]) && Str::contains(Str::upper($lines[$j]), 'REF')) {
                if (preg_match('/REF[\s:]*([A-Z0-9\-]+)/i', $lines[$j], $m)) {
                    return $m[1];
                }
            }
        }
        return null;
    }

    protected function extractTimes(?string $line): array
    {
        if (!$line) {
            return [null, null];
        }
        preg_match_all('/(\d{1,2}:\d{2})/', $line, $matches);
        return $matches[1] ?? [null, null];
    }

    protected function findTimeLine(array $lines, int $idx, int $lookahead = 6): ?string
    {
        for ($i = 1; $i <= $lookahead; $i++) {
            $line = $lines[$idx + $i] ?? null;
            if ($line && preg_match('/\d{1,2}[:.]?\d{2}/i', $line)) {
                return $line;
            }
        }
        return null;
    }

    protected function findDateLine(array $lines, int $idx, int $lookahead = 6): ?string
    {
        for ($i = 1; $i <= $lookahead; $i++) {
            $line = $lines[$idx + $i] ?? null;
            if ($line && preg_match('/\d{2}\/\d{2}\/\d{2,4}/', $line)) {
                return $line;
            }
        }
        return null;
    }
}