<?php

declare(strict_types=1);

namespace App\Console\Commands;

class MigrateCommand extends BaseMigrateCommand
{
    public static function getSignature(): string
    {
        return 'migrate';
    }

    public static function getDescription(): string
    {
        return 'Run the database migrations';
    }

    public function execute(): int
    {
        $this->info("Running migrations...");
        $this->getRunner()->migrate();
        $this->info("Migrations completed successfully.");
        return 0;
    }
}
