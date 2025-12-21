<?php

declare(strict_types=1);

namespace App\Application\UseCases\Product;

use App\Domain\Repositories\ProductRepositoryInterface;
use App\Domain\Services\StorageServiceInterface;

class ListProductsUseCase
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

    public function execute(): array
    {
        $products = $this->repository->findAll();
        return array_map(function($p) {
            $data = $p->toArray();
            if ($p->imagePath) {
                $data['imagePath'] = $this->storageService->getUrl($p->imagePath);
            }
            return $data;
        }, $products);
    }
}
