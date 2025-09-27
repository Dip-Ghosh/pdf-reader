<?php

namespace App\DTO;

class TransportOrderData
{
    public array   $attachments          = [];
    public array   $customer             = [];
    public array   $loadingLocations     = [];
    public array   $destinationLocations = [];
    public array   $cargos               = [];
    public ?string $orderReference       = null;
    public ?float  $freightPrice         = null;
    public ?string $freightCurrency      = null;
    public ?string $comment              = null;

    public function toArray(): array
    {
        return [
            'attachment_filenames'  => $this->attachments,
            'customer'              => $this->customer,
            'loading_locations'     => $this->loadingLocations,
            'destination_locations' => $this->destinationLocations,
            'cargos'                => $this->cargos,
            'order_reference'       => $this->orderReference,
            'freight_price'         => $this->freightPrice,
            'freight_currency'      => $this->freightCurrency,
            'comment'               => $this->comment,
        ];
    }
}
