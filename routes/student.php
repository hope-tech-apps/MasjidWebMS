<?php

use App\Http\Controllers\Student\AuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('student/masjids/{masjid_id}')->whereNumber('masjid_id')->group(function () {
    Route::get('/availability',[AuthController::class,'availability']);
    Route::post('/auth/sign-in',[AuthController::class,'signIn']);
    Route::middleware(['auth:student','student.active'])->group(function () {
        Route::put('/password',[AuthController::class,'password'])->name('student.password');
        Route::post('/sign-out',[AuthController::class,'signOut'])->name('student.sign-out');
        Route::get('/timetable',[\App\Http\Controllers\Student\PortalController::class,'timetable']);
        Route::get('/school-calendar',[\App\Http\Controllers\Student\PortalController::class,'calendar']);
        Route::get('/classes/{membership_id}/work',[\App\Http\Controllers\Student\PortalController::class,'work']);
        Route::get('/classes/{membership_id}/marks',[\App\Http\Controllers\Student\PortalController::class,'marks']);
        Route::get('/classes/{membership_id}/work/{assignment_id}/hand-ins',[\App\Http\Controllers\Student\PortalController::class,'history']);
        Route::post('/classes/{membership_id}/work/{assignment_id}/hand-ins',[\App\Http\Controllers\Student\PortalController::class,'submit']);
        Route::get('/classes/{membership_id}/work/{assignment_id}/hand-ins/{hand_in_id}/download',[\App\Http\Controllers\Student\PortalController::class,'download']);
        Route::get('/me',[\App\Http\Controllers\Student\PortalController::class,'me']);
        Route::get('/classes',[\App\Http\Controllers\Student\PortalController::class,'classes']);
    });
});
