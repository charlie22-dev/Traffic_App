<?php

namespace App\Http\Controllers;

use App\Services\PingIngestor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PingController extends Controller
{
    public function store(Request $request, PingIngestor $ingestor): JsonResponse
    {
        $data = $request->validate([
            'trip_id' => 'required|integer', 'pings' => 'required|array|min:1|max:'.config('commutelive.ping_batch_limit'),
            'pings.*' => 'required|array', 'vehicle_id' => 'prohibited', 'route_id' => 'prohibited',
        ]);

        return response()->json(['data' => $ingestor->ingest($request->user(), $data['trip_id'], $data['pings'])]);
    }
}
