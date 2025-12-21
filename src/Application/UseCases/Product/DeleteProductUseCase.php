<?php

declare(strict_types=1);

namespace App\Application\UseCases\Product;

use App\Domain\Repositories\ProductRepositoryInterface;
use App\Presentation\Exceptions\HttpException;

class DeleteProductUseCase
{
    private ProductRepositoryInterface $repository;

    public function __construct(ProductRepositoryInterface $repository)
    {
        $this->repository = $repository;
    }

    public function execute(int $id): void
    {
        $product = $this->repository->findById($id);

        if (!$product) {
            throw new HttpException("Product not found", 404);
        }

        $this->repository->delete($id);
    }
}
