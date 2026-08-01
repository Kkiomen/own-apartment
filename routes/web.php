<?php

use App\Infrastructure\Http\Controllers\ListingsController;
use Illuminate\Support\Facades\Route;

Route::get('/', ListingsController::class)->name('listings.index');
