<?php

namespace App\Mobile\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Boat;
use App\Models\Rental;
use App\Models\User;
use App\Http\Resources\RentalResource;
use App\Services\RentalService;
use App\Exceptions\BoatNotAvailableException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class RentalController extends Controller
{
    public function __construct(private RentalService $rentalService) {}

    public function start(Request $request): JsonResponse
    {
        $request->validate([
            'boat_id' => 'required|integer|exists:boats,id',
        ]);

        try {
            $boat = Boat::findOrFail($request->boat_id);
            $rental = $this->rentalService->startRental($boat, $request->user());

            return response()->json([
                'success' => true,
                'message' => 'Rental started successfully.',
                'rental' => new RentalResource($rental),
            ], 201);
        } catch (BoatNotAvailableException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 409);
        } catch (\Exception $e) {
            Log::error('Mobile rental start error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json([
                'success' => false,
                'message' => 'Unable to start rental. Please try again.',
            ], 500);
        }
    }

    public function end(Request $request, Rental $rental): JsonResponse
    {
        $request->validate(['notes' => 'nullable|string|max:1000']);

        try {
            if (!$request->user()->isAdmin() && !$rental->isOwnedBy($request->user()->id)) {
                return response()->json([
                    'success' => false,
                    'message' => 'You are not authorized to end this rental.',
                ], 403);
            }

            $rental = $this->rentalService->endRental($rental, $request->user(), $request->notes);

            return response()->json([
                'success' => true,
                'message' => 'Rental ended successfully. Boat is awaiting receipt confirmation.',
                'rental' => new RentalResource($rental),
            ]);
        } catch (\Exception $e) {
            Log::error('Mobile rental end error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json([
                'success' => false,
                'message' => 'Unable to end rental. Please try again.',
            ], 500);
        }
    }

    public function markReceived(Request $request, Rental $rental): JsonResponse
    {
        try {
            if (!$request->user()->isAdmin() && !$rental->isOwnedBy($request->user()->id)) {
                return response()->json([
                    'success' => false,
                    'message' => 'You are not authorized to receive this boat.',
                ], 403);
            }

            if ($rental->status->value !== 'ended') {
                return response()->json([
                    'success' => false,
                    'message' => 'Rental must be in ENDED status to receive the boat.',
                ], 400);
            }

            $rental = $this->rentalService->markReceived($rental, $request->user());

            return response()->json([
                'success' => true,
                'message' => 'Boat received and now available.',
                'rental' => new RentalResource($rental),
            ]);
        } catch (\Exception $e) {
            Log::error('Mobile mark received error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json([
                'success' => false,
                'message' => 'Unable to mark as received. Please try again.',
            ], 500);
        }
    }

    public function transfer(Request $request, Rental $rental): JsonResponse
    {
        $request->validate([
            'worker_id' => 'required|integer|exists:users,id',
        ]);

        try {
            if (!$request->user()->isAdmin()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only admins can transfer ownership.',
                ], 403);
            }

            $newWorker = User::findOrFail($request->worker_id);
            $rental = $this->rentalService->transferOwnership($rental, $newWorker, $request->user());

            return response()->json([
                'success' => true,
                'message' => "Boat ownership transferred to {$newWorker->name}.",
                'rental' => new RentalResource($rental),
            ]);
        } catch (\Exception $e) {
            Log::error('Mobile transfer error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json([
                'success' => false,
                'message' => 'Unable to transfer ownership.',
            ], 500);
        }
    }

    public function confirmReturn(Request $request, Rental $rental): JsonResponse
    {
        $request->validate([
            'returned' => 'required|boolean',
        ]);

        try {
            if (!$this->rentalService->checkWorkerOwnership($rental, $request->user())) {
                return response()->json([
                    'success' => false,
                    'message' => 'You are not authorized to confirm this rental.',
                ], 403);
            }

            if ($request->returned) {
                $rental = $this->rentalService->confirmReturn($rental, $request->user());
                return response()->json([
                    'success' => true,
                    'message' => 'Return confirmed. Rental ended.',
                    'rental' => new RentalResource($rental),
                ]);
            } else {
                $rental = $this->rentalService->markStillOut($rental, $request->user());
                return response()->json([
                    'success' => true,
                    'message' => 'Boat marked as still out. Overtime started.',
                    'rental' => new RentalResource($rental),
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Mobile rental confirm error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json([
                'success' => false,
                'message' => 'Unable to process confirmation. Please try again.',
            ], 500);
        }
    }

    public function markStillOut(Request $request, Rental $rental): JsonResponse
    {
        try {
            if (!$this->rentalService->checkWorkerOwnership($rental, $request->user())) {
                return response()->json([
                    'success' => false,
                    'message' => 'Not authorized.',
                ], 403);
            }

            $rental = $this->rentalService->markStillOut($rental, $request->user());

            return response()->json([
                'success' => true,
                'message' => 'Boat marked as still out.',
                'rental' => new RentalResource($rental),
            ]);
        } catch (\Exception $e) {
            Log::error('Mobile mark still out error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json([
                'success' => false,
                'message' => 'Unable to update status. Please try again.',
            ], 500);
        }
    }

    public function myRentals(Request $request): JsonResponse
    {
        try {
            $rentals = Rental::with(['boat'])
                ->where('worker_id', $request->user()->id)
                ->latest()
                ->paginate(20);

            return response()->json([
                'success' => true,
                'data' => RentalResource::collection($rentals),
                'pagination' => [
                    'total' => $rentals->total(),
                    'per_page' => $rentals->perPage(),
                    'current_page' => $rentals->currentPage(),
                    'last_page' => $rentals->lastPage(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Mobile my rentals error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Unable to load rentals.',
            ], 500);
        }
    }

    public function extend(Request $request, Rental $rental): JsonResponse
    {
        $request->validate([
            'minutes' => 'required|integer|min:1|max:120',
        ]);

        try {
            if (!$request->user()->isAdmin()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only admins can extend rental time.',
                ], 403);
            }

            $rental = $this->rentalService->extendTime($rental, $request->user(), (int) $request->minutes);

            return response()->json([
                'success' => true,
                'message' => "Rental extended by {$request->minutes} minutes.",
                'rental' => new RentalResource($rental),
            ]);
        } catch (\Exception $e) {
            Log::error('Mobile extend time error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json([
                'success' => false,
                'message' => 'Unable to extend time. Please try again.',
            ], 500);
        }
    }

    public function reduce(Request $request, Rental $rental): JsonResponse
    {
        $request->validate([
            'minutes' => 'required|integer|min:1|max:120',
        ]);

        try {
            if (!$request->user()->isAdmin()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only admins can reduce rental time.',
                ], 403);
            }

            $rental = $this->rentalService->reduceTime($rental, $request->user(), (int) $request->minutes);

            $isCompleted = in_array($rental->status->value, ['completed', 'overridden']);

            return response()->json([
                'success' => true,
                'message' => $isCompleted
                    ? "Time fully reduced. Rental for Boat #{$rental->boat->boat_number} completed."
                    : "Rental reduced by {$request->minutes} minutes.",
                'rental' => new RentalResource($rental),
            ]);
        } catch (\Exception $e) {
            Log::error('Mobile reduce time error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json([
                'success' => false,
                'message' => 'Unable to reduce time. Please try again.',
            ], 500);
        }
    }

    public function forceEnd(Request $request, Rental $rental): JsonResponse
    {
        try {
            if (!$request->user()->isAdmin()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only admins can force-end rentals.',
                ], 403);
            }

            $rental = $this->rentalService->forceEnd($rental, $request->user(), $request->notes);

            return response()->json([
                'success' => true,
                'message' => 'Rental force-ended successfully.',
                'rental' => new RentalResource($rental),
            ]);
        } catch (\Exception $e) {
            Log::error('Mobile force-end error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json([
                'success' => false,
                'message' => 'Unable to force-end rental. Please try again.',
            ], 500);
        }
    }

    public function allActive(Request $request): JsonResponse
    {
        try {
            $rentals = $this->rentalService->getActiveRentals();

            return response()->json([
                'success' => true,
                'data' => RentalResource::collection($rentals),
            ]);
        } catch (\Exception $e) {
            Log::error('Mobile active rentals error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Unable to load rentals.',
            ], 500);
        }
    }

    public function complete(Request $request, Rental $rental): JsonResponse
    {
        try {
            if (!$request->user()->isAdmin()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only admins can complete rentals.',
                ], 403);
            }

            $rental = $this->rentalService->adminCompleteRental($rental, $request->user());

            return response()->json([
                'success' => true,
                'message' => 'Rental marked as completed.',
                'rental' => new RentalResource($rental),
            ]);
        } catch (\Exception $e) {
            Log::error('Mobile complete rental error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json([
                'success' => false,
                'message' => 'Unable to complete rental. Please try again.',
            ], 500);
        }
    }
}
