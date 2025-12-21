<?php

declare(strict_types=1);

namespace App\Presentation\Controllers;

use App\Application\DTOs\User\CreateUserDTO;
use App\Application\DTOs\User\UpdateUserDTO;
use App\Application\UseCases\User\CreateUserUseCase;
use App\Application\UseCases\User\DeleteUserUseCase;
use App\Application\UseCases\User\GetUserUseCase;
use App\Application\UseCases\User\ListUsersUseCase;
use App\Application\UseCases\User\UpdateUserUseCase;
use App\Application\Validation\Validator;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Domain\Enums\UserRole;

class UserController
{
    public function __construct(
        private ListUsersUseCase $listUsersUseCase,
        private GetUserUseCase $getUserUseCase,
        private CreateUserUseCase $createUserUseCase,
        private UpdateUserUseCase $updateUserUseCase,
        private DeleteUserUseCase $deleteUserUseCase
    ) {}

    public function index(Request $request): Response
    {
        $users = $this->listUsersUseCase->execute();
        return (new Response())->json($users);
    }

    public function show(Request $request, array $params): Response
    {
        $id = (int)$params['id'];
        $user = $this->getUserUseCase->execute($id);
        return (new Response())->json($user);
    }

    public function store(Request $request): Response
    {
        $data = $request->body;

        (new Validator())->validate($data, [
            'name' => 'required|min:3',
            'email' => 'required|email',
            'password' => 'required|min:6'
        ]);

        $role = isset($data['role']) ? UserRole::tryFrom($data['role']) : null;

        $dto = new CreateUserDTO(
            name: $data['name'],
            email: $data['email'],
            password: $data['password'],
            role: $role
        );

        $user = $this->createUserUseCase->execute($dto);
        return (new Response())->json($user, 201);
    }

    public function update(Request $request, array $params): Response
    {
        $id = (int)$params['id'];
        $data = $request->body;

        (new Validator())->validate($data, [
            'name' => 'min:3',
            'email' => 'email',
            'password' => 'min:6'
        ]);

        $role = isset($data['role']) ? UserRole::tryFrom($data['role']) : null;

        $dto = new UpdateUserDTO(
            name: $data['name'] ?? null,
            email: $data['email'] ?? null,
            password: $data['password'] ?? null,
            role: $role
        );

        $user = $this->updateUserUseCase->execute($id, $dto);
        return (new Response())->json($user);
    }

    public function delete(Request $request, array $params): Response
    {
        $id = (int)$params['id'];
        $this->deleteUserUseCase->execute($id);
        return (new Response())->noContent();
    }
}
