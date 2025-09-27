<?php

namespace App\Strategy;

use App\Contracts\PdfParserStrategy;
use App\DTO\TransportOrderData;
use App\Services\TransportOrderBuilder;
use App\Traits\PdfParsing;
use Illuminate\Support\Str;


class ZieglerParser implements PdfParserStrategy
{
    use PdfParsing;

    protected array $sectionConfig;

    public function __construct()
    {
        $this->sectionConfig = [
            'Collection' => [
                'time'            => 'collection',
                'companyFinder'   => 'findCompany',
                'streetFinder'    => 'findStreet',
                'postalFinder'    => 'findPostalCity',
                'palletFinder'    => 'findPalletLine',
                'postalRegex'     => '/([A-Z0-9]{2,}\s?\d+[A-Z]*)\s+(.+)/',
                'countryStrategy' => 'prefix',
            ],
            'Delivery' => [
                'time'            => 'delivery',
                'companyFinder'   => 'findCompany',
                'streetFinder'    => 'findStreet',
                'postalFinder'    => 'findPostalCity',
                'palletFinder'    => 'findPalletLine',
                'postalRegex'     => '/(\d{4,5})\s+(.+)/',
                'countryStrategy' => 'FR',
            ],
        ];
    }

    public function canHandle(array $lines): bool
    {
        return $this->isValidated($lines);
    }

    public function parse(array $lines, ?string $file): array
    {
        $lines = array_values(array_filter(array_map('trim', $lines)));

        $price = $this->extractPrice($lines);

        $loadingLocations     = [];
        $destinationLocations = [];
        $cargos               = [];

        foreach ($this->sectionConfig as $type => $conf) {
            foreach (array_keys($lines, $type) as $idx) {
                [$loc, $cargo] = $this->parseSection($lines, $idx, $type, $conf);

                if ($type === 'Collection') {
                    $loadingLocations[] = $loc;
                } else {
                    $destinationLocations[] = $loc;
                }

                if ($cargo) $cargos[] = $cargo;
            }
        }

        $builder = (new TransportOrderBuilder(new TransportOrderData()))
            ->withAttachments($this->getFileName($file))
            ->withCustomer($this->extractCustomer($lines))
            ->withOrderReference($this->extractOrderReference($lines))
            ->withFreight($price, 'EUR')
            ->withComment($this->extractComment($lines));

        if (!empty($loadingLocations)) {
            $builder->withLoadingLocations($loadingLocations);
        }
        if (!empty($destinationLocations)) {
            $builder->withDestinationLocations($destinationLocations);
        }
        if (!empty($cargos)) {
            $builder->withCargos($cargos);
        }

        return $builder->build();
    }

    private function parseSection(array $lines, int $idx, string $type, array $conf): array
    {
        $company = $this->{$conf['companyFinder']}($lines, $idx);
        $street = $this->{$conf['streetFinder']}($lines, $idx);
        $postalLine = $this->{$conf['postalFinder']}($lines, $idx);
        [$postalCode, $city, $country] = $this->parsePostal($postalLine, $conf);
        $time = $this->parseTime($lines, $idx, $type);

        $location = [
            'company_address' => array_filter([
                                                  'company'        => $company,
                                                  'street_address' => $street ?? $postalLine,
                                                  'city'           => $city,
                                                  'postal_code'    => $postalCode,
                                                  'country'        => $country,
                                              ]),
        ];
        if ($time) {
            $location['time'] = $time;
        }


        $palletLine = $this->{$conf['palletFinder']}($lines, $idx);
        $cargo = $this->parseCargo($palletLine, $lines, $idx);

        return [$location, $cargo];
    }

    protected function findNextTimeAndDate(array $lines, int $start): array
    {
        $time = null;
        $date = null;

        for ($i = $start; $i < count($lines); $i++) {
            $line = trim($lines[$i]);

            if (in_array($line, ['Collection', 'Delivery'])) {
                break;
            }

            if (preg_match('/^(\d{4}|\d{1,2}(:\d{2})?\s?(am|pm)?)(\s*-\s*\d{1,2}(:\d{2})?\s?(am|pm)?)?$/i', $line)) {
                $time = $line;
            }

            if (preg_match('/^\d{1,2}\/\d{1,2}\/\d{2,4}$/', $line)) {
                $date = $line;
            }

            if ($time && $date) {
                break;
            }
        }

        return [$time, $date];
    }

    private function parseTime(array $lines, int $idx, string $type): array
    {
        $fromRaw = $toRaw = $dateRaw = $dateLine = null;

        if ($type === 'Collection') {
            [$timeRaw, $dateRaw] = $this->findNextTimeAndDate($lines, $idx + 1);
            [$fromRaw, $toRaw]   = array_pad(explode('-', $timeRaw ?? ''), 2, null);
        }

        if ($type === 'Delivery') {
            $timeLine = $this->findTimeLine($lines, $idx);
            $dateLine = $this->findDateLine($lines, $idx);
            [$fromRaw, $toRaw] = array_pad($this->extractTimes($timeLine), 2, null);
        }

        $from = $fromRaw ? $this->normalizeSingleTime($dateRaw ?? $dateLine, $fromRaw) : null;
        $to   = $toRaw ? $this->normalizeSingleTime($dateRaw ?? $dateLine, $toRaw) : null;

        return array_filter([
                                'datetime_from' => $from,
                                'datetime_to'   => $to,
                            ]);
    }

