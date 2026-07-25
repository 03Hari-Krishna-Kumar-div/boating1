<?php

namespace App\Mobile\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Rental;
use App\Services\TimerService;
use Illuminate\Http\JsonResponse;

class TimerController extends Controller
{
    public function __construct(private TimerService $timerService) {}

    public function sync(): JsonResponse
    {
        $activeRentals = Rental::with('boat')
            ->where('status', 'active')
            ->get()
            ->map(function ($rental) {
                return [
                    'rental_id' => $rental->id,
                    'boat_id' => $rental->boat_id,
                    'effective_end_at' => $rental->effective_end_at->format('Y-m-d\TH:i:s.u\Z'),
                    'remaining_seconds' => $this->timerService->getRemainingSeconds($rental->effective_end_at),
                    'overtime_seconds' => $this->timerService->getOvertimeSeconds(
                        $rental->started_at, $rental->effective_end_at
                    ),
                    'is_warning' => $this->timerService->isInWarning($rental->effective_end_at),
                    'is_expired' => $this->timerService->isExpired($rental->effective_end_at),
                ];
            });

        return response()->json([
            'success' => true,
            'server_time' => now()->format('Y-m-d\TH:i:s.u\Z'),
            'server_timestamp' => now()->timestamp,
            'active_rentals' => $activeRentals,
        ]);
    }
}
