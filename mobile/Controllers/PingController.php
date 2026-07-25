<?php

namespace App\Mobile\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class PingController extends Controller
{
    public function ping(): JsonResponse
    {
        $user = auth()->user();

        return response()->json([
            'success' => true,
            'server_time' => now()->format('Y-m-d\TH:i:s.u\Z'),
            'server_timestamp' => now()->timestamp,
            'status' => 'connected',
            'user' => $user?->only(['id', 'name', 'email', 'role']),
        ]);
    }

    public function serverTime(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'server_time' => now()->format('Y-m-d\TH:i:s.u\Z'),
            'server_timestamp' => now()->timestamp,
        ]);
    }
}