    private function parsePostal(?string $line, array $conf): array
    {
        if (!$line || !preg_match($conf['postalRegex'], $line, $m)) {
            return [null, null, null];
        }

        $postalCode = $m[1];
        $city       = $m[2];
        $country    = $this->resolveCountry($conf['countryStrategy'], $postalCode);

        return [$postalCode, $city, $country];
    }

    private function parseCargo(?string $line, array $lines, int $idx): ?array
    {
        if (!$line) return null;

        if (preg_match('/(\d+)\s*PALLETS?/i', $line, $m)) {
            return array_filter([
                'title'         => $line,
                'package_count' => (int) $m[1],
                'package_type'  => 'pallet',
                'number'        => $this->findRefNearby($lines, $idx),
            ]);
        }

        return null;
    }

    private function resolveCountry(string $strategy, ?string $postal): ?string
    {
        return match ($strategy) {
            'prefix' => $postal ? substr($postal, 0, 2) : null,
            'FR'     => 'FR',
            default  => null,
        };
    }

    private function findCompany(array $lines, int $idx, int $lookahead = 5): ?string
    {
        for ($i = 1; $i <= $lookahead; $i++) {
            $line = $lines[$idx + $i] ?? null;
            if ($line && !preg_match('/^(REF|[0-9]{1,2}[:\-])/', $line)) {
                return $line;
            }
        }
        return null;
    }

    private function findStreet(array $lines, int $idx, int $lookahead = 12): ?string
    {
        for ($i = 1; $i <= $lookahead; $i++) {
            $line = $lines[$idx + $i] ?? null;
            if ($line && !preg_match('/^(REF|[0-9]{1,2}[:\-])/', $line)) {
                return $line;
            }
        }
        return null;
    }

    private function findPostalCity(array $lines, int $idx, int $lookahead = 12): ?string
    {
        for ($i = 1; $i <= $lookahead; $i++) {
            $line = $lines[$idx + $i] ?? null;
            if ($line && preg_match('/(\d{4,5}|[A-Z0-9]{2,}\s?\d+[A-Z]*)\s+.+/', $line)) {
                return $line;
            }
        }
        return null;
    }

    private function findPalletLine(array $lines, int $idx, int $lookahead = 12): ?string
    {
        for ($i = 1; $i <= $lookahead; $i++) {
            $line = $lines[$idx + $i] ?? null;
            if ($line && preg_match('/\d+\s*PALLETS?/i', $line)) {
                return $line;
            }
        }
        return null;
    }

    private function findTimeLine(array $lines, int $idx, int $lookahead = 6): ?string
    {
        for ($i = 1; $i <= $lookahead; $i++) {
            $line = $lines[$idx + $i] ?? null;
            if ($line && preg_match('/\d{1,2}[:.]?\d{2}(\s*-\s*\d{1,2}[:.]?\d{2})?/i', $line)) {
                return $line;
            }
        }
        return null;
    }

    private function findDateLine(array $lines, int $idx, int $lookahead = 6): ?string
    {
        for ($i = 1; $i <= $lookahead; $i++) {
            $line = $lines[$idx + $i] ?? null;
            if ($line && preg_match('/\d{2}\/\d{2}\/\d{4}/', $line)) {
                return $line;
            }
        }
        return null;
    }

    private function extractPrice(array $lines): ?float
    {
        $rateIndex = array_search('Rate', $lines);
        $priceLine = $rateIndex !== false ? $lines[$rateIndex + 2] ?? null : null;

        return $priceLine
            ? (float) str_replace(['€', ',', ' '], ['', '', ''], $priceLine)
            : null;
    }


    private function extractComments(array $lines): ?string
    {
        return collect($lines)
            ->filter(fn($l) => Str::startsWith(trim($l), '-'))
            ->map(fn($l) => ltrim($l, '- '))
            ->implode(' ')
        ;
    }

    protected function extractCustomer(array $lines): array
    {
        $company     = $this->getCompanyName($lines[0]);
        $streetParts = [];

        foreach ($lines as $line) {
            if (preg_match('/^[A-Z ]+$/', $line) && !Str::contains($line, ['BOOKING', 'INSTRUCTION'])) {
                $city = $line;
                break;
            }
            if ($line !== $company) {
                $streetParts[] = $line;
            }
        }
        $street     = implode(', ', $streetParts);
        $postalLine = collect($lines)->first(fn($l) => preg_match('/[A-Z]{1,2}\d{1,2}[A-Z]?\s?\d[A-Z]{2}/i', $l));
        $postalCode = $postalLine ?? null;
        $country    = $postalCode ? 'GB' : null;

        return [
            'side'    => $this->getSideName('sender'),
            'details' => [
                'company'        => $company,
                'street_address' => $this->getStreetAddress($lines),
                'postal_code'    => $postalCode,
                'city'           => $city ?? null,
                'country'        => $country,
            ],
        ];
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

}