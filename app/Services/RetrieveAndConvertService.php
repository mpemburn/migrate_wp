<?php

namespace App\Services;

use App\Helpers\MigrationField;
use App\Models\DynamicModel;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class RetrieveAndConvertService
{
    const NULLABLE_FIELD_TYPES = [
        'varchar'
    ];

    protected int $sourceBlogId;
    protected string $sourceDatabase;
    protected string $destDatabase;
    protected int $destBlogId;
    protected string $destBlogUrl;
    protected string $datetimePrefix;
    protected string $migrationsPath;
    protected Collection $blogTables;
    protected Collection $migrations;
    protected Collection $inserts;

    public function __construct()
    {
        $this->blogTables = collect();
        $this->migrations = collect();
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
            $this->createMigrations($tableName)
                ->buildInsertRows($tableName);
        });

        $this->saveMigrations()
            ->persistData();

    }

    protected function getClassName($tableName): string
    {
        $camelTable = str_replace(' ', '', ucwords(str_replace('_', ' ', $tableName)));
        $classname = sprintf("Create%sTable", $camelTable);

        return $classname;
    }

    protected function getDestTableName(string $tableName): string
    {
        return str_replace("_{$this->sourceBlogId}_", "_{$this->destBlogId}_", $tableName);
    }

    protected function persistData()
    {
        $this->switchToDatabase($this->destDatabase);
        Artisan::call('migrate');
    }

    public function createMigrations($tableName): self
    {
        $destTableName = $this->getDestTableName($tableName);

        $columns = DB::select("SHOW FULL COLUMNS FROM {$tableName};");
        $tableSchemaCodes = [];

        collect($columns)->each(function ($column) use (&$tableSchemaCodes, $destTableName) {
            $fieldName = $column->Field;
            $columnType = $column->Type;
            $isNull = $column->Null;
            $default = $column->Default;
            $extra = $column->Extra;
            $comment = $column->Comment;

            $migrationField = new MigrationField($fieldName, $columnType);

            if ($extra == 'auto_increment' && $fieldName == 'id') {
                $migrationField->setFieldName('increments');
            }

            $appends = [];
            if ($isNull === 'YES' && in_array($migrationField->getFieldName(), self::NULLABLE_FIELD_TYPES)) {
                $appends [] = '->nullable()';
            }
            if ('unsigned' === $migrationField->getSqlFieldType() && $migrationField->getFieldName() !== 'increments') {
                $appends [] = '->unsigned()';
            }
            if (!is_null($default)) {
                if ($default === 'CURRENT_TIMESTAMP' || $default === '0000-00-00 00:00:00') {
                    $appends [] = sprintf("->default(DB::raw('%s'))", 'CURRENT_TIMESTAMP');
                } else {
                    $appends [] = sprintf("->default('%s')", $default);
                }
            }
            if ($comment) {
                $appends [] = "->comment('{$comment}')";
            }

            $migrationParams = array_merge([sprintf("'%s'", $fieldName)],);
            $migrationParams = array_filter($migrationParams, function ($param) {
                return trim($param) != "";
            });

            $tableSchemaCode = sprintf("    \$table->%s%s;", $migrationField->getMethod(), implode("", $appends));
            $tableSchemaCodes[] = $tableSchemaCode;
        });

        $indexes = $this->getIndexes($tableName);

        if (!empty($indexes)) {
            foreach ($indexes as $indexName => $index) {
                $tableSchemaCodes[] = '    $table->' . ($index['is_unique'] ? 'unique' : 'index') . '(["' . implode('", "', $index['keys']) . '"]);';
            }
        }

        $classname = $this->getClassName($destTableName);

        $tableSchemaCodes = implode("\n        ", $tableSchemaCodes);

        $migration = $this->getMigrationStub($classname, $destTableName, $tableSchemaCodes);

        $this->migrations->push([$this->getMigrationFilename($destTableName) => $migration]);

        return $this;
    }

    protected function saveMigrations(): self
    {
        $this->migrations->each(function ($migration) {
            $filename = key($migration);
            $migration = current($migration);

            $this->saveMigration($filename, $migration);
        });

        return $this;
    }

    protected function getIndexes($tableName): array
    {
        $indexes = [];

        $query = DB::select("SHOW INDEX FROM {$tableName};");
        collect($query)->each(function ($index) use (&$indexes) {
            if ($index->Key_name === 'PRIMARY') {
                return;
            }
            $indexes[$index->Key_name]['is_unique'] = $index->Non_unique === 0;
            $indexes[$index->Key_name]['keys'][] = $index->Column_name;
        });

        return $indexes;
    }

    protected function getMigrationFilename($tableName): string
    {
        return sprintf("%s_create_%s_table.php", $this->datetimePrefix, $tableName);
    }

    protected function getMigrationStub(string $classname, string $tableName, string $schemaCodes): string
    {
        return "
<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class {$classname} extends Migration
{
    /**
     * Run the migrations.
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('{$tableName}')) {
            return;
        }
        Schema::create('{$tableName}', function (Blueprint \$table) {
        {$schemaCodes}
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('{$tableName}');
    }
}";
    }

    protected function saveMigration(string $filename, string $migrationData): void
    {
        $migrationsPath = base_path() . '/database/migrations/';
        $contents = 'this string';
        $migration = $migrationsPath . $filename;
        file_put_contents ( $migration , $migrationData );
    }

    protected function buildInsertRows(string $tableName): self
    {
        $insertStub = null;
        $model = new DynamicModel();

        $model->setTable($tableName);

        $rows = $model->get()->toArray();

        $destTableName = $this->getDestTableName($tableName);

        collect($rows)->each(function ($row) use ($destTableName, $insertStub) {
            if (!$insertStub) {
                $insertStub = $this->buildInsertStatement($row, $destTableName);
            }
            // Save insert data
            $this->inserts->push([$this->sourceBlogId => [
                'table' => $destTableName,
                'insert' => $insertStub,
                'values' => array_values($row)
                ]
            ]);

            //DB::insert($insertStub, array_values($row));
        });

        return $this;
    }

    protected function insertBlogRecord(): void
    {
        $tableName = 'wp_blogs';
        $model = new DynamicModel();
        $model->setTable($tableName);

        $blogRecord = $model->where('blog_id', $this->sourceBlogId)->first();
        $blogRecord->blog_id = $this->destBlogId;
        $blogRecord->domain = $this->destBlogUrl;

        $record = $blogRecord->toArray();
        $values = array_values($record);

        $insertStub = $this->buildInsertStatement($record, $tableName);

        DB::insert($insertStub, $values);
    }

    protected function buildInsertStatement($data, string $tableName): string
    {
        $columns = implode(',', array_keys($data));
        $placeholders = implode(',', array_fill(0, count(array_keys($data)), '?'));

        $insertStub = "INSERT INTO {$tableName} ({$columns}) VALUES($placeholders);";

        return $insertStub;
    }

}
