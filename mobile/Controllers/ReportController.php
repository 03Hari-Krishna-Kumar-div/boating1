<?php

namespace App\Mobile\Controllers;

use App\Http\Controllers\Controller;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(private ReportService $reportService) {}

    public function daily(Request $request): JsonResponse
    {
        $date = \Carbon\Carbon::parse($request->get('date', now()->toDateString()));
        $report = $this->reportService->daily($date);

        return response()->json([
            'success' => true,
            'data' => $report,
        ]);
    }

    public function weekly(Request $request): JsonResponse
    {
        $start = \Carbon\Carbon::parse($request->get('start', now()->startOfWeek()->toDateString()));
        $report = $this->reportService->weekly($start);

        return response()->json([
            'success' => true,
            'data' => $report,
        ]);
    }

    public function monthly(Request $request): JsonResponse
    {
        $year = (int) $request->get('year', now()->year);
        $month = (int) $request->get('month', now()->month);
        $report = $this->reportService->monthly($year, $month);

        return response()->json([
            'success' => true,
            'data' => $report,
        ]);
    }

    public function utilization(Request $request): JsonResponse
    {
        $start = \Carbon\Carbon::parse($request->get('date_from', now()->startOfMonth()->toDateString()));
        $end = \Carbon\Carbon::parse($request->get('date_to', now()->endOfMonth()->toDateString()));
        $report = $this->reportService->utilization($start, $end);

        return response()->json([
            'success' => true,
            'data' => $report,
        ]);
    }

    public function workerPerformance(Request $request): JsonResponse
    {
        $start = \Carbon\Carbon::parse($request->get('date_from', now()->startOfMonth()->toDateString()));
        $end = \Carbon\Carbon::parse($request->get('date_to', now()->endOfMonth()->toDateString()));
        $report = $this->reportService->workerPerformance($start, $end);

        return response()->json([
            'success' => true,
            'data' => $report,
        ]);
    }
}
