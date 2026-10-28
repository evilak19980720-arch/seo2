<?php

use App\Http\Controllers\AdTrackController;
use App\Http\Controllers\InstallController;
use App\Http\Controllers\PreviewController;
use App\Http\Controllers\ThemeAssetController;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

// The admin host is never indexed. (No public/robots.txt or favicon.ico: on panel-managed
// servers Nginx would serve those files to every brand domain instead of the site's own.)
Route::get('/robots.txt', fn () => response("User-agent: *\nDisallow: /\n", 200, ['Content-Type' => 'text/plain']));

Route::get('/install/{step?}', [InstallController::class, 'show']);
Route::post('/install/{step}', [InstallController::class, 'store']);

// Ad beacon: "/_t/ad" or the site's own prefix (SiteRenderer::trackingPrefix).
Route::post('/{prefix}/ad', AdTrackController::class)->where('prefix', '_t|x[0-9a-f]{6}')->withoutMiddleware([PreventRequestForgery::class]);

Route::get('/_theme/{path}', ThemeAssetController::class)->where('path', '.*');

Route::middleware('auth')->get('/preview/{site}/{path?}', PreviewController::class)->where('path', '.*')->name('site.preview');
