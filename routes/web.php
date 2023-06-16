<?php

use App\Services\DatabaseService;
use App\Services\RetrieveAndConvertService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Schema\Blueprint;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});
Route::get('derp', function () {
    class FieldType
    {
        const NAME_MAPPINGS = [
            'tinyint' => 'tinyInteger',
            'bigint' => 'bigInteger',
            'int' => 'integer',
            'varchar' => 'string'
        ];

        protected string $migrationMethod = '';
        public string $methodName = '';
        public ?string $setting = null;

        protected $reflectionClass;
        protected string $fieldType;
        protected string $fieldName;

        public function __construct(string $fieldName, string $fieldType)
        {
            $this->fieldName = $fieldName;
            $this->fieldType = $fieldType;
            $this->reflectionClass = new ReflectionClass(Blueprint::class);

            // Test for parentheses
            if (preg_match('/^.*[\(\)].*$/', $fieldType, $matches)) {
                $this->migrationMethod = $this->getMethodWithParamters();
            } else {
                $method = self::NAME_MAPPINGS[$fieldType] ?? $fieldType;
                $this->methodName = $method;
                $this->migrationMethod = $method . "('" . $this->fieldName . "')";
            }
        }

        public function getMethod(): string
        {
            return $this->migrationMethod;
        }

        protected function getMethodWithParamters(): string
        {
            // Break into parts.
            preg_match('/([\w]+)(.*)/', $this->fieldType, $matches);
            $method = $matches[1];
            $method = self::NAME_MAPPINGS[$method] ?? $method;
            $this->methodName = $method;

            $params = $matches[2];

            $paramTypes = $this->getParamTypes($method);
            if (count($paramTypes) > 1) {
                switch ($paramTypes[1]) {
                    case 'array':
                        $params = str_replace(['(', ')'], ['[', ']'], $params);
                    default:
                        $params = str_replace(['(', ')'], '', $params);

                }
            }

            return $method . "('" . $this->fieldName . "', " . $params . ')';
        }

        protected function getParamTypes(string $type): array
        {
            $paramTypes = [];

            $method = $this->reflectionClass->getMethod($type);
            foreach ($method->getParameters() as $parameter) {
                $paramTypes[] = (string)$parameter->getType();
            }

            return $paramTypes;
        }
    }

    $fields = [
        'name' => "varchar(100)",
        'status' => "enum('enabled', 'disabled')",
        'flag' => "int",
    ];

    foreach ($fields as $name => $type) {
        $fieldType = new FieldType($name, $type);
        !d($fieldType->getMethod());
    }

});


Route::get('dev', function () {
    $sourceDb = 'wordpress_clarku';
    $destDb = 'sites_clarku';

    DatabaseService::setDb($destDb);
    $blogs = DB::select('SELECT domain, MAX(blog_id) AS max FROM wp_blogs GROUP BY domain');
    $destBlogId = current($blogs)->max;
    $destBlogUrl = current($blogs)->domain;

    DatabaseService::setDb($sourceDb);

    $service = new RetrieveAndConvertService();

    $service->setBlogToMigrate(101)
        ->setSourceDatabase('wordpress_clarku')
        ->setDestDatabase('sites_clarku')
        ->setDestBlogId($destBlogId)
        ->setDestBlogUrl($destBlogUrl)
        ->run();
});
