<?php

declare(strict_types=1);

namespace App\Presentation\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;

class ResourceController
{
    private string $resourcesPath;

    public function __construct()
    {
        $this->resourcesPath = dirname(__DIR__, 3) . '/resources';
    }

    public function serve(Request $request, string $path): Response
    {
        // Sanitize path to prevent directory traversal
        $path = str_replace('\\', '/', $path);
        $path = ltrim($path, '/');
        $file = $this->resourcesPath . '/' . $path;

        if (!file_exists($file)) {
            return new Response('File not found', 404);
        }

        $mimeType = $this->getMimeType($file);
        
        $content = file_get_contents($file);
        
        $response = new Response($content, 200);
        $response->withHeader('Content-Type', $mimeType);
        
        return $response;
    }

    private function getMimeType(string $file): string
    {
        $extension = pathinfo($file, PATHINFO_EXTENSION);
        
        return match ($extension) {
            'js' => 'application/javascript; charset=utf-8',
            'css' => 'text/css',
            'vue' => 'text/javascript', // Serve Vue files as JS so they can be imported (loader needed downstream)
            'json' => 'application/json',
            default => mime_content_type($file) ?: 'text/plain',
        };
    }
}
