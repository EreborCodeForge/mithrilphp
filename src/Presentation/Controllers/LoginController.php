<?php

declare(strict_types=1);

namespace App\Presentation\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Infrastructure\View\VueEngine;

class LoginController
{
    public function show(Request $request): Response
    {
        $html = (new VueEngine())->render('/views/layouts/vue/login.php');
        return Response::html($html);
    }
}
