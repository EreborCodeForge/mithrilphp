<?php

declare(strict_types=1);

namespace App\Application\UseCases\Product;

use App\Application\DTOs\Product\UpdateProductDTO;
use App\Common\Money\Currency;
use App\Common\Money\Money;
use App\Domain\Repositories\ProductRepositoryInterface;
use App\Domain\Services\StorageServiceInterface;
use App\Presentation\Exceptions\HttpException;

class UpdateProductUseCase
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

    public function execute(int $id, UpdateProductDTO $dto): array
    {
        $product = $this->repository->findById($id);

        if (!$product) {
            throw new HttpException("Product not found", 404);
        }

        if ($dto->name !== null) {
            $product->name = $dto->name;
        }

        if ($dto->price !== null) {
            $product->price = Money::fromString($dto->price, Currency::BRL()->getCode());
        }

        if ($dto->quantity !== null) {
            $product->quantity = $dto->quantity;
        }

        if ($dto->description !== null) {
            $product->description = $dto->description;
        }

        if ($dto->image !== null) {
            // Delete old image if exists
            if ($product->imagePath) {
                $this->storageService->delete($product->imagePath);
            }
            $product->imagePath = $this->storageService->store($dto->image, 'products');
        } elseif ($dto->imagePath !== null) {
            $product->imagePath = $dto->imagePath;
        }

        $updatedProduct = $this->repository->save($product);

        $data = $updatedProduct->toArray();
        if ($updatedProduct->imagePath) {
            $data['imagePath'] = $this->storageService->getUrl($updatedProduct->imagePath);
        }

        return $data;
    }
}
