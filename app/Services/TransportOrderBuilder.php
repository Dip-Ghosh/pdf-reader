<?php

namespace App\Services;

use App\DTO\TransportOrderData;

class TransportOrderBuilder
{
    public function __construct(protected TransportOrderData $dto) {}

    public function withAttachments(array $attachments): self
    {
        $this->dto->attachments = $attachments;
        return $this;
    }

    public function withCustomer(array $customer): self
    {
        $this->dto->customer = $customer;
        return $this;
    }

    public function withLoadingLocations(array $locations): self
    {
        $this->dto->loadingLocations = $locations;
        return $this;
    }

    public function withDestinationLocations(array $locations): self
    {
        $this->dto->destinationLocations = $locations;
        return $this;
    }

    public function withCargos(array $cargos): self
    {
        $this->dto->cargos = $cargos;
        return $this;
    }

    public function withOrderReference(?string $ref): self
    {
        $this->dto->orderReference = $ref;
        return $this;
    }

    public function withFreight(?float $price, ?string $currency): self
    {
        $this->dto->freightPrice    = $price;
        $this->dto->freightCurrency = $currency;
        return $this;
    }

    public function withComment(?string $comment): self
    {
        $this->dto->comment = $comment;
        return $this;
    }

    public function when($value, callable $callback)
    {
        if (!empty($value)) {
            $callback($this);
        }
        return $this;
    }

    public function build(): array
    {
        return $this->dto->toArray();
    }
}
