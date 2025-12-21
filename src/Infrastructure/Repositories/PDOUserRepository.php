<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Core\Database;
use App\Domain\Entities\User;
use App\Domain\Enums\UserRole;
use App\Domain\Repositories\UserRepositoryInterface;
use App\Infrastructure\Exceptions\InfrastructureException;
use PDO;
use PDOException;

class PDOUserRepository implements UserRepositoryInterface
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    public function findByEmail(string $email): ?User
    {
        try {
            $stmt = $this->db->prepare("SELECT * FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $row = $stmt->fetch();
            return $row ? $this->mapRowToEntity($row) : null;
        } catch (PDOException $e) {
            throw new InfrastructureException("Failed to find user by email", 0, $e);
        }
    }

    public function findById(int $id): ?User
    {
        try {
            $stmt = $this->db->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            return $row ? $this->mapRowToEntity($row) : null;
        } catch (PDOException $e) {
            throw new InfrastructureException("Failed to find user by id", 0, $e);
        }
    }

    public function findAll(): array
    {
        try {
            $stmt = $this->db->query("SELECT * FROM users");
            $users = [];
            while ($row = $stmt->fetch()) {
                $users[] = $this->mapRowToEntity($row);
            }
            return $users;
        } catch (PDOException $e) {
            throw new InfrastructureException("Failed to fetch all users", 0, $e);
        }
    }

    public function save(User $user): User
    {
        try {
            $stmt = $this->db->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)");
            $stmt->execute([
                $user->name,
                $user->email,
                $user->password,
                $user->role->value
            ]);
            $user->id = (int)$this->db->lastInsertId();
            return $user;
        } catch (PDOException $e) {
            throw new InfrastructureException("Failed to save user", 0, $e);
        }
    }

    public function update(User $user): bool
    {
        try {
            $stmt = $this->db->prepare("UPDATE users SET name = ?, email = ?, password = ?, role = ? WHERE id = ?");
            return $stmt->execute([
                $user->name,
                $user->email,
                $user->password,
                $user->role->value,
                $user->id
            ]);
        } catch (PDOException $e) {
            throw new InfrastructureException("Failed to update user", 0, $e);
        }
    }

    public function delete(int $id): bool
    {
        try {
            $stmt = $this->db->prepare("DELETE FROM users WHERE id = ?");
            return $stmt->execute([$id]);
        } catch (PDOException $e) {
            throw new InfrastructureException("Failed to delete user", 0, $e);
        }
    }

    private function mapRowToEntity(array $row): User
    {
        return new User(
            id: (int)$row['id'],
            name: $row['name'],
            email: $row['email'],
            password: $row['password'],
            role: UserRole::from($row['role'])
        );
    }
}
