<?php

declare(strict_types=1);

namespace App\Presentation\Middleware;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Middleware\MiddlewareInterface;
use App\Presentation\Exceptions\HttpException;

class AuthMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        $authHeader = $request->header('Authorization');

        if (!$authHeader) {
            throw new HttpException('Unauthorized', 401);
        }

        // Simple Bearer token check (in a real app, verify signature/expiration)
        if (!preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
             throw new HttpException('Invalid token format', 401);
        }

        $token = $matches[1];
        $decoded = base64_decode($token, true);
        
        if ($decoded === false) {
             throw new HttpException('Invalid token', 401);
        }
        
        // Format is userID:timestamp
        $parts = explode(':', $decoded);
        if (count($parts) !== 2) {
            throw new HttpException('Invalid token structure', 401);
        }

        return $next($request);
    }
}
