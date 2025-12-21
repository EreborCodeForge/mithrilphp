<?php

declare(strict_types=1);

namespace App\Application\UseCases\Product;

use App\Application\DTOs\Product\CreateProductDTO;
use App\Common\Money\Currency;
use App\Common\Money\Money;
use App\Domain\Entities\Product;
use App\Domain\Repositories\ProductRepositoryInterface;
use App\Domain\Services\StorageServiceInterface;

class CreateProductUseCase
{
    private ProductRepositoryInterface $repository;
    private StorageServiceInterface $storageService;

    public function __construct(
        ProductRepositoryInterface $repository,
        StorageServiceInterface $storageService
    ) {
        $this->repository = $repository;
        $this->storageService = $storageService;
    }

    public function execute(CreateProductDTO $dto): array
    {
        // Domain Logic: Convert string price to Money Value Object
        // Assuming BRL as default currency for now. 
        // In a real app, currency might come from DTO or User Context.
        $price = Money::fromString($dto->price, Currency::BRL()->getCode());

        $imagePath = $dto->imagePath;

        if ($dto->image) {
            $imagePath = $this->storageService->store($dto->image, 'products');
        }

        $product = new Product(
            id: null,
            name: $dto->name,
            price: $price,
            quantity: $dto->quantity,
            description: $dto->description,
            imagePath: $imagePath
        );

        $savedProduct = $this->repository->save($product);

        $data = $savedProduct->toArray();
        if ($savedProduct->imagePath) {
            $data['imagePath'] = $this->storageService->getUrl($savedProduct->imagePath);
        }

        return $data;
    }
}
