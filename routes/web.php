<?php

use App\Http\Controllers\AdTrackController;
use App\Http\Controllers\InstallController;
use App\Http\Controllers\PreviewController;
use App\Http\Controllers\ThemeAssetController;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

Route::get('/install/{step?}', [InstallController::class, 'show']);
Route::post('/install/{step}', [InstallController::class, 'store']);

Route::post('/_t/ad', AdTrackController::class)->withoutMiddleware([PreventRequestForgery::class]);

Route::get('/_theme/{path}', ThemeAssetController::class)->where('path', '.*');

Route::middleware('auth')->get('/preview/{site}/{path?}', PreviewController::class)->where('path', '.*')->name('site.preview');
