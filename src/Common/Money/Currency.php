<?php

declare(strict_types=1);

namespace App\Common\Money;

final class Currency
{
    private string $code;
    private string $name;
    private string $symbol;
    private int $precision;

    private function __construct(string $code, string $name, string $symbol, int $precision = 2)
    {
        $this->code = strtoupper($code);
        $this->name = $name;
        $this->symbol = $symbol;
        $this->precision = $precision;
    }

    public static function BRL(): self
    {
        return new self('BRL', 'Real Brasileiro', 'R$');
    }

    public static function USD(): self
    {
        return new self('USD', 'US Dollar', '$');
    }

    public static function EUR(): self
    {
        return new self('EUR', 'Euro', '€');
    }

    public static function fromCode(string $code): self
    {
        return match(strtoupper($code)) {
            'BRL' => self::BRL(),
            'USD' => self::USD(),
            'EUR' => self::EUR(),
            default => new self($code, $code, $code)
        };
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSymbol(): string
    {
        return $this->symbol;
    }

    public function getPrecision(): int
    {
        return $this->precision;
    }

    public function equals(Currency $other): bool
    {
        return $this->code === $other->code;
    }

    public function __toString(): string
    {
        return $this->code;
    }
}
