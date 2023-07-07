<?php

namespace App\Services;

use App\Models\DynamicModel;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MigrateTablesService
{
    protected int $sourceBlogId;
    protected string $sourceDatabase;
    protected string $destDatabase;
    protected int $destBlogId;
    protected string $prefix = 'wp_';
    protected string $destBlogUrl;
    protected string $datetimePrefix;
    protected string $migrationsPath;
    protected Collection $blogTables;
    protected Collection $createTableStatements;
    protected Collection $dropTableStatements;
    protected Collection $inserts;

    public function __construct()
    {
        $this->blogTables = collect();
        $this->createTableStatements = collect();
        $this->dropTableStatements = collect();
        $this->inserts = collect();
        $this->migrationsPath = base_path() . '/database/migrations';
        $this->datetimePrefix = Carbon::now()->format('Y_m_d_His');
    }

    public function setBlogToMigrate(int $blogId): self
    {
        $this->sourceBlogId = $blogId;

        return $this;
    }

    public function setSourceDatabase(string $sourceDatabase): self
    {
        $this->sourceDatabase = $sourceDatabase;

        return $this;
    }

    public function setDestDatabase(string $destDatabase): self
    {
        $this->destDatabase = $destDatabase;

        return $this;
    }

    protected function switchToDatabase(string $databaseName)
    {
        DatabaseService::setDb($databaseName);
    }

    public function run(): void
    {
        $this->setDestBlogInfo();
        $this->switchToDatabase($this->sourceDatabase);
        $dbName = $this->sourceDatabase;
        $tables = DB::select('SHOW TABLES');

        collect($tables)->each(function ($table) use ($dbName) {
            $prop = 'Tables_in_' . $dbName;
            if (stripos($table->$prop, $this->prefix . $this->sourceBlogId . '_') !== false) {
                // Add table names to collection
//                echo $table->$prop . PHP_EOL;
                $this->blogTables->push($table->$prop);
            }
        });

        $this->migrate();
    }

    public function migrate()
    {
        $this->blogTables->each(function ($tableName) {
            $this->buildCreateStatements($tableName)
                ->buildInsertRows($tableName);
        });

        // Drop all tables that match the destination ID
        // as well as the wp_blogs entry
        $this->dropTables()
            ->removeBlogsTableEntry();

        $this->createTables()
            ->insertData()
            ->insertBlogRecord();

    }

    protected function setDestBlogInfo()
    {
        $this->switchToDatabase($this->destDatabase);

        // Get the current highest blog ID from the destination
        $blogs = DB::select('SELECT domain, MAX(blog_id) AS max FROM wp_blogs GROUP BY domain');
        $this->destBlogId = (int) current($blogs)->max + 1;
        $this->destBlogUrl = current($blogs)->domain;
    }

    protected function getDestTableName(string $tableName): string
    {
        return str_replace("_{$this->sourceBlogId}_", "_{$this->destBlogId}_", $tableName);
    }

    protected function createTables(): self
    {
        $this->switchToDatabase($this->destDatabase);

        $this->createTableStatements->each(function ($statement) {
            $sql = current($statement);
            DB::statement($sql);
        });

        return $this;
    }

    protected function dropTables(): self
    {
        $this->switchToDatabase($this->destDatabase);

        $this->dropTableStatements->each(function ($statement) {
            $sql = current($statement);
            DB::statement($sql);
        });

        return $this;
    }

    protected function removeBlogsTableEntry(): self
    {
        $this->switchToDatabase($this->destDatabase);

        $blogsTable = $this->prefix . 'blogs';

        DB::statement("DELETE FROM {$blogsTable} WHERE blog_id = {$this->destBlogId}");

        return $this;
    }

    protected function insertData(): self
    {
        // Set sql_mode to prevent error when inserting a record with a "zero" date
        DB::statement("SET sql_mode='ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");

        // Execute prepared statements
        $this->inserts->each(function ($item) {
            DB::insert($item['insert'], $item['values']);
        });

        return $this;
    }

    protected function buildCreateStatements(string $tableName): self
    {
        $destTableName = $this->getDestTableName($tableName);
        $query = DB::select("SHOW CREATE TABLE {$tableName};");
        $prop = 'Create Table';
        $createText = current($query)->$prop;

        $createStatement = str_replace(
            [
                'CREATE TABLE',
                "'0000-00-00 00:00:00'",
                $this->prefix . $this->sourceBlogId
            ],
            [
                'CREATE TABLE IF NOT EXISTS',
                'CURRENT_TIMESTAMP',
                $this->prefix . $this->destBlogId
            ],
            $createText
        );

        $this->createTableStatements->push([$destTableName => $createStatement]);

        $dropStatement = "DROP TABLE IF EXISTS {$destTableName};";

        $this->dropTableStatements->push([$destTableName => $dropStatement]);

        return $this;
    }

    protected function buildInsertRows(string $tableName): self
    {
        $insertStub = null;
        $model = new DynamicModel();

        $model->setTable($tableName);

        $rows = $model->select()->get()->toArray();

        $destTableName = $this->getDestTableName($tableName);

        collect($rows)->each(function ($row) use ($destTableName, $insertStub) {
            if (!$insertStub) {
                $insertStub = $this->buildInsertStatement($row, $destTableName);
            }

            // Save insert data
            $this->inserts->push([
                'table' => $destTableName,
                'insert' => $insertStub,
                'values' => array_values($row)
            ]);
        });

        return $this;
    }

    protected function insertBlogRecord(): void
    {
        $this->switchToDatabase($this->sourceDatabase);

        $tableName = $this->prefix . 'blogs';
        $model = new DynamicModel();
        $model->setTable($tableName);

        $blogRecord = $model->where('blog_id', $this->sourceBlogId)->first();
        $blogRecord->blog_id = $this->destBlogId;
        $blogRecord->domain = $this->destBlogUrl;

        $record = $blogRecord->toArray();
        $values = array_values($record);

        $insertStub = $this->buildInsertStatement($record, $tableName);

        $this->switchToDatabase($this->destDatabase);
        DB::insert($insertStub, $values);

        echo $this->sourceBlogId . ' Done!' . PHP_EOL;
    }

    protected function buildInsertStatement($data, string $tableName): string
    {
        $columns = implode(',', array_keys($data));
        $placeholders = implode(',', array_fill(0, count(array_keys($data)), '?'));

        $insertStub = "INSERT INTO {$tableName} ({$columns}) VALUES($placeholders);";

        return $insertStub;
    }

}
