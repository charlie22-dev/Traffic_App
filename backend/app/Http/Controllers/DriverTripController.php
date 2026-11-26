<?php

namespace App\Http\Controllers;

use App\Models\Vehicle;
use App\Services\DriverTrips;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DriverTripController extends Controller
{
    public function vehicles(Request $request): JsonResponse
    {
        return response()->json(['data' => Vehicle::where('driver_id', $request->user()->id)->with('route:id,code,name,is_simulated')->get(['id', 'route_id', 'driver_id', 'label', 'mode', 'is_simulated'])->makeHidden('driver_id')]);
    }

    public function start(Request $request, DriverTrips $trips): JsonResponse
    {
        $data = $request->validate([
            'vehicle_id' => 'required|integer', 'start_battery_pct' => 'nullable|integer|between:0,100',
            'tracking_consent' => 'required|accepted', 'privacy_notice_version' => ['required', Rule::in([config('commutelive.privacy_notice_version')])],
        ]);
        try {
            $trip = $trips->start($request->user(), $data);
        } catch (UniqueConstraintViolationException) {
            abort(409, 'An active trip already exists for this driver or vehicle.');
        }

        return response()->json(['data' => $trip, 'tracking_on' => true,
            'message' => 'Tracking ON. Your vehicle position is public during this trip. End the trip to turn tracking OFF.'], 201);
    }

    public function end(Request $request, int $trip, DriverTrips $trips): JsonResponse
    {
        $data = $request->validate(['end_battery_pct' => 'nullable|integer|between:0,100']);
        $record = DB::transaction(function () use ($request, $trip, $trips, $data) {
            $record = $trips->owned($request->user(), $trip);
            if ($record->ended_at === null) {
                $record->update(['ended_at' => now(), 'end_battery_pct' => $data['end_battery_pct'] ?? null,
                    'active_driver_id' => null, 'active_vehicle_id' => null]);
            }

            return $record;
        });

        return response()->json(['data' => $record, 'tracking_on' => false, 'message' => 'Tracking OFF. This vehicle is hidden from the live feed.']);
    }
}
