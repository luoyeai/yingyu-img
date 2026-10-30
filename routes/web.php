<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\StorageConfigurationController;
use App\Http\Controllers\UpdateController;
use App\Http\Controllers\VaultController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::view('/login', 'login')->name('login');
    Route::post('/login', [AuthController::class, 'login']);
});
Route::middleware(['auth', 'auth.session'])->group(function () {
    Route::get('/updates', [UpdateController::class, 'index'])->name('updates');
    Route::post('/updates/upload', [UpdateController::class, 'upload'])->name('updates.upload')->middleware('throttle:6,1');
    Route::post('/updates/confirm', [UpdateController::class, 'confirm'])->name('updates.confirm');
    Route::post('/updates/recovery', [UpdateController::class, 'recovery'])->name('updates.recovery');
    Route::get('/', [VaultController::class, 'uploadPage'])->name('upload');
    Route::post('/upload', [VaultController::class, 'upload'])->name('upload.store');
    Route::get('/library', [VaultController::class, 'library'])->name('library');
    Route::get('/trash', [VaultController::class, 'library'])->name('trash');
    Route::patch('/images/{image}', [VaultController::class, 'edit'])->name('images.edit');
    Route::post('/images/batch', [VaultController::class, 'batch'])->name('images.batch');
    Route::get('/images/{id}/preview', [VaultController::class, 'preview'])->name('images.preview');
    Route::get('/images/{id}/original', [VaultController::class, 'original'])->name('images.original');
    Route::post('/groups', [VaultController::class, 'group'])->name('groups.store');
    Route::delete('/groups/{group}', [VaultController::class, 'deleteGroup'])->name('groups.destroy');
    Route::post('/settings/diagnostics', [VaultController::class, 'diagnostics'])->name('settings.diagnostics')->middleware('throttle:6,1');
    Route::post('/settings/storage', [StorageConfigurationController::class, 'store'])->name('storage.store');
    Route::put('/settings/storage/default', [StorageConfigurationController::class, 'setDefault'])->name('storage.default');
    Route::post('/settings/storage/retry', [StorageConfigurationController::class, 'retry'])->name('storage.retry')->middleware('throttle:6,1');
    Route::put('/settings/storage/{configuration}', [StorageConfigurationController::class, 'update'])->name('storage.update');
    Route::delete('/settings/storage/{configuration}', [StorageConfigurationController::class, 'destroy'])->name('storage.destroy');
    Route::post('/settings/storage/{configuration}/test', [StorageConfigurationController::class, 'test'])->name('storage.test')->middleware('throttle:6,1');
    Route::get('/settings', [VaultController::class, 'settings'])->name('settings');
    Route::put('/settings', [VaultController::class, 'saveSettings'])->name('settings.save');
    Route::post('/settings/clean-originals', [VaultController::class, 'cleanOriginals'])->name('originals.clean');
    Route::put('/password', [AuthController::class, 'password'])->name('password');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
});
