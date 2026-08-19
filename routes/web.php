<?php

use App\Http\Controllers\MeetingController;
use Illuminate\Support\Facades\Route;

Route::get('/', [MeetingController::class, 'index'])->name('home');

Route::get('/meet/{meeting:uuid}', [MeetingController::class, 'show'])
    ->where('meeting', '[0-9a-fA-F\-]{36}')
    ->name('meeting.show');

Route::post('/meet/{meeting:uuid}/heartbeat', [MeetingController::class, 'heartbeat'])
    ->where('meeting', '[0-9a-fA-F\-]{36}')
    ->name('meeting.heartbeat');
