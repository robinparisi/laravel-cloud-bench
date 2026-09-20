<?php

use App\Http\Controllers\Bench\CpuController;
use App\Http\Controllers\Bench\DatabaseController;
use App\Http\Controllers\Bench\NoopController;
use App\Http\Controllers\Bench\RuntimeInfoController;
use Illuminate\Support\Facades\Route;

/*
| These routes run outside the "web" group on purpose. Sessions and cookies
| would add a store read to every request and pollute every measurement.
*/

Route::get('info', RuntimeInfoController::class)->name('info');
Route::get('noop', NoopController::class)->name('noop');
Route::get('cpu', CpuController::class)->name('cpu');
Route::get('db/{queries}', DatabaseController::class)->whereNumber('queries')->name('db');
