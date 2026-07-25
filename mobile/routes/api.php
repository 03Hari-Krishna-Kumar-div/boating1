<?php

use App\Mobile\Controllers\AuthController;
use App\Mobile\Controllers\DashboardController;
use App\Mobile\Controllers\BoatController;
use App\Mobile\Controllers\RentalController;
use App\Mobile\Controllers\WorkerController;
use App\Mobile\Controllers\ReportController;
use App\Mobile\Controllers\NotificationController;
use App\Mobile\Controllers\PingController;
use App\Mobile\Controllers\TimerController;
use App\Mobile\Controllers\SimulationController;
use Illuminate\Support\Facades\Route;

// ─── Unauthenticated routes ────────────────────────────────────────────────
Route::post('auth/login', [AuthController::class, 'login'])->name('mobile.auth.login');

// ─── Authenticated routes (Sanctum token) ───────────────────────────────────
Route::middleware(['auth:sanctum', 'mobile.active'])->group(function () {

    // Auth
    Route::post('auth/logout', [AuthController::class, 'logout'])->name('mobile.auth.logout');
    Route::get('auth/user', [AuthController::class, 'user'])->name('mobile.auth.user');

    // Dashboard
    Route::get('dashboard', [DashboardController::class, 'index'])->name('mobile.dashboard');

    // Connection / Ping
    Route::get('ping', [PingController::class, 'ping'])->name('mobile.ping');
    Route::get('server-time', [PingController::class, 'serverTime'])->name('mobile.server-time');

    // Timer sync
    Route::get('timer/sync', [TimerController::class, 'sync'])->name('mobile.timer.sync');

    // Boats
    Route::get('boats', [BoatController::class, 'index'])->name('mobile.boats.index');
    Route::get('boats/{boat}', [BoatController::class, 'show'])->name('mobile.boats.show');
    Route::post('boats/{boat}/maintenance', [BoatController::class, 'moveToMaintenance'])->name('mobile.boats.maintenance');
    Route::post('boats/{boat}/available', [BoatController::class, 'removeFromMaintenance'])->name('mobile.boats.available');

    // Rentals
    Route::post('rentals/start', [RentalController::class, 'start'])->name('mobile.rentals.start');
    Route::post('rentals/{rental}/end', [RentalController::class, 'end'])->name('mobile.rentals.end');
    Route::post('rentals/{rental}/confirm-return', [RentalController::class, 'confirmReturn'])->name('mobile.rentals.confirm-return');
    Route::post('rentals/{rental}/mark-still-out', [RentalController::class, 'markStillOut'])->name('mobile.rentals.mark-still-out');
    Route::post('rentals/{rental}/receive', [RentalController::class, 'markReceived'])->name('mobile.rentals.receive');
    Route::post('rentals/{rental}/transfer', [RentalController::class, 'transfer'])->name('mobile.rentals.transfer');
    Route::post('rentals/{rental}/extend', [RentalController::class, 'extend'])->name('mobile.rentals.extend');
    Route::post('rentals/{rental}/reduce', [RentalController::class, 'reduce'])->name('mobile.rentals.reduce');
    Route::post('rentals/{rental}/force-end', [RentalController::class, 'forceEnd'])->name('mobile.rentals.force-end');
    Route::post('rentals/{rental}/complete', [RentalController::class, 'complete'])->name('mobile.rentals.complete');
    Route::get('rentals/active', [RentalController::class, 'allActive'])->name('mobile.rentals.active');
    Route::get('rentals/my', [RentalController::class, 'myRentals'])->name('mobile.rentals.my');

    // Workers
    Route::get('workers', [WorkerController::class, 'index'])->name('mobile.workers.index');
    Route::get('workers/online', [WorkerController::class, 'online'])->name('mobile.workers.online');

    // Reports
    Route::get('reports/daily', [ReportController::class, 'daily'])->name('mobile.reports.daily');
    Route::get('reports/weekly', [ReportController::class, 'weekly'])->name('mobile.reports.weekly');
    Route::get('reports/monthly', [ReportController::class, 'monthly'])->name('mobile.reports.monthly');
    Route::get('reports/utilization', [ReportController::class, 'utilization'])->name('mobile.reports.utilization');
    Route::get('reports/worker-performance', [ReportController::class, 'workerPerformance'])->name('mobile.reports.worker-performance');

    // Notifications
    Route::get('notifications/unread', [NotificationController::class, 'unread'])->name('mobile.notifications.unread');
    Route::post('notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('mobile.notifications.read');
    Route::post('notifications/read-all', [NotificationController::class, 'markAllRead'])->name('mobile.notifications.read-all');

    // Simulation & Export
    Route::prefix('simulation')->name('mobile.simulation.')->group(function () {
        Route::post('generate', [SimulationController::class, 'generateDummyData'])->name('generate');
        Route::get('timers', [SimulationController::class, 'timerSimulation'])->name('timers');
        Route::post('alarms/{rental}/stop', [SimulationController::class, 'stopAlarm'])->name('alarms.stop');
        Route::get('logs', [SimulationController::class, 'activityLogs'])->name('logs');
        Route::get('summary', [SimulationController::class, 'summary'])->name('summary');
        Route::delete('clear', [SimulationController::class, 'clearData'])->name('clear');
    });

    // CSV Export
    Route::get('export/csv', [SimulationController::class, 'exportCsv'])->name('mobile.export.csv');
});
