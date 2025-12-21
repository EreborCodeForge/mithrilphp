<?php

declare(strict_types=1);

namespace App\Infrastructure\Services;

use App\Core\Http\UploadedFile;
use App\Domain\Services\StorageServiceInterface;
use App\Infrastructure\Exceptions\InfrastructureException;

class LocalStorageService implements StorageServiceInterface
{
    private const STORAGE_PATH_TEMPLATE = '%s/storage/%s';
    private string $basePath;
    private string $baseUrl;

    public function __construct(string $basePath, string $baseUrl)
    {
        $this->basePath = rtrim($basePath, '/\\');
        $this->baseUrl = rtrim($baseUrl, '/\\');
        if (!is_dir($this->basePath)) {
            mkdir($this->basePath, 0755, true);
        }
    }

    public function store(UploadedFile $file, string $path): string
    {
        if (!$file->isValid()) {
            throw new InfrastructureException("Invalid uploaded file");
        }

        $directory = $this->basePath . '/' . trim($path, '/\\');
        if (!is_dir($directory)) {
            if (!mkdir($directory, 0755, true)) {
                 throw new InfrastructureException("Failed to create storage directory: $directory");
            }
        }

        // Generate unique filename
        $filename = uniqid() . '.' . $file->getExtension();
        $targetPath = $directory . '/' . $filename;

        if (!move_uploaded_file($file->tmpName, $targetPath)) {
            throw new InfrastructureException("Failed to move uploaded file to $targetPath");
        }

        // Return relative path for database storage
        return $path . '/' . $filename;
    }

    public function delete(string $path): void
    {
        $fullPath = $this->basePath . '/' . trim($path, '/\\');
        if (file_exists($fullPath)) {
            unlink($fullPath);
        }
    }

    public function getUrl(string $path): string
    {
        return sprintf(self::STORAGE_PATH_TEMPLATE, $this->baseUrl, ltrim($path, '/\\'));
    }
}
