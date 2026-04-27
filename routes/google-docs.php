<?php

use Illuminate\Support\Facades\Route;
use hexa_package_google_docs\Http\Controllers\GoogleDocsSettingController;

Route::middleware(['web', 'auth', 'locked', 'system_lock', 'two_factor', 'role'])->group(function () {
    Route::get('/settings/google-docs', [GoogleDocsSettingController::class, 'index'])->name('settings.google-docs');
    Route::post('/settings/google-docs', [GoogleDocsSettingController::class, 'save'])->name('settings.google-docs.save');
    Route::post('/settings/google-docs/test', [GoogleDocsSettingController::class, 'test'])->name('settings.google-docs.test');
});
