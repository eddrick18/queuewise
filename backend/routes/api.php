<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\QueueController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\StaffQueueController;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json([
        'success' => true,
        'message' => 'QueueWise API is connected',
    ]);
});

Route::middleware('auth:sanctum')->group(
    function () {
        /*
         * Current authenticated user.
         */
        Route::get('/user', [
            AuthController::class,
            'user',
        ]);

        /*
         * Available QueueWise services.
         */
        Route::get('/services', [
            ServiceController::class,
            'index',
        ]);

        /*
         * Customer queue routes.
         */
        Route::get('/queue/current', [
            QueueController::class,
            'current',
        ]);

        Route::post('/queue/join', [
            QueueController::class,
            'join',
        ]);

        Route::patch(
            '/queue/{queueEntry}/cancel',
            [
                QueueController::class,
                'cancel',
            ],
        );

        /*
         * Staff and administrator routes.
         */
        Route::middleware('role:staff,admin')
            ->prefix('staff')
            ->group(function () {
                Route::get('/queue', [
                    StaffQueueController::class,
                    'index',
                ]);

                Route::post('/queue/call-next', [
                    StaffQueueController::class,
                    'callNext',
                ]);

                Route::patch(
                    '/queue/{queueEntry}/serve',
                    [StaffQueueController::class, 'serve'],
                );

                Route::patch(
                    '/queue/{queueEntry}/skip',
                    [StaffQueueController::class, 'skip'],
                );

                Route::patch(
                    '/queue/{queueEntry}/complete',
                    [
                        StaffQueueController::class,
                        'complete',
                    ],
                );
            });
    },
);
