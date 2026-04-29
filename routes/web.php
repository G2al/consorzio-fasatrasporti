<?php

use App\Http\Controllers\Admin\DocumentDownloadController;
use App\Models\UploadedDocument;
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
        Route::get('/companies/{company}/{scope?}/pdf', [DocumentDownloadController::class, 'companyPdf'])
            ->whereIn('scope', ['all', 'company', 'employees', 'vehicles'])
            ->name('companies.pdf');
        Route::get('/templates/{template}', [DocumentDownloadController::class, 'template'])
            ->name('templates.show');
        Route::get('/templates/{template}/pdf', [DocumentDownloadController::class, 'templatePdf'])
            ->name('templates.pdf');
    });

Route::middleware('auth:admin')
    ->get('/admin/document-approvals/pending-count', fn () => response()->json([
        'count' => UploadedDocument::query()
            ->where('status', 'pending')
            ->count(),
    ]))
    ->name('admin.document-approvals.pending-count');
