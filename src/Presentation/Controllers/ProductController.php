<?php

declare(strict_types=1);

namespace App\Presentation\Controllers;

use App\Application\DTOs\Product\CreateProductDTO;
use App\Application\DTOs\Product\UpdateProductDTO;
use App\Application\UseCases\Product\CreateProductUseCase;
use App\Application\UseCases\Product\DeleteProductUseCase;
use App\Application\UseCases\Product\GetProductUseCase;
use App\Application\UseCases\Product\ListProductsUseCase;
use App\Application\UseCases\Product\UpdateProductUseCase;
use App\Application\Validation\Validator;
use App\Core\Http\Request;
use App\Core\Http\Response;

class ProductController
{
    public function __construct(
        private ListProductsUseCase $listProductsUseCase,
        private GetProductUseCase $getProductUseCase,
        private CreateProductUseCase $createProductUseCase,
        private UpdateProductUseCase $updateProductUseCase,
        private DeleteProductUseCase $deleteProductUseCase
    ) {}

    public function index(Request $request): Response
    {
        $products = $this->listProductsUseCase->execute();
        return (new Response())->json($products);
    }

    public function show(Request $request, array $params): Response
    {
        $id = (int)$params['id'];
        $product = $this->getProductUseCase->execute($id);
        return (new Response())->json($product);
    }

    public function store(Request $request): Response
    {
        $data = $request->body;
        (new Validator())->validate($data, [
            'name' => 'required|min:3',
            'price' => 'required|money',
            'quantity' => 'int|min:0'
        ]);

        $image = $request->files['image'] ?? null;

        $dto = new CreateProductDTO(
            name: $data['name'],
            price: (string)$data['price'],
            quantity: (int)($data['quantity'] ?? 0),
            description: $data['description'] ?? null,
            imagePath: $data['image_path'] ?? null,
            image: $image
        );

        $product = $this->createProductUseCase->execute($dto);
        
        return (new Response())->json($product, 201);
    }

    public function update(Request $request, array $params): Response
    {
        $id = (int)$params['id'];
        
        $data = $request->body;
        (new Validator())->validate($data, [
            'price' => 'money',
            'quantity' => 'int|min:0'
        ]);

        $image = $request->files['image'] ?? null;

        $dto = new UpdateProductDTO(
            name: $data['name'] ?? null,
            price: isset($data['price']) ? (string)$data['price'] : null,
            quantity: isset($data['quantity']) ? (int)$data['quantity'] : null,
            description: $data['description'] ?? null,
            imagePath: $data['image_path'] ?? null,
            image: $image
        );
        
        $product = $this->updateProductUseCase->execute($id, $dto);

        return (new Response())->json($product);
    }

    public function delete(Request $request, array $params): Response
    {
        $id = (int)$params['id'];
        $this->deleteProductUseCase->execute($id);
        return (new Response())->json(['message' => 'Product deleted']);
    }
}
