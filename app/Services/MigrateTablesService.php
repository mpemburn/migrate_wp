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

    public function setDestBlogId($destBlogId): self
    {
        $this->destBlogId = $destBlogId + 1;

        return $this;
    }

    public function setDestBlogUrl($destBlogUrl): self
    {
        $this->destBlogUrl = $destBlogUrl;

        return $this;
    }

    protected function getDestTableName(string $tableName): string
    {
        return str_replace("_{$this->sourceBlogId}_", "_{$this->destBlogId}_", $tableName);
    }

    public function run(): void
    {
        $this->switchToDatabase($this->sourceDatabase);
        $dbName = $this->sourceDatabase;
        $tables = DB::select('SHOW TABLES');

        collect($tables)->each(function ($table) use ($dbName) {
            $prop = 'Tables_in_' . $dbName;
            if (stripos($table->$prop, 'wp_' . $this->sourceBlogId) !== false) {
                // Add table names to collection
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

//        !d($this->createTableStatements);
//        !d($this->dropTableStatements);
//        $this->dropTables();
        $this->createTables()
            ->insertData();

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

    protected function insertData()
    {
        DB::statement("SET sql_mode='ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        $this->inserts->each(function ($item) {
//            !d($item['insert']);
//            !d($item['values']);
            DB::insert($item['insert'], $item['values']);
        });

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
//            if ($destTableName === 'wp_25_links') {
//                dd(array_values($row));
//            }
            // Save insert data
            $this->inserts->push([
                'table' => $destTableName,
                'insert' => $insertStub,
                'values' => array_values($row)
            ]);
        });

        return $this;
    }

    protected function buildInsertStatement($data, string $tableName): string
    {
        $columns = implode(',', array_keys($data));
        $placeholders = implode(',', array_fill(0, count(array_keys($data)), '?'));

        $insertStub = "INSERT INTO {$tableName} ({$columns}) VALUES($placeholders);";

        return $insertStub;
    }

}
