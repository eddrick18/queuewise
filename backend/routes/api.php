<?php

use App\Http\Controllers\AdminQueueHistoryController;
use App\Http\Controllers\AdminServiceController;
use App\Http\Controllers\AdminStaffController;
use App\Http\Controllers\AppointmentController;
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

Route::middleware(['auth:sanctum', 'active'])->group(
    function () {
        Route::middleware('role:customer')->group(function () {
            Route::get('/appointments', [AppointmentController::class, 'index']);
            Route::get('/appointments/slots', [AppointmentController::class, 'slots']);
            Route::get('/appointments/upcoming', [AppointmentController::class, 'upcoming']);
            Route::get('/appointments/history', [AppointmentController::class, 'history']);
            Route::patch('/appointments/{appointment}/reschedule', [AppointmentController::class, 'reschedule']);
            Route::post('/appointments', [AppointmentController::class, 'store']);
            Route::patch('/appointments/{appointment}/cancel', [AppointmentController::class, 'cancel']);
        });
        Route::middleware('role:admin')->prefix('admin')->group(function () {
            Route::get('/queue-history', [AdminQueueHistoryController::class, 'index']);
            Route::get('/staff', [AdminStaffController::class, 'index']);
            Route::post('/staff', [AdminStaffController::class, 'store']);
            Route::patch('/staff/{staff}', [AdminStaffController::class, 'update']);
            Route::get('/services', [AdminServiceController::class, 'index']);
            Route::post('/services', [AdminServiceController::class, 'store']);
            Route::patch('/services/{service}', [AdminServiceController::class, 'update']);
        });

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
                Route::get('/appointments', [AppointmentController::class, 'index']);
                Route::get('/appointments/upcoming', [AppointmentController::class, 'upcoming']);
                Route::get('/appointments/history', [AppointmentController::class, 'history']);
                Route::patch('/appointments/{appointment}/no-show', [AppointmentController::class, 'noShow']);
                Route::patch('/appointments/{appointment}/check-in', [AppointmentController::class, 'checkIn']);
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
