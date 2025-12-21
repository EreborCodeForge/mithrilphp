<?php

declare(strict_types=1);

namespace App\Common\Money;

use InvalidArgumentException;
use JsonSerializable;

final readonly class Money implements JsonSerializable
{
    private int $amountInCents;
    private string $currency;

    private function __construct(int $amountInCents, string $currency = 'BRL')
    {
        $this->amountInCents = $amountInCents;
        $this->currency = strtoupper($currency);
    }

    public static function fromFloat(float $amount, string $currency = 'BRL'): self
    {
        if ($amount < 0) {
            throw new InvalidArgumentException('Amount cannot be negative');
        }
        
        return new self((int) round($amount * 100), $currency);
    }

    public static function fromCents(int $cents, string $currency = 'BRL'): self
    {
        if ($cents < 0) {
            throw new InvalidArgumentException('Amount in cents cannot be negative');
        }
        
        return new self($cents, $currency);
    }

    public static function zero(string $currency = 'BRL'): self
    {
        return new self(0, $currency);
    }

    public static function fromString(string $amount, string $currency = 'BRL'): self
    {
        $cleanAmount = preg_replace('/[^0-9.,]/', '', $amount);
        $cleanAmount = str_replace(',', '.', $cleanAmount);
        
        if (!is_numeric($cleanAmount)) {
            throw new InvalidArgumentException('Invalid amount format');
        }
        
        return self::fromFloat((float) $cleanAmount, $currency);
    }

    public function getAmount(): float
    {
        return $this->amountInCents / 100;
    }

    public function toCents(): int
    {
        return $this->amountInCents;
    }

    public function toFloat(): float
    {
        return $this->getAmount();
    }

    public function toString(bool $formatted = false, int $decimals = 2): string
    {
        if ($formatted) {
            return $this->format($decimals);
        }
        
        return number_format($this->getAmount(), $decimals, '.', '');
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getAmountInCents(): int
    {
        return $this->amountInCents;
    }

    public function plus(Money $other): self
    {
        $this->ensureSameCurrency($other);
        return new self($this->amountInCents + $other->amountInCents, $this->currency);
    }

    public function minus(Money $other): self
    {
        $this->ensureSameCurrency($other);
        $result = $this->amountInCents - $other->amountInCents;
        
        if ($result < 0) {
            throw new InvalidArgumentException('Result cannot be negative');
        }
        
        return new self($result, $this->currency);
    }

    public function multiply(float $multiplier): self
    {
        if ($multiplier < 0) {
            throw new InvalidArgumentException('Multiplier cannot be negative');
        }
        
        return new self((int) round($this->amountInCents * $multiplier), $this->currency);
    }

    public function divide(float $divisor): self
    {
        if ($divisor <= 0) {
            throw new InvalidArgumentException('Divisor must be positive');
        }
        
        return new self((int) round($this->amountInCents / $divisor), $this->currency);
    }

    public function percentage(float $percentage): self
    {
        return $this->multiply($percentage / 100);
    }

    public function isZero(): bool
    {
        return $this->amountInCents === 0;
    }

    public function isPositive(): bool
    {
        return $this->amountInCents > 0;
    }

    public function isGreaterThan(Money $other): bool
    {
        $this->ensureSameCurrency($other);
        return $this->amountInCents > $other->amountInCents;
    }

    public function isGreaterThanOrEqual(Money $other): bool
    {
        $this->ensureSameCurrency($other);
        return $this->amountInCents >= $other->amountInCents;
    }

    public function isLessThan(Money $other): bool
    {
        $this->ensureSameCurrency($other);
        return $this->amountInCents < $other->amountInCents;
    }

    public function isLessThanOrEqual(Money $other): bool
    {
        $this->ensureSameCurrency($other);
        return $this->amountInCents <= $other->amountInCents;
    }

    public function equals(Money $other): bool
    {
        return $this->currency === $other->currency && 
               $this->amountInCents === $other->amountInCents;
    }

    public function format(int $decimals = 2, string $decimalSeparator = ',', string $thousandsSeparator = '.'): string
    {
        $amount = number_format($this->getAmount(), $decimals, $decimalSeparator, $thousandsSeparator);
        
        return match($this->currency) {
            'BRL' => 'R$ ' . $amount,
            'USD' => '$ ' . $amount,
            'EUR' => '€ ' . $amount,
            default => $this->currency . ' ' . $amount
        };
    }

    public function formatSimple(int $decimals = 2): string
    {
        return number_format($this->getAmount(), $decimals, '.', '');
    }

    public function allocate(array $ratios): array
    {
        if (empty($ratios)) {
            throw new InvalidArgumentException('Ratios cannot be empty');
        }
        
        $total = array_sum($ratios);
        if ($total <= 0) {
            throw new InvalidArgumentException('Sum of ratios must be positive');
        }
        
        $remainder = $this->amountInCents;
        $results = [];
        
        foreach ($ratios as $ratio) {
            $amount = (int) floor($this->amountInCents * $ratio / $total);
            $results[] = new self($amount, $this->currency);
            $remainder -= $amount;
        }
        
        // Distribute remainder
        for ($i = 0; $i < $remainder; $i++) {
            $results[$i] = new self($results[$i]->amountInCents + 1, $this->currency);
        }
        
        return $results;
    }

    public function jsonSerialize(): array
    {
        return [
            'amount' => $this->getAmount(),
            'currency' => $this->currency,
            'formatted' => $this->format()
        ];
    }

    public function __toString(): string
    {
        return $this->format();
    }

    private function ensureSameCurrency(Money $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException(
                sprintf('Currency mismatch: %s vs %s', $this->currency, $other->currency)
            );
        }
    }
}