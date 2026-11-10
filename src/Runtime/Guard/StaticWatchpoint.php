<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime\Guard;

/**
 * One explicitly registered static property.
 * lifetime=request is compared to the pre-request baseline.
 * lifetime=worker is ignored by the violation check.
 */
final readonly class StaticWatchpoint
{
    public function __construct(
        public string $class,
        public string $property,
        public string $lifetime,
    ) {}

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        $allowed = ['class' => true, 'property' => true, 'lifetime' => true];
        foreach ($row as $key => $_) {
            if (!isset($allowed[$key])) {
                throw new GuardConfigException('Invalid watchpoint');
            }
        }

        $class = $row['class'] ?? null;
        $property = $row['property'] ?? null;
        $lifetime = $row['lifetime'] ?? null;

        if (!is_string($class) || preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]*$/', $class) !== 1) {
            throw new GuardConfigException('Invalid watchpoint');
        }
        if (!is_string($property) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $property) !== 1) {
            throw new GuardConfigException('Invalid watchpoint');
        }
        if ($lifetime !== 'request' && $lifetime !== 'worker') {
            throw new GuardConfigException('Invalid watchpoint');
        }

        return new self($class, $property, $lifetime);
    }
}
