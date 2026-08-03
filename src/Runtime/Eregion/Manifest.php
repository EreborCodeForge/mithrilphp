<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime\Eregion;

use Erebor\Mithril\Runtime\Eregion\Exceptions\ProtocolException;

final readonly class Manifest
{
    /**
     * @param array{maxRequests?: int, memoryLimitBytes?: int} $worker
     * @param array{version?: int, maxFrameBytes?: int} $protocol
     */
    public function __construct(
        public string $application,
        public string $autoload,
        public string $workingDirectory,
        public ?string $compiledContainer,
        public ?string $compiledRoutes,
        public string $environment,
        public array $worker,
        public array $protocol,
    ) {}

    public static function fromFile(string $path): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new ProtocolException("Manifest not found or unreadable: {$path}");
        }

        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            throw new ProtocolException("Manifest is empty: {$path}");
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ProtocolException("Invalid manifest JSON: {$path}", 0, $e);
        }

        if (!is_array($data)) {
            throw new ProtocolException("Manifest must be a JSON object: {$path}");
        }

        return self::fromArray($data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $application = (string) ($data['application'] ?? '');
        $autoload = (string) ($data['autoload'] ?? '');
        $workingDirectory = (string) ($data['workingDirectory'] ?? '');

        if ($application === '' || $autoload === '' || $workingDirectory === '') {
            throw new ProtocolException('Manifest requires application, autoload, and workingDirectory');
        }

        $worker = is_array($data['worker'] ?? null) ? $data['worker'] : [];
        $protocol = is_array($data['protocol'] ?? null) ? $data['protocol'] : [];

        return new self(
            application: $application,
            autoload: $autoload,
            workingDirectory: $workingDirectory,
            compiledContainer: isset($data['compiledContainer']) ? (string) $data['compiledContainer'] : null,
            compiledRoutes: isset($data['compiledRoutes']) ? (string) $data['compiledRoutes'] : null,
            environment: (string) ($data['environment'] ?? 'production'),
            worker: [
                'maxRequests' => (int) ($worker['maxRequests'] ?? 0),
                'memoryLimitBytes' => (int) ($worker['memoryLimitBytes'] ?? 0),
            ],
            protocol: [
                'version' => (int) ($protocol['version'] ?? Protocol::VERSION),
                'maxFrameBytes' => (int) ($protocol['maxFrameBytes'] ?? Protocol::DEFAULT_MAX_FRAME_BYTES),
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'application' => $this->application,
            'autoload' => $this->autoload,
            'workingDirectory' => $this->workingDirectory,
            'compiledContainer' => $this->compiledContainer,
            'compiledRoutes' => $this->compiledRoutes,
            'environment' => $this->environment,
            'worker' => $this->worker,
            'protocol' => $this->protocol,
        ];
    }

    public function write(string $path): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new ProtocolException("Unable to create manifest directory: {$dir}");
        }

        $json = json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($path, $json . "\n") === false) {
            throw new ProtocolException("Unable to write manifest: {$path}");
        }
    }

    public function maxFrameBytes(): int
    {
        return (int) ($this->protocol['maxFrameBytes'] ?? Protocol::DEFAULT_MAX_FRAME_BYTES);
    }

    public function maxRequests(): int
    {
        return (int) ($this->worker['maxRequests'] ?? 0);
    }

    public function memoryLimitBytes(): int
    {
        return (int) ($this->worker['memoryLimitBytes'] ?? 0);
    }
}
