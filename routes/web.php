<?php

use App\Models\Test;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/admin/login');
});

Route::get('/playwright-reports/{test}/index', function (Test $test) {
    $path = storage_path("app/playwright/test-{$test->id}/reports/index.html");
    abort_unless(File::exists($path), 404);

    return response(File::get($path), 200, [
        'Content-Type' => 'text/html; charset=UTF-8',
        'X-Frame-Options' => 'SAMEORIGIN',
    ]);
})->middleware('signed')->name('playwright-reports.index');

Route::get('/playwright-reports/{test}/{path}', function (Test $test, string $path) {
    $relativePath = ltrim($path, '/');
    $isAllowedAsset = str_starts_with($relativePath, 'data/') || str_starts_with($relativePath, 'trace/');
    abort_unless($isAllowedAsset, 404);

    $fullPath = storage_path("app/playwright/test-{$test->id}/reports/{$relativePath}");
    abort_unless(File::exists($fullPath) && File::isFile($fullPath), 404);

    $mimeType = File::mimeType($fullPath) ?: 'application/octet-stream';

    return response(File::get($fullPath), 200, [
        'Content-Type' => $mimeType,
        'X-Frame-Options' => 'SAMEORIGIN',
    ]);
})->where('path', '.*')->name('playwright-reports.asset');
