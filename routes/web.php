<?php

use App\Services\DatabaseService;
use App\Services\RetrieveAndConvertService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
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
    $t = 'wp_101_redirection_404';

    $pattern = '/([\w]+_)([\d]+)(.)/';
    $min = 25;
    $new = str_replace('wp_101_', "wp_{$min}_", $t);

    echo $new;

});

Route::get('dev', function () {
    $sourceDb = 'wordpress_clarku';
    $destDb = 'sites_clarku';

    DatabaseService::setDb($destDb);
    $blogs = DB::select('SELECT domain, MAX(blog_id) AS max FROM wp_blogs GROUP BY domain');
    $minBlogId = current($blogs)->max;

    DatabaseService::setDb($sourceDb);

    $service = new RetrieveAndConvertService();

    $service->setBlog(101, $sourceDb)
        ->setMinBlogId($minBlogId)
        ->migrate();
});
