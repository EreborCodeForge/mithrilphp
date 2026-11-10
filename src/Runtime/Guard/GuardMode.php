<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime\Guard;

enum GuardMode: string
{
    case Off = 'off';
    case Observe = 'observe';
    case Enforce = 'enforce';
    case Audit = 'audit';

    public function runsProbes(): bool
    {
        return $this !== self::Off;
    }
}
