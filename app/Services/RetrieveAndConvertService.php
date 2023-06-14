<?php

namespace App\Services;

use App\Helpers\FieldType;
use App\Models\DynamicModel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RetrieveAndConvertService
{
    const NULLABLE_FIELD_TYPES = [
        'varchar'
    ];

    protected $timezone;
    protected $blogId;
    protected Collection $blogTables;
    protected Collection $migrations;
    protected Collection $inserts;

    public function __construct()
    {
        $this->timezone = env('APP_TIMEZONE');
        $this->blogTables = collect();
        $this->migrations = collect();
        $this->inserts = collect();
    }

    public function setBlog(int $blogId, ?string $database = null): self
    {
        $this->blogId = $blogId;
        $dbName = $database ? $database : env('DB_DATABASE');
        $tables = DB::select('SHOW TABLES');
        collect($tables)->each(function ($table) use ($blogId, $dbName) {
            $prop = 'Tables_in_' . $dbName;
            if (stripos($table->$prop, 'wp_' . $blogId) !== false) {
                // Add table names to collection
                $this->blogTables->push($table->$prop);
            }
        });

        return $this;
    }

    public function migrate()
    {
        $this->blogTables->each(function ($tableName) {
            if ($tableName !== 'wp_19_posts') {
                return;
            }
            $this->createMigrations($tableName)
                ->buildInsertRows($tableName);
        });

        !d($this->migrations);
        !d($this->inserts);
    }

    public function createMigrations($tableName): self
    {
        $columns = DB::select("SHOW FULL COLUMNS FROM {$tableName};");
        $tableSchemaCodes = [];

        collect($columns)->each(function ($column) use (&$tableSchemaCodes) {
            $field = $column->Field;
            $columnType = $column->Type;
            $collation = $column->Collation;
            $isNull = $column->Null;
            $key = $column->Key;
            $default = $column->Default;
            $extra = $column->Extra;
            $privileges = $column->Privileges;
            $comment = $column->Comment;

            $fieldType = $this->getFieldTypeParts($columnType);
            if ($extra == 'auto_increment' && $field == 'id') {
                $field_type_name = 'increments';
            }

            $appends = [];
            if ($isNull === 'YES' && in_array($fieldType->name, self::NULLABLE_FIELD_TYPES)) {
                $appends [] = '->nullable()';
            }
            if ('unsigned' === $fieldType->setting && $fieldType->name !== 'increments') {
                $appends [] = '->unsigned()';
            }
            if (!is_null($default)) {
                if ($default == 'CURRENT_TIMESTAMP') {
                    $appends [] = sprintf("->default(\DB::raw('%s'))", $default);
                } else {
                    $appends [] = sprintf("->default('%s')", $default);
                }
            }
            if ($comment) {
                $appends [] = "->comment('{$comment}')";
            }

            $migrationParams = array_merge([sprintf("'%s'", $field)],);
            $migrationParams = array_filter($migrationParams, function ($param) {
                return trim($param) != "";
            });

            $tableSchemaCode = sprintf("    \$table->%s(%s)%s;", $fieldType->name, implode(", ", $migrationParams), implode("", $appends));

            $tableSchemaCodes[] = $tableSchemaCode;
        });

        $tableSchemaCode = '    $table->timestamps();';
        $tableSchemaCodes[] = $tableSchemaCode;

        $indexes = [];
        $query = DB::select("SHOW INDEX FROM {$tableName};");
        collect($query)->each(function ($index) use (&$indexes) {
            if ($index->Key_name === 'PRIMARY') {
                return;
            }
            $indexes[$index->Key_name]['is_unique'] = $index->Non_unique === 0;
            $indexes[$index->Key_name]['keys'][] = $index->Column_name;
        });

        if (!empty($indexes)) {
            foreach ($indexes as $indexName => $index) {
                $tableSchemaCodes[] = '    $table->' . ($index['is_unique'] ? 'unique' : 'index') . '(["' . implode('", "', $index['keys']) . '"]);';
            }
        }

        $camelTable = str_replace(' ', '', ucwords(str_replace('_', ' ', $tableName)));
        $classname = sprintf("Create%sTable", $camelTable);

        $tableSchemaCodes = implode("\n        ", $tableSchemaCodes);

        $migration = $this->getMigrationStub($classname, $tableName, $tableSchemaCodes);

        $this->migrations->push([$this->blogId => $migration]);

        return $this;
    }

    protected function getFieldTypeParts(string $fieldType): FieldType
    {
        return (new FieldType())->set($fieldType);
    }

    protected function getMigrationStub(string $classname, string $tableName, string $schemaCodes): string
    {
        return "
<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class {$classname} extends Migration
{
    /**
     * Run the migrations.
     * @return void
     */
    public function up()
    {
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

    public function buildInsertRows(string $tableName): self
    {
        $insertStub = null;
        $model = new DynamicModel();

        $model->setTable($tableName);

        $rows = $model->get()->toArray();

        collect($rows)->each(function ($row) use ($tableName, $insertStub) {
            if (!$insertStub) {
                $columns = implode(',', array_keys($row));
                $qs = implode(',', array_fill(0, count(array_keys($row)), '?'));

                $insertStub = "INSERT INTO {$tableName} ({$columns}) VALUES($qs);";
            }
            // Save insert data
            $this->inserts->push([$this->blogId => [
                'table' => $tableName,
                'insert' => $insertStub,
                'values' => array_values($row)
                ]
            ]);

            //DB::insert($insertStub, array_values($row));
        });

        return $this;
    }

}
