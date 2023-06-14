<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RetrieveAndConvertSchema extends Command
{
    protected $signature = 'schema:retrieve';

    public function handle()
    {
        return Command::SUCCESS;
    }
}
