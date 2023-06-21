<?php

namespace App\Console\Commands;

use App\Services\DatabaseService;
use App\Services\MigrateTablesService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrateBlogTables extends Command
{
    protected $signature = 'schema:migrate';

    public function handle()
    {
        $blogId = 432;

        $sourceDb = 'wordpress_clarku';
        $destDb = 'sites_clarku';

        DatabaseService::setDb($destDb);
        $blogs = DB::select('SELECT domain, MAX(blog_id) AS max FROM wp_blogs GROUP BY domain');
        $destBlogId = current($blogs)->max;
        $destBlogUrl = current($blogs)->domain;

        DatabaseService::setDb($sourceDb);

        $service = new MigrateTablesService();

        $service->setBlogToMigrate($blogId)
            ->setSourceDatabase('wordpress_clarku')
            ->setDestDatabase('sites_clarku')
            ->setDestBlogId($destBlogId)
            ->setDestBlogUrl($destBlogUrl)
            ->run();


        return Command::SUCCESS;
    }
}
