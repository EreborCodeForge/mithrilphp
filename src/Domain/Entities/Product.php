<?php

declare(strict_types=1);

namespace App\Domain\Entities;

use App\Common\Money\Money;

class Product
{
    public function __construct(
        public ?int $id,
        public string $name,
        public Money $price,
        public int $quantity,
        public ?string $description = null,
        public ?string $imagePath = null
    ) {}
    
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'price' => $this->price->toFloat(), // For API JSON response, often float is expected or object
            'price_formatted' => $this->price->format(),
            'quantity' => $this->quantity,
            'description' => $this->description,
            'image_path' => $this->imagePath,
        ];
    }
}
