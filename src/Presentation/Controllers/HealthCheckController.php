<?php

declare(strict_types=1);

namespace App\Presentation\Controllers;

use App\Core\Http\Response;

class HealthCheckController
{
    public function check(): Response
    {
        return (new Response())->json([
            'status' => 'ok',
            'timestamp' => time(),
            'service' => 'AppMarket API',
            'version' => '1.0.2'
        ]);
    }
}
