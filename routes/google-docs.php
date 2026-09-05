<?php

use Illuminate\Support\Facades\Route;
use hexa_package_google_docs\Http\Controllers\GoogleDocsSettingController;

Route::middleware(['web', 'auth', 'locked', 'system_lock', 'two_factor', 'role'])->group(function () {
    Route::get('/settings/google-docs', [GoogleDocsSettingController::class, 'index'])->name('settings.google-docs');
    Route::post('/settings/google-docs/accounts', [GoogleDocsSettingController::class, 'createAccount'])->name('settings.google-docs.accounts');
    Route::post('/settings/google-docs/default-account', [GoogleDocsSettingController::class, 'setDefaultAccount'])->name('settings.google-docs.default-account');
    Route::post('/settings/google-docs/general', [GoogleDocsSettingController::class, 'saveGeneral'])->name('settings.google-docs.general');
    Route::post('/settings/google-docs/oauth', [GoogleDocsSettingController::class, 'saveOauth'])->name('settings.google-docs.oauth');
    Route::post('/settings/google-docs/service-account', [GoogleDocsSettingController::class, 'saveServiceAccount'])->name('settings.google-docs.service-account');
    Route::post('/settings/google-docs/test-read', [GoogleDocsSettingController::class, 'testRead'])->name('settings.google-docs.test-read');
    Route::post('/settings/google-docs/test-write', [GoogleDocsSettingController::class, 'testWrite'])->name('settings.google-docs.test-write');
    Route::post('/settings/google-docs/create-folder', [GoogleDocsSettingController::class, 'createFolder'])->name('settings.google-docs.create-folder');
    Route::post('/settings/google-docs/smoke', [GoogleDocsSettingController::class, 'smoke'])->name('settings.google-docs.smoke');
});
