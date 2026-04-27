<?php

use App\Http\Controllers\Admin\DocumentDownloadController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->file(public_path('login.html'));
});

Route::middleware('auth:admin')
    ->prefix('admin/downloads')
    ->name('admin.downloads.')
    ->group(function (): void {
        Route::get('/documents/{document}', [DocumentDownloadController::class, 'document'])
            ->name('documents.show');
        Route::get('/companies/{company}/{scope?}', [DocumentDownloadController::class, 'company'])
            ->whereIn('scope', ['all', 'company', 'employees', 'vehicles'])
            ->name('companies.show');
        Route::get('/templates/{template}', [DocumentDownloadController::class, 'template'])
            ->name('templates.show');
    });
