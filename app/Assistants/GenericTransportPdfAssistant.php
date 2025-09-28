<?php

namespace App\Assistants;

use App\Strategy\TransallianceParser;
use App\Strategy\ZieglerParser;

class GenericTransportPdfAssistant extends PdfClient
{
    protected array $parsers;

    public function __construct()
    {
        $this->parsers = [
            new ZieglerParser(),
            new TransallianceParser(),
        ];
    }

    public static function validateFormat(array $lines)
    {
        return !empty($lines);
    }

    public function processLines(array $lines, ?string $attachment_filename = null)
    {
        foreach ($this->parsers as $parser) {

            if ($parser->canHandle($lines)) {
                $data = $parser->parse($lines, $attachment_filename);

                return $this->createOrder($data);
            }
        }

        throw new \RuntimeException("No matching parser found for file: " . ($attachment_filename ?? 'unknown'));
    }
}