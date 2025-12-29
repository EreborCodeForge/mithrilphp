<?php

declare(strict_types=1);

namespace Erebor\Mithril\Console\Commands;

class MigrateRollbackCommand extends BaseMigrateCommand
{
    public static function getSignature(): string
    {
        return 'migrate:rollback';
    }

    public static function getDescription(): string
    {
        return 'Rollback the last database migration';
    }

    public function execute(): int
    {
        $this->info("Rolling back migrations...");
        $this->getRunner()->rollback();
        $this->info("Rollback completed.");
        return 0;
    }
}
