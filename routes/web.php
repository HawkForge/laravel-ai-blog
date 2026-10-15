<?php

use ByteMinus\AiBlog\Http\Controllers\BlogController;
use Illuminate\Support\Facades\Route;

Route::prefix(config('ai-blog.routes.prefix', 'blog'))
    ->middleware(config('ai-blog.routes.middleware', ['web']))
    ->name('ai-blog.')
    ->group(function () {
        Route::get('/', [BlogController::class, 'index'])->name('index');
        Route::get('/feed', [BlogController::class, 'feed'])->name('feed');
        Route::get('/category/{slug}', [BlogController::class, 'category'])->name('category');
        Route::get('/tag/{slug}', [BlogController::class, 'tag'])->name('tag');
        Route::get('/{slug}', [BlogController::class, 'show'])->name('show');
    });
