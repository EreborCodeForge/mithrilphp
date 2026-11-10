<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime\Guard;

enum FindingConfidence: string
{
    case Observed = 'observed';
    case Suspected = 'suspected';
    case Verified = 'verified';
}
