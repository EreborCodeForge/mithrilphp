<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime\Guard;

enum FindingSeverity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';
}
