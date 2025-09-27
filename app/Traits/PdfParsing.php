<?php

namespace App\Traits;

use App\GeonamesCountry;
use Carbon\Carbon;
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

//    protected function extractCustomer(array $lines): array
//    {
//        return [
//            'side'    => 'Sender',
//            'details' => [
//
//             ,
//                'postal_code'    => $lines[6],
//                'country'        => $this->extractCountry($lines),
//                'telephone'      => $lines[7] ?? null,
//                'Contact'        => $lines[7] ?? null,
//            ],
//        ];
//    }

    private function extractCountry($lines)
    {
        if (preg_match('/\b([A-Z]{2,3})\b/', $lines[0], $m)) {
            $country = $m[1];
        }

        return GeonamesCountry::getIso($country);
    }

    protected function extractComment(array $lines): ?string
    {
        $obsIndex = array_find_key($lines, fn($l) => Str::contains(strtoupper($l), 'OBSERVATIONS'));


        if ($obsIndex !== null) {
            $customsIndex = array_find_key($lines, fn($l) => Str::contains(strtoupper($l), 'CUSTOMS INSTRUCTIONS'));
            $slice        = array_slice($lines, $obsIndex, ($customsIndex ?? count($lines)) - $obsIndex);
            $cleaned      = collect($slice)
                ->map(fn($l) => Str::squish(str_replace('Observations :', '', $l)))
                ->filter()
                ->implode(' ')
            ;
            return $cleaned ?: null;
        }

        //For Booking PDF
        $commentLines = collect($lines)
            ->filter(fn($l) => Str::startsWith(trim($l), '-'))
            ->map(fn($l) => Str::squish(ltrim($l, '-')))
            ->implode(' ')
        ;

        if (empty($commentLines)) {
            return null;
        }

        return $commentLines;
    }

//    protected function extractCargos(array $lines): array
//    {
//        $titleLine  = collect($lines)->first(fn($l) => Str::contains(strtoupper($l),
//                                                                     ['ROLLS', 'PALLET', 'PACKAGE', 'CARGO']));
//        $weightLine = collect($lines)->first(fn($l) => preg_match('/\d{3,}\.?(\d+)?/', $l) && Str::contains($l, 'Kgs'));
//        $weight     = $weightLine ? (float) preg_replace('/[^0-9.]/', '', $weightLine) : null;
//
//        return [
//            [
//                'title'         => $titleLine ?? 'Cargo',
//                'package_count' => 1,
//                'package_type'  => 'pallet',
//                'number'        => $this->extractOrderReference($lines),
//                'type'          => 'FTL',
//                'weight'        => $weight,
//            ],
//        ];
//    }

//    private function extractFreightPrice(array $lines): ?float
//    {
//        $line = collect($lines)->first(fn($l) => preg_match('/\d+,\d+/', $l));
//        return $line ? (float) str_replace(',', '.', preg_replace('/[^0-9,]/', '', $line)) : null;
//    }
//
//    private function detectCurrency(array $lines): string
//    {
//        $line = collect($lines)->first(fn($l) => Str::contains(strtoupper($l), ['EUR', 'USD', 'GBP', 'PLN', 'ZAR']));
//        if ($line) {
//            foreach (['EUR', 'USD', 'GBP', 'PLN', 'ZAR'] as $c) {
//                if (Str::contains($line, $c)) {
//                    return $c;
//                }
//            }
//        }
//        return 'EUR';
//    }

//    private function extractLocations(array $lines, string $section): array
//    {
//        $results      = [];
//        $sectionIndex = array_find_key($lines, fn($l) => Str::contains(strtoupper($l), strtoupper($section)));
//        if (!$sectionIndex) {
//            return [];
//        }
//
//        // Grab ~20 lines after "Loading" or "Delivery"
//        $slice = array_slice($lines, $sectionIndex, 20);
//
//        // Date
//        $dateLine = collect($slice)->first(fn($l) => preg_match('/\d{2}\/\d{2}\/\d{2}/', $l));
//        $date     = $dateLine ? $this->parseDate($dateLine) : null;
//
//        // Address (grab block of uppercase + postal code)
//        $address     = collect($slice)->filter(fn($l) => !empty(trim($l)) && strlen($l) > 3)->values();
//        $company     = $address[2] ?? null;
//        $street      = $address[3] ?? null;
//        $postalBlock = collect($address)->first(fn($l) => preg_match('/[A-Z]{2}-?\d{2,}/', $l));
//        $postalCode  = $postalBlock ? preg_replace('/[^0-9]/', '', $postalBlock) : null;
//        $country     = $postalBlock ? substr($postalBlock, 0, 2) : null;
//        $city        = $postalBlock ? trim(Str::after($postalBlock, $country.'-')) : null;
//
//        $results[] = [
//            'company_address' => [
//                'company'        => $company,
//                'street_address' => $street,
//                'city'           => $city,
//                'country'        => $country,
//                'postal_code'    => $postalCode,
//            ],
//            'time'            => [
//                'datetime_from' => $date ? $date->startOfDay()->toIso8601String() : null,
//                'datetime_to'   => $date ? $date->endOfDay()->toIso8601String() : null,
//            ],
//        ];
//
//        return $results;
//    }
}