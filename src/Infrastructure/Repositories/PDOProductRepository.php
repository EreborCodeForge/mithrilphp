<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Common\Money\Money;
use App\Core\Database;
use App\Domain\Entities\Product;
use App\Domain\Repositories\ProductRepositoryInterface;
use App\Infrastructure\Exceptions\InfrastructureException;
use PDO;
use PDOException;

class PDOProductRepository implements ProductRepositoryInterface
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    public function findAll(): array
    {
        try {
            $stmt = $this->db->query("SELECT * FROM products");
            $products = [];
            while ($row = $stmt->fetch()) {
                $products[] = $this->mapRowToEntity($row);
            }
            return $products;
        } catch (PDOException $e) {
            throw new InfrastructureException("Failed to fetch products", 0, $e);
        }
    }

    public function findById(int $id): ?Product
    {
        try {
            $stmt = $this->db->prepare("SELECT * FROM products WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            return $row ? $this->mapRowToEntity($row) : null;
        } catch (PDOException $e) {
             throw new InfrastructureException("Failed to fetch product with ID $id", 0, $e);
        }
    }

    public function save(Product $product): Product
    {
        try {
            $stmt = $this->db->prepare("INSERT INTO products (name, price, quantity, description, image_path) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([
                $product->name,
                $product->price->toString(), // Saving as string (decimal format)
                $product->quantity,
                $product->description,
                $product->imagePath
            ]);
            $product->id = (int)$this->db->lastInsertId();
            return $product;
        } catch (PDOException $e) {
             throw new InfrastructureException("Failed to save product", 0, $e);
        }
    }

    public function update(Product $product): bool
    {
        try {
            $stmt = $this->db->prepare("UPDATE products SET name=?, price=?, quantity=?, description=?, image_path=? WHERE id=?");
            return $stmt->execute([
                $product->name,
                $product->price->toString(),
                $product->quantity,
                $product->description,
                $product->imagePath,
                $product->id
            ]);
        } catch (PDOException $e) {
             throw new InfrastructureException("Failed to update product with ID {$product->id}", 0, $e);
        }
    }

    public function delete(int $id): bool
    {
        try {
            $stmt = $this->db->prepare("DELETE FROM products WHERE id = ?");
            return $stmt->execute([$id]);
        } catch (PDOException $e) {
            throw new InfrastructureException("Failed to delete product with ID $id", 0, $e);
        }
    }

    private function mapRowToEntity(array $row): Product
    {
        // Assuming default currency is BRL for now or stored in DB (but schema has price only)
        // If price is stored as float/decimal in DB:
        return new Product(
            id: (int)$row['id'],
            name: $row['name'],
            price: new Money((int)$row['price'], 'BRL'), // Simplified currency
            quantity: (int)$row['quantity'],
            description: $row['description'],
            imagePath: $row['image_path'] ?? null
        );
    }
}
