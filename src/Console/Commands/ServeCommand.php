<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Console\Command;
use App\Core\Environment;

class ServeCommand extends Command
{
    public static function getSignature(): string
    {
        return 'serve';
    }

    public static function getDescription(): string
    {
        return 'Start the development server';
    }

    public function execute(): int
    {
        $host = 'localhost';
        $port = Environment::get('APP_PORT', '8000');
        
        $this->info("Starting development server at http://$host:$port");
        $this->line("Press Ctrl+C to stop.");
        
        passthru("php -S $host:$port -t public");
        
        return 0;
    }
}
