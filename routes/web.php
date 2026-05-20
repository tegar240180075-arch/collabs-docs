<?php
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DocumentController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login',            [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login',           [AuthController::class, 'login'])->name('login.post');
    Route::get('/register',         [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register',        [AuthController::class, 'register'])->name('register.post');
});

Route::post('/logout', [AuthController::class, 'logout'])->name('logout')->middleware('auth');

Route::middleware('auth')->group(function () {
    Route::get('/',                                             [DocumentController::class, 'index'])->name('documents.index');
    Route::post('/documents',                                   [DocumentController::class, 'create'])->name('documents.create');
    Route::get('/documents/{document}/editor',                  [DocumentController::class, 'editor'])->name('documents.editor');
    Route::put('/documents/{document}',                         [DocumentController::class, 'update'])->name('documents.update');
    Route::delete('/documents/{document}',                      [DocumentController::class, 'destroy'])->name('documents.destroy');
    Route::post('/documents/{document}/cursor',                 [DocumentController::class, 'cursor'])->name('documents.cursor');
    Route::get('/documents/{document}/versions',                [DocumentController::class, 'versions'])->name('documents.versions');
    Route::post('/documents/{document}/versions/{version}/restore', [DocumentController::class, 'restoreVersion'])->name('documents.restore');
    Route::post('/documents/{document}/versions/save',          [DocumentController::class, 'saveVersion'])->name('documents.saveVersion');

    Route::post('/documents/{document}/share',                  [DocumentController::class, 'share'])->name('documents.share');
    Route::delete('/documents/{document}/share/{user}',         [DocumentController::class, 'removeShare'])->name('documents.removeShare');
    Route::get('/documents/{document}/shared-users',            [DocumentController::class, 'sharedUsers'])->name('documents.sharedUsers');
    Route::delete('/documents/{document}/leave-share',           [DocumentController::class, 'leaveShare'])->name('documents.leaveShare');
});
