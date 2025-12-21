<?php

declare(strict_types=1);

namespace App\Application\UseCases\Product;

use App\Domain\Repositories\ProductRepositoryInterface;
use App\Domain\Services\StorageServiceInterface;
use App\Presentation\Exceptions\HttpException;

class GetProductUseCase
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

    public function execute(int $id): array
    {
        $product = $this->repository->findById($id);

        if (!$product) {
            throw new HttpException("Product not found", 404);
        }

        $data = $product->toArray();
        if ($product->imagePath) {
            $data['imagePath'] = $this->storageService->getUrl($product->imagePath);
        }

        return $data;
    }
}
