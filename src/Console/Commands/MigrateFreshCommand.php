<?php

declare(strict_types=1);

namespace Erebor\Mithril\Console\Commands;

class MigrateFreshCommand extends BaseMigrateCommand
{
    public static function getSignature(): string
    {
        return 'migrate:fresh';
    }

    public static function getDescription(): string
    {
        return 'Drop all tables and re-run all migrations';
    }

    public function execute(): int
    {
        $this->info("Dropping all tables and re-running migrations...");
        $this->getRunner()->fresh();
        $this->info("Database refreshed successfully.");
        return 0;
    }
}
