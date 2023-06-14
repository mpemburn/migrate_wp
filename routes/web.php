<?php

use App\Services\DatabaseService;
use App\Services\RetrieveAndConvertService;
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

Route::get('dev', function () {

    $db = 'sites_clarku';
    DatabaseService::setDb($db);
    $service = new RetrieveAndConvertService();

    $service->setBlog(19, $db)
        ->migrate();
});
