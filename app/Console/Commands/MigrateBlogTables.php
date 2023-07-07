<?php

namespace App\Console\Commands;

use App\Services\DatabaseService;
use App\Services\MigrateTablesService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrateBlogTables extends Command
{
    protected $signature = 'schema:migrate {--blog_id=} {--source=} {--dest=}';

    public function handle(MigrateTablesService $service)
    {
        $blogId = $this->option('blog_id');
        $sourceDb = $this->option('source'); //'wordpress_clarku';
        $destDb = $this->option('dest'); //'sites_clarku';

        $service->setBlogToMigrate($blogId)
            ->setSourceDatabase($sourceDb)
            ->setDestDatabase($destDb)
            ->run();

        return Command::SUCCESS;
    }
}
