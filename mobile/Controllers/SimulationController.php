<?php

namespace App\Mobile\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Boat;
use App\Models\Rental;
use App\Models\User;
use App\Models\ActivityLog;
use App\Enums\BoatStatus;
use App\Enums\RentalStatus;
use App\Enums\UserRole;
use App\Services\TimerService;
use App\Services\RentalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SimulationController extends Controller
{
    public function __construct(
        private TimerService $timerService,
        private RentalService $rentalService,
    ) {}

    /**
     * Generate dummy data: 8 boats with past rentals across time periods.
     */
    public function generateDummyData(Request $request): JsonResponse
    {
        $request->validate([
            'days_back' => 'integer|min:1|max:365',
            'rentals_per_boat' => 'integer|min:1|max:50',
        ]);

        $daysBack = $request->input('days_back', 30);
        $rentalsPerBoat = $request->input('rentals_per_boat', 5);

        try {
            DB::beginTransaction();

            $admin = User::where('role', UserRole::ADMIN)->first();
            if (!$admin) {
                return response()->json([
                    'success' => false,
                    'message' => 'No admin user found. Seed the database first.',
                ], 400);
            }

            $workers = User::where('role', UserRole::WORKER)->get();
            if ($workers->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No worker users found. Seed the database first.',
                ], 400);
            }

            $boatNames = [
                'Speedster', 'Wave Rider', 'Ocean King', 'Sea Breeze',
                'Storm Chaser', 'Sun Seeker', 'Blue Marlin', 'Coastal Queen'
            ];

            $createdBoats = [];
            $totalRentals = 0;

            // Create 8 boats
            foreach (range(101, 108) as $i => $num) {
                $boat = Boat::create([
                    'boat_number' => $num,
                    'name' => $boatNames[$i] ?? "Boat $num",
                    'status' => BoatStatus::AVAILABLE,
                    'color_hex' => sprintf('#%06X', rand(0, 0xFFFFFF)),
                    'notes' => "Simulation boat - created for testing",
                ]);
                $createdBoats[] = $boat;
            }

            // Generate past rentals for each boat
            foreach ($createdBoats as $boat) {
                for ($r = 0; $r < $rentalsPerBoat; $r++) {
                    $worker = $workers->random();
                    $daysAgo = rand(1, $daysBack);
                    $startHour = rand(8, 17);
                    $durationMinutes = rand(15, 90);

                    $startedAt = now()
                        ->subDays($daysAgo)
                        ->setHour($startHour)
                        ->setMinute(rand(0, 59))
                        ->setSecond(0);

                    $expectedEnd = (clone $startedAt)->addMinutes($durationMinutes);

                    // Randomly decide if it ended on time, overdue, or still ongoing
                    $outcome = rand(0, 10);
                    if ($outcome < 6) {
                        // Completed on time
                        $actualEnd = (clone $expectedEnd)->addMinutes(rand(-5, 5));
                        $status = RentalStatus::COMPLETED;
                        $overtimeSeconds = 0;
                    } elseif ($outcome < 8) {
                        // Ended with overtime
                        $overtimeMinutes = rand(5, 30);
                        $actualEnd = (clone $expectedEnd)->addMinutes($overtimeMinutes);
                        $status = RentalStatus::COMPLETED;
                        $overtimeSeconds = $overtimeMinutes * 60;
                    } elseif ($outcome < 9) {
                        // Overdue (not yet returned)
                        $actualEnd = null;
                        $status = RentalStatus::OVERDUE;
                        $overtimeSeconds = abs(now()->diffInSeconds($expectedEnd));
                    } else {
                        // Active
                        $actualEnd = null;
                        $status = RentalStatus::ACTIVE;
                        $overtimeSeconds = 0;
                    }

                    $rental = Rental::create([
                        'boat_id' => $boat->id,
                        'worker_id' => $worker->id,
                        'started_at' => $startedAt,
                        'expected_end_at' => $expectedEnd,
                        'ended_at' => $actualEnd,
                        'actual_end_at' => $actualEnd,
                        'status' => $status,
                        'ended_by' => $actualEnd ? $admin->id : null,
                        'overtime_seconds' => $overtimeSeconds,
                        'notes' => "Simulation rental #" . ($r + 1) . " for {$boat->name}",
                        'customer_returned' => in_array($status, [RentalStatus::COMPLETED]),
                    ]);

                    // If active, update boat status
                    if ($status === RentalStatus::ACTIVE) {
                        $boat->update([
                            'status' => BoatStatus::OCCUPIED,
                            'current_rental_id' => $rental->id,
                        ]);
                    } elseif ($status === RentalStatus::OVERDUE) {
                        $boat->update([
                            'status' => BoatStatus::OVERDUE,
                            'current_rental_id' => $rental->id,
                        ]);
                    }

                    // Log activity
                    ActivityLog::create([
                        'user_id' => $worker->id,
                        'boat_id' => $boat->id,
                        'rental_id' => $rental->id,
                        'action' => 'simulation_rental_' . $status->value,
                        'details' => "Sim: {$worker->name} {$status->value} rental on {$boat->name}",
                        'created_at' => $startedAt,
                    ]);

                    $totalRentals++;
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Generated 8 boats with {$totalRentals} simulated rentals across {$daysBack} days.",
                'data' => [
                    'boats_created' => count($createdBoats),
                    'rentals_created' => $totalRentals,
                    'days_back' => $daysBack,
                    'workers_used' => $workers->count(),
                    'boat_ids' => collect($createdBoats)->pluck('id'),
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Simulation error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json([
                'success' => false,
                'message' => 'Simulation failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Run timer simulation — returns active rentals with countdown timers.
     */
    public function timerSimulation(Request $request): JsonResponse
    {
        $activeRentals = Rental::with('boat')
            ->whereIn('status', [RentalStatus::ACTIVE, RentalStatus::OVERDUE])
            ->get()
            ->map(function ($rental) {
                $effectiveEnd = $rental->effective_end_at;
                $remaining = $this->timerService->getRemainingSeconds($effectiveEnd);
                $overtime = $this->timerService->getOvertimeSeconds($rental->started_at, $effectiveEnd);
                $isWarning = $this->timerService->isInWarning($effectiveEnd);
                $isExpired = $this->timerService->isExpired($effectiveEnd);

                // Determine alarm state
                $alarmState = 'stopped';
                if ($isExpired) {
                    $alarmState = 'ringing';
                } elseif ($isWarning) {
                    $alarmState = 'warning';
                } elseif ($remaining > 0) {
                    $alarmState = 'running';
                }

                return [
                    'rental_id' => $rental->id,
                    'boat_id' => $rental->boat_id,
                    'boat_name' => $rental->boat?->name,
                    'boat_number' => $rental->boat?->boat_number,
                    'worker_name' => $rental->worker?->name,
                    'started_at' => $rental->started_at?->format('Y-m-d H:i:s'),
                    'expected_end_at' => $rental->expected_end_at?->format('Y-m-d H:i:s'),
                    'effective_end_at' => $effectiveEnd?->format('Y-m-d H:i:s'),
                    'remaining_seconds' => $remaining,
                    'remaining_formatted' => gmdate('H:i:s', $remaining),
                    'overtime_seconds' => $overtime,
                    'overtime_formatted' => $overtime > 0 ? gmdate('H:i:s', $overtime) : '00:00:00',
                    'is_warning' => $isWarning,
                    'is_expired' => $isExpired,
                    'alarm_state' => $alarmState,
                    'status' => $rental->status->value,
                ];
            });

        // Stats
        $totalActive = $activeRentals->count();
        $ringingAlarms = $activeRentals->where('alarm_state', 'ringing')->count();
        $warningAlarms = $activeRentals->where('alarm_state', 'warning')->count();
        $safeTimers = $activeRentals->where('alarm_state', 'running')->count();

        return response()->json([
            'success' => true,
            'server_time' => now()->format('Y-m-d\TH:i:s.u\Z'),
            'server_timestamp' => now()->timestamp,
            'summary' => [
                'total_active_rentals' => $totalActive,
                'alarms_ringing' => $ringingAlarms,
                'alarms_warning' => $warningAlarms,
                'timers_running' => $safeTimers,
            ],
            'rentals' => $activeRentals,
        ]);
    }

    /**
     * Stop alarm for a specific rental.
     */
    public function stopAlarm(Request $request, Rental $rental): JsonResponse
    {
        try {
            if (!in_array($rental->status->value, ['active', 'overdue'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Alarm can only be stopped for active or overdue rentals.',
                ], 400);
            }

            // Log the alarm stop
            ActivityLog::create([
                'user_id' => $request->user()->id,
                'boat_id' => $rental->boat_id,
                'rental_id' => $rental->id,
                'action' => 'alarm_stopped',
                'details' => "Alarm stopped by {$request->user()->name} for Boat #{$rental->boat?->boat_number}",
                'created_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => "Alarm stopped for Boat #{$rental->boat?->boat_number}.",
                'data' => [
                    'rental_id' => $rental->id,
                    'boat_name' => $rental->boat?->name,
                    'remaining_seconds' => $this->timerService->getRemainingSeconds($rental->effective_end_at),
                    'alarm_state' => 'stopped',
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Stop alarm error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to stop alarm.',
            ], 500);
        }
    }

    /**
     * Get all simulation activity logs.
     */
    public function activityLogs(Request $request): JsonResponse
    {
        $query = ActivityLog::with(['user', 'boat', 'rental'])
            ->latest();

        // Filter by action type
        if ($request->has('action')) {
            $query->where('action', $request->action);
        }

        // Filter by date range
        if ($request->has('date_from')) {
            $query->where('created_at', '>=', $request->date_from);
        }
        if ($request->has('date_to')) {
            $query->where('created_at', '<=', $request->date_to . ' 23:59:59');
        }

        // Filter by boat
        if ($request->has('boat_id')) {
            $query->where('boat_id', $request->boat_id);
        }

        $logs = $query->paginate($request->input('per_page', 50));

        return response()->json([
            'success' => true,
            'data' => $logs->map(fn($log) => [
                'id' => $log->id,
                'action' => $log->action,
                'description' => $log->details,
                'user' => $log->user?->name ?? 'System',
                'boat' => $log->boat?->name ?? 'N/A',
                'boat_number' => $log->boat?->boat_number,
                'rental_id' => $log->rental_id,
                'created_at' => $log->created_at?->format('Y-m-d H:i:s'),
            ]),
            'pagination' => [
                'total' => $logs->total(),
                'per_page' => $logs->perPage(),
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
            ],
        ]);
    }

    /**
     * Export all data to CSV.
     */
    public function exportCsv(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $type = $request->input('type', 'rentals');

        $filename = "boat-rental-{$type}-" . now()->format('Y-m-d-Hi') . ".csv";
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        $callback = function () use ($type, $request) {
            $handle = fopen('php://output', 'w');

            // UTF-8 BOM for Excel compatibility
            fwrite($handle, "\xEF\xBB\xBF");

            switch ($type) {
                case 'rentals':
                    $this->exportRentalsCsv($handle, $request);
                    break;
                case 'boats':
                    $this->exportBoatsCsv($handle);
                    break;
                case 'workers':
                    $this->exportWorkersCsv($handle);
                    break;
                case 'activity':
                    $this->exportActivityCsv($handle, $request);
                    break;
                case 'all':
                    $this->exportRentalsCsv($handle, $request);
                    $this->exportBoatsCsv($handle);
                    $this->exportWorkersCsv($handle);
                    $this->exportActivityCsv($handle, $request);
                    break;
                default:
                    $this->exportRentalsCsv($handle, $request);
            }

            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }

    private function exportRentalsCsv($handle, Request $request): void
    {
        fputcsv($handle, [
            'ID', 'Boat #', 'Boat Name', 'Worker', 'Started At', 'Expected End',
            'Ended At', 'Status', 'Duration (min)', 'Overtime (sec)',
            'Customer Returned', 'Notes', 'Created At'
        ]);

        $query = Rental::with(['boat', 'worker', 'endedBy']);

        if ($request->has('date_from')) {
            $query->where('started_at', '>=', $request->date_from);
        }
        if ($request->has('date_to')) {
            $query->where('started_at', '<=', $request->date_to . ' 23:59:59');
        }
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $query->chunk(200, function ($rentals) use ($handle) {
            foreach ($rentals as $r) {
                $duration = $r->started_at
                    ? $r->started_at->diffInMinutes($r->actual_end_at ?? now())
                    : 0;

                fputcsv($handle, [
                    $r->id,
                    $r->boat?->boat_number,
                    $r->boat?->name,
                    $r->worker?->name,
                    $r->started_at?->format('Y-m-d H:i:s'),
                    $r->expected_end_at?->format('Y-m-d H:i:s'),
                    $r->ended_at?->format('Y-m-d H:i:s'),
                    $r->status->value,
                    $duration,
                    $r->overtime_seconds ?? 0,
                    $r->customer_returned ? 'Yes' : 'No',
                    $r->notes,
                    $r->created_at?->format('Y-m-d H:i:s'),
                ]);
            }
        });
    }

    private function exportBoatsCsv($handle): void
    {
        fputcsv($handle, []); // empty separator row
        fputcsv($handle, ['--- BOATS ---']);
        fputcsv($handle, ['ID', 'Boat #', 'Name', 'Status', 'Color', 'Total Rentals', 'Notes']);

        Boat::withCount('rentals')->chunk(100, function ($boats) use ($handle) {
            foreach ($boats as $b) {
                fputcsv($handle, [
                    $b->id,
                    $b->boat_number,
                    $b->name,
                    $b->status->value,
                    $b->color_hex,
                    $b->rentals_count,
                    $b->notes,
                ]);
            }
        });
    }

    private function exportWorkersCsv($handle): void
    {
        fputcsv($handle, []); // empty separator row
        fputcsv($handle, ['--- WORKERS ---']);
        fputcsv($handle, ['ID', 'Name', 'Email', 'Role', 'Active', 'Last Activity']);

        User::chunk(100, function ($users) use ($handle) {
            foreach ($users as $u) {
                fputcsv($handle, [
                    $u->id,
                    $u->name,
                    $u->email,
                    $u->role->value,
                    $u->is_active ? 'Yes' : 'No',
                    $u->last_activity_at?->format('Y-m-d H:i:s'),
                ]);
            }
        });
    }

    private function exportActivityCsv($handle, Request $request): void
    {
        fputcsv($handle, []); // empty separator row
        fputcsv($handle, ['--- ACTIVITY LOGS ---']);
        fputcsv($handle, ['ID', 'Action', 'Description', 'User', 'Boat', 'Boat #', 'Rental ID', 'Timestamp']);

        $query = ActivityLog::with(['user', 'boat']);

        if ($request->has('date_from')) {
            $query->where('created_at', '>=', $request->date_from);
        }
        if ($request->has('date_to')) {
            $query->where('created_at', '<=', $request->date_to . ' 23:59:59');
        }

        $query->chunk(200, function ($logs) use ($handle) {
            foreach ($logs as $log) {
                fputcsv($handle, [
                    $log->id,
                    $log->action,
                    $log->details,
                    $log->user?->name ?? 'System',
                    $log->boat?->name ?? 'N/A',
                    $log->boat?->boat_number,
                    $log->rental_id,
                    $log->created_at?->format('Y-m-d H:i:s'),
                ]);
            }
        });
    }

    /**
     * Get simulation summary statistics.
     */
    public function summary(): JsonResponse
    {
        $totalBoats = Boat::count();
        $totalRentals = Rental::count();
        $simBoats = Boat::where('boat_number', '>=', 101)->count();

        $statusBreakdown = Rental::selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $avgDuration = Rental::whereNotNull('actual_end_at')
            ->get()
            ->avg(fn($r) => $r->started_at->diffInMinutes($r->actual_end_at));

        $overtimeTotal = Rental::sum('overtime_seconds');

        $logsCount = ActivityLog::count();

        return response()->json([
            'success' => true,
            'data' => [
                'total_boats' => $totalBoats,
                'simulation_boats' => $simBoats,
                'total_rentals' => $totalRentals,
                'rentals_by_status' => $statusBreakdown,
                'average_duration_minutes' => round($avgDuration ?? 0, 1),
                'total_overtime_seconds' => $overtimeTotal,
                'total_overtime_hours' => round($overtimeTotal / 3600, 2),
                'activity_log_entries' => $logsCount,
                'server_time' => now()->format('Y-m-d\TH:i:s.u\Z'),
            ],
        ]);
    }

    /**
     * Clear all simulation data (boats with number >= 101 and their rentals).
     */
    public function clearData(): JsonResponse
    {
        try {
            DB::beginTransaction();

            $simBoats = Boat::where('boat_number', '>=', 101)->get();
            $boatIds = $simBoats->pluck('id');

            // Delete associated rentals
            Rental::whereIn('boat_id', $boatIds)->delete();

            // Delete associated activity logs
            ActivityLog::whereIn('boat_id', $boatIds)->delete();

            // Delete the boats
            Boat::whereIn('id', $boatIds)->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Cleared {$simBoats->count()} simulation boats and all associated data.",
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Clear simulation error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to clear simulation data.',
            ], 500);
        }
    }
}
