<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CompanyDataController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\VehicleController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');

Route::middleware('auth:company_api')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/profile', [AuthController::class, 'profile']);
    Route::put('/profile', [AuthController::class, 'updateProfile']);
    Route::put('/profile/password', [AuthController::class, 'updatePassword']);

    Route::get('/dashboard', [CompanyDataController::class, 'dashboard']);
    Route::get('/notifications', [CompanyDataController::class, 'notifications']);
    Route::delete('/notifications', [CompanyDataController::class, 'dismissAllNotifications']);
    Route::delete('/notifications/{notification}', [CompanyDataController::class, 'dismissNotification']);
    Route::get('/sections', [CompanyDataController::class, 'sections']);
    Route::get('/company/documents', [DocumentController::class, 'companyDocuments']);

    Route::get('/employees', [EmployeeController::class, 'index']);
    Route::post('/employees', [EmployeeController::class, 'store']);
    Route::put('/employees/{employee}', [EmployeeController::class, 'update']);
    Route::delete('/employees/{employee}', [EmployeeController::class, 'destroy']);
    Route::get('/employees/{employee}/documents', [DocumentController::class, 'employeeDocuments']);

    Route::get('/vehicles', [VehicleController::class, 'index']);
    Route::post('/vehicles', [VehicleController::class, 'store']);
    Route::put('/vehicles/{vehicle}', [VehicleController::class, 'update']);
    Route::delete('/vehicles/{vehicle}', [VehicleController::class, 'destroy']);
    Route::get('/vehicles/{vehicle}/documents', [DocumentController::class, 'vehicleDocuments']);

    Route::post('/documents', [DocumentController::class, 'upload'])->middleware('throttle:30,1');
    Route::post('/documents/bulk', [DocumentController::class, 'bulkUpload'])->middleware('throttle:20,1');
});
