<?php

declare(strict_types=1);

namespace App\Application\DTOs\Product;

use App\Application\DTOs\DTOInterface;
use App\Core\Http\UploadedFile;

readonly class UpdateProductDTO implements DTOInterface
{
    public function __construct(
        public ?string $name,
        public ?string $price,
        public ?int $quantity,
        public ?string $description,
        public ?string $imagePath = null,
        public ?UploadedFile $image = null
    ) {}

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'price' => $this->price,
            'quantity' => $this->quantity,
            'description' => $this->description,
            'imagePath' => $this->imagePath,
            'image' => $this->image
        ];
    }
}
