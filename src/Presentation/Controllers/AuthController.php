<?php

declare(strict_types=1);

namespace App\Presentation\Controllers;

use App\Application\DTOs\Auth\LoginDTO;
use App\Application\DTOs\Auth\RegisterDTO;
use App\Application\UseCases\Auth\LoginUseCaseInterface;
use App\Application\UseCases\Auth\RegisterUseCaseInterface;
use App\Application\Validation\Validator;
use App\Core\Http\Request;
use App\Core\Http\Response;

class AuthController
{
    public function __construct(
        private LoginUseCaseInterface $loginUseCase,
        private RegisterUseCaseInterface $registerUseCase
    ) {}

    public function login(Request $request): Response
    {
        $data = $request->body;
        (new Validator())->validate($data, [
            'email' => 'required|email',
            'password' => 'required|min:6'
        ]);

        $dto = new LoginDTO(
            email: $data['email'],
            password: $data['password']
        );
        
        $result = $this->loginUseCase->execute($dto);

        return (new Response())->json($result);
    }

    public function register(Request $request): Response
    {
        $data = $request->body;
        (new Validator())->validate($data, [
            'name' => 'required|min:3',
            'email' => 'required|email',
            'password' => 'required|min:6'
        ]);

        $dto = new RegisterDTO(
            name: $data['name'],
            email: $data['email'],
            password: $data['password']
        );

        $result = $this->registerUseCase->execute($dto);

        return (new Response())->json($result, 201);
    }
}
