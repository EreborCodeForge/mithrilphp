<?php

declare(strict_types=1);

namespace App\Domain\Services;

use App\Core\Http\UploadedFile;

interface StorageServiceInterface
{
    public function store(UploadedFile $file, string $path): string;
    public function delete(string $path): void;
    public function getUrl(string $path): string;
}
