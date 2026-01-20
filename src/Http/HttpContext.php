<?php

declare(strict_types=1);

namespace Erebor\Mithril\Http;

use Erebor\Mithril\Exceptions\HttpException;
use Erebor\Mithril\Support\FastTraceIdGenerator;

final class HttpContext
{
    public readonly Request $request;

    private AttributeBag $attributes;
    private AttributeBag $items;

    private ?string $traceId;

    public function __construct(
        Request $request,
        ?AttributeBag $attributes = null,
        ?AttributeBag $items = null,
        ?string $traceId = null
    ) {
        $this->request = $request;
        $this->attributes = $attributes ?? new AttributeBag();
        $this->items = $items ?? new AttributeBag();
        $this->traceId = $traceId;
    }

    public function traceId(): string
    {
        return $this->traceId ??= self::fallbackTraceId();
    }

    public function setTraceId(string $traceId): void
    {
        $this->traceId = $traceId;
    }

    public function attributes(): AttributeBag
    {
        return $this->attributes;
    }

    public function items(): AttributeBag
    {
        return $this->items;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->attributes->get($key, $default);
    }

    public function set(string $key, mixed $value): void
    {
        $this->attributes->set($key, $value);
    }

    public function has(string $key): bool
    {
        return $this->attributes->has($key);
    }

    public function forget(string $key): void
    {
        $this->attributes->forget($key);
    }

    public function once(string $key, callable $factory): mixed
    {
        return $this->items->once($key, $factory);
    }

    public function abort(int $statusCode, string $message = 'Aborted'): never
    {
        throw new HttpException($statusCode, $message);
    }

    public function cloneWithRequest(Request $request, bool $keepItems = false): self
    {
        return new self(
            request: $request,
            attributes: new AttributeBag($this->attributes->all()),
            items: $keepItems ? new AttributeBag($this->items->all()) : new AttributeBag(),
            traceId: $this->traceId
        );
    }

    private static function fallbackTraceId(): string
    {
        $time = (int) (microtime(true) * 1000);
        $r = random_int(0, 0xFFFF);
        return dechex($time) . '-' . dechex($r) . '-' . bin2hex(pack('N', mt_rand()));
    }
}
