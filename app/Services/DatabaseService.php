<?php

namespace App\Services;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

class DatabaseService
{
    public static function setDb(string $dbName, string $driver = 'mysql')
    {
        DB::disconnect('mysql');

        $connection = config('database.connections.' . $driver);
        Config::set("database.connections." . $driver, [
            'driver' => 'mysql',
            'host' => $connection['host'],
            'username' => $connection['username'],
            'password' => $connection['password'],
            'database' => $dbName,
        ]);
    }
}
