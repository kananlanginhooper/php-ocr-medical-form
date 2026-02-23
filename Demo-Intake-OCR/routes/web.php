<?php

use App\Http\Controllers\FaxController;
use App\Http\Controllers\FirstNameOcrController;
use App\Http\Controllers\LastNameOcrController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('login');
});

Route::get('/fax-intake', [FaxController::class, 'index'])->name('fax.index');
Route::match(['get', 'post'], '/fax-intake/check', [FaxController::class, 'check'])->name('fax.check');
Route::get('/fax-intake/preview/{faxId}', [FaxController::class, 'preview'])->name('fax.preview');
Route::get('/pending-imports', [FaxController::class, 'pendingImports'])->name('fax.pending');
Route::post('/pending-imports/select/{recordId}', [FaxController::class, 'selectRecord'])->name('fax.select-record');
Route::post('/pending-imports/confirm', [FaxController::class, 'confirmPendingImport'])->name('fax.confirm-import');
Route::get('/pending-imports/preview/{pendingId}', [FaxController::class, 'pendingPreview'])->name('fax.pending-preview');
Route::get('/fax-image/{fpId}', [FaxController::class, 'serveFaxImage'])->name('fax.image');
Route::get('/firstname-ocr', [FirstNameOcrController::class, 'firstNameOcr'])->name('firstname.index');
Route::post('/firstname-ocr/label', [FirstNameOcrController::class, 'findFirstNameLabel'])->name('firstname.label');
Route::post('/firstname-ocr/handwritten', [FirstNameOcrController::class, 'findFirstNameHandwritten'])->name('firstname.handwritten');
Route::post('/firstname-ocr/area', [FirstNameOcrController::class, 'findFirstNameArea'])->name('firstname.area');
Route::post('/firstname-ocr/options', [FirstNameOcrController::class, 'findFirstNameOptions'])->name('firstname.options');
Route::post('/firstname-ocr/run', [FirstNameOcrController::class, 'runFirstNameOcr'])->name('firstname.run');
Route::post('/firstname-ocr/confirm', [FirstNameOcrController::class, 'confirmFirstNameOcr'])->name('firstname.confirm');
Route::get('/lastname-ocr', [LastNameOcrController::class, 'lastNameOcr'])->name('lastname.index');
Route::post('/lastname-ocr/label', [LastNameOcrController::class, 'findLastNameLabel'])->name('lastname.label');
Route::post('/lastname-ocr/handwritten', [LastNameOcrController::class, 'findLastNameHandwritten'])->name('lastname.handwritten');
Route::post('/lastname-ocr/area', [LastNameOcrController::class, 'findLastNameArea'])->name('lastname.area');
Route::post('/lastname-ocr/options', [LastNameOcrController::class, 'findLastNameOptions'])->name('lastname.options');
Route::post('/lastname-ocr/run', [LastNameOcrController::class, 'runLastNameOcr'])->name('lastname.run');
Route::post('/lastname-ocr/confirm', [LastNameOcrController::class, 'confirmLastNameOcr'])->name('lastname.confirm');
Route::get('/dob-ocr', [FaxController::class, 'dobOcr'])->name('dob.index');
Route::get('/global-state', [FaxController::class, 'globalState'])->name('global.index');
Route::post('/reset-demo', [FaxController::class, 'resetToDemo'])->name('fax.reset-demo');
