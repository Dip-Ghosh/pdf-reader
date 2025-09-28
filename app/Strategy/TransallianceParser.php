<?php

namespace App\Strategy;

use App\Contracts\PdfParserStrategy;
use App\DTO\TransportOrderData;
use App\Services\TransportOrderBuilder;
use App\Traits\PdfParsing;
use Illuminate\Support\Str;

class TransallianceParser implements PdfParserStrategy
{
    use PdfParsing;

    protected array $sectionConfig;

    public function __construct()
    {
        $this->sectionConfig = [
            'Loading'  => [
                'time'            => 'loading',
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
        $lines                = array_values(array_filter(array_map('trim', $lines)));
        $loadingLocations     = [];
        $destinationLocations = [];
        $cargos               = [];

        foreach ($this->sectionConfig as $type => $conf) {
            foreach (array_keys($lines, $type) as $idx) {
                [$loc, $cargo] = $this->parseSection($lines, $idx, $type, $conf);

                if ($type === 'Loading') {
                    $loadingLocations[] = $loc;
                } else {
                    $destinationLocations[] = $loc;
                }

                if ($cargo) {
                    $cargos[] = $cargo;
                }
            }
        }

        return (new TransportOrderBuilder(new TransportOrderData()))
            ->withAttachments($this->getFileName($file))
            ->withCustomer($this->extractCustomer($lines))
            ->withOrderReference($this->extractOrderReference($lines))
            ->withFreight(uncomma($this->extractPrice($lines)), 'EUR')
            ->withComment($this->extractComment($lines))
            ->withLoadingLocations($loadingLocations)
            ->withDestinationLocations($destinationLocations)
            ->withCargos($cargos)
            ->build();
    }

    private function parseSection(array $lines, int $idx, string $type, array $conf): array
    {
        $company    = $this->findCompany($lines, $idx);
        $street     = $this->findStreet($lines, $idx);
        $postalLine = $this->findPostalCity($lines, $idx);
        [$postalCode, $city, $country] = $this->parsePostal($postalLine, $conf);
        $time = $this->parseTime($lines, $idx, $type);

        $location = [
            'company_address' => array_filter([
                                                  'company'        => $company,
                                                  'street_address' => $street,
                                                  'city'           => $city,
                                                  'postal_code'    => $postalCode,
                                                  'country'        => $country,
                                              ]),
        ];

        if ($time) {
            $location['time'] = $time;
        }

        $cargoBlock = $this->findCargoBlock($lines, $idx);
        $cargo      = $this->parseCargo($cargoBlock, $lines);

        return [$location, $cargo];
    }

    private function parseTime(array $lines, int $idx, string $type): array
    {
        $fromRaw = $toRaw = $dateRaw = $dateLine = null;

        if ($type === 'Loading') {
            [$timeRaw, $dateRaw] = $this->findNextTimeAndDate($lines, $idx + 1);
            [$fromRaw, $toRaw] = array_pad(explode('-', $timeRaw ?? ''), 2, null);
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

    private function resolveCountry(string $strategy, ?string $postal): ?string
    {
        return match ($strategy) {
            'prefix' => $postal ? substr($postal, 0, 2) : null,
            'FR'     => 'FR',
            default  => null,
        };
    }

    private function findNextTimeAndDate(array $lines, int $start): array
    {
        $time = null;
        $date = null;

        for ($i = $start; $i < count($lines); $i++) {
            $line = trim($lines[$i]);

            if (in_array($line, ['Collection', 'Delivery'])) {
                break;
            }

            if (preg_match('/\d{1,2}[:.]\d{2}/', $line)) {
                $time = $line;
            }

            if (preg_match('/\d{2}\/\d{2}\/\d{2,4}/', $line)) {
                $date = $line;
            }

            if ($time && $date) {
                break;
            }
        }

        return [$time, $date];
    }

    private function findCompany(array $lines, int $idx, int $lookahead = 12): ?string
    {
        for ($i = 1; $i <= $lookahead; $i++) {
            $line = trim($lines[$idx + $i] ?? '');

            if ($line === '' || $line === '-' ||
                preg_match('/^(REFERENCE|REF|ON|Contact|Payment terms)/i', $line) ||
                preg_match('/^\d{1,2}\/\d{1,2}\/\d{2,4}$/', $line) || str_contains(strtoupper($line), 'VIREMENT')) {
                continue;
            }

            return $line;
        }
        return null;
    }

    private function findStreet(array $lines, int $idx, int $lookahead = 12): ?string
    {
        $streetParts  = [];
        $companyFound = false;

        for ($i = 1; $i <= $lookahead; $i++) {
            $line = trim($lines[$idx + $i] ?? '');

            if ($line === '' || $line === '-') {
                continue;
            }

            if (preg_match('/^(REFERENCE|REF|ON|Contact|Payment terms)/i', $line)) {
                continue;
            }

            if (preg_match('/^\d{1,2}\/\d{1,2}\/\d{2,4}$/', $line)) {
                continue;
            }

            if (preg_match('/^(?:[A-Z]{2}-\S+|-\d{4,5}\s+.+|\d{4,5}\s+.+)/', $line)) {
                break;
            }


            if (!$companyFound) {
                $companyFound = true;
                continue;
            }

            $streetParts[] = $line;
        }

        return $streetParts ? implode(', ', $streetParts) : null;
    }

    private function findPostalCity(array $lines, int $idx, int $lookahead = 12): ?string
    {
        for ($i = 1; $i <= $lookahead; $i++) {
            $line = trim($lines[$idx + $i] ?? '');

            if ($line === '' || $line === '-' || preg_match('/^(REFERENCE|REF|ON|Contact|Payment terms)/i', $line)) {
                continue;
            }

            if (preg_match('/^-?[A-Z]{2}-\S+|^-?\d{4,5}\s+.+/i', $line)) {
                return ltrim($line, '-');
            }
        }
        return null;
    }

    protected function extractCustomer(array $lines): array
    {
        $clientIndex  = collect($lines)->search(fn($l) => Str::contains($l, 'Test Client'));
        $carrierIndex = collect($lines)->search(fn($l) => Str::contains($l, 'TRANSALLIANCE'));
        $company      = null;
        $streetParts  = [];
        $postalCode   = null;
        $city         = null;
        $country      = null;

        if ($clientIndex !== false && $carrierIndex !== false && $clientIndex < $carrierIndex) {
            $company = $lines[$clientIndex];

            for ($i = $clientIndex + 1; $i < $carrierIndex; $i++) {
                $line = trim($lines[$i]);

                if ($line === '' || preg_match('/^(VAT NUM|Contact|Tel|E-mail)/i', $line)) {
                    break;
                }

                $streetParts[] = $line;

                if (preg_match('/^([A-Z]{2}-\S+)\s+(.+)/', $line, $m)) {
                    $postalCode = $m[1];
                    $city       = $m[2];
                    $country    = substr($postalCode, 0, 2);
                }
            }
        }

        return [
            'side'    => $this->getSideName('sender'),
            'details' => array_filter(
                [
                    'company'        => $company,
                    'street_address' => implode(', ', $streetParts),
                    'postal_code'    => $postalCode,
                    'city'           => trim($city ?? ''),
                    'country'        => $country,
                ]),
        ];
    }

    private function findCargoBlock(array $lines, int $idx, int $lookahead = 40): ?array
    {
        for ($i = 1; $i <= $lookahead; $i++) {
            $line = trim($lines[$idx + $i] ?? '');

            if (!$line) {
                continue;
            }

            if (preg_match('/(PACKAGING|PAPER ROLLS)/i', $line)) {
                $pos    = $idx + $i;
                $weight = $this->findNearbyRefNumber($lines, $pos, 6, 'weight');
                $volume = $this->findNearbyRefNumber($lines, $pos, 6, 'volume');

                return [
                    'title'  => $line,
                    'type'   => 'other',
                    'count'  => null,
                    'weight' => $weight,
                    'volume' => $volume,
                    'line'   => $pos,
                ];
            }
        }
        return null;
    }

    private function parseCargo(?array $cargoBlock, array $lines): ?array
    {
        if (!$cargoBlock) {
            return null;
        }

        return array_filter([
                                'title'         => $cargoBlock['title'],
                                'package_count' => $cargoBlock['count'],
                                'package_type'  => $cargoBlock['type'],
                                'weight'        => $cargoBlock['weight'],
                                'volume'        => $cargoBlock['volume'],
                                'number'        => (string) $this->findNearbyRefNumber($lines, $cargoBlock['line']),
                            ]);
    }

    private function findNearbyRefNumber(array $lines, int $idx, int $lookaround = 3, string $type = ''): ?float
    {
        for ($i = max(0, $idx - $lookaround); $i <= $idx + $lookaround; $i++) {
            $line = trim($lines[$i] ?? '');
            if (!$line) {
                continue;
            }

            if ($type === 'weight' && preg_match('/^\d{1,3}(?:,\d{3})*$/', $line)) {
                return (float) str_replace(',', '.', str_replace('.', '', $line));
            }

            if ($type === 'volume' && preg_match('/^\d{4,}(?:[.,]\d+)?$/', $line)) {
                return (float) str_replace(',', '.', $line);
            }

            if ($type === '' && preg_match('/^[\d.,]+$/', $line)) {
                return (float) str_replace(',', '.', $line);
            }
        }
        return null;
    }
}
