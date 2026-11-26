<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['commuter', 'driver', 'admin'])->default('commuter')->index();
            $table->boolean('is_simulated')->default(false);
        });
        foreach (['routes', 'stops', 'route_stops', 'route_segments'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->boolean('is_simulated')->default(false));
        }
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('route_id')->constrained()->restrictOnDelete();
            $table->string('label', 100)->unique();
            $table->enum('mode', ['jeepney', 'bus']);
            $table->boolean('is_simulated')->default(false);
            $table->timestamps();
        });
        Schema::create('trips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->restrictOnDelete();
            $table->foreignId('driver_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('route_id')->constrained()->restrictOnDelete();
            // Nullable unique keys enforce one active trip per driver AND vehicle.
            $table->foreignId('active_driver_id')->nullable()->unique()->constrained('users')->restrictOnDelete();
            $table->foreignId('active_vehicle_id')->nullable()->unique()->constrained('vehicles')->restrictOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable()->index();
            $table->unsignedTinyInteger('start_battery_pct')->nullable();
            $table->unsignedTinyInteger('end_battery_pct')->nullable();
            $table->timestamp('consented_at');
            $table->string('consent_notice_version', 40);
            $table->boolean('is_simulated')->default(false);
            $table->timestamps();
        });
        Schema::create('location_pings', function (Blueprint $table) {
            $table->id();
            $table->uuid('client_ping_uuid')->unique();
            $table->string('payload_hash', 64);
            $table->foreignId('vehicle_id')->constrained()->restrictOnDelete();
            $table->foreignId('trip_id')->constrained()->restrictOnDelete();
            $table->double('lat');
            $table->double('lng');
            $table->double('speed_kph')->nullable();
            $table->double('heading')->nullable();
            $table->double('accuracy_m');
            $table->timestamp('recorded_at')->index();
            $table->timestamp('received_at')->index();
            $table->double('distance_along_route_m')->nullable();
            $table->double('offset_m');
            $table->foreignId('segment_id')->nullable()->constrained('route_segments')->nullOnDelete();
            $table->boolean('is_off_route')->default(false);
            $table->boolean('is_simulated')->default(false);
            $table->index(['trip_id', 'is_off_route', 'recorded_at']);
        });
        Schema::create('vehicle_status', function (Blueprint $table) {
            $table->foreignId('vehicle_id')->primary()->constrained()->cascadeOnDelete();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->enum('occupancy', ['empty', 'seats', 'full']);
            $table->timestamp('updated_at');
            $table->boolean('is_simulated')->default(false);
        });
        Schema::create('incident_reports', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['heavy_traffic', 'road_closed', 'accident']);
            $table->double('lat');
            $table->double('lng');
            $table->foreignId('route_id')->constrained()->restrictOnDelete();
            $table->foreignId('reporter_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('trip_id')->constrained()->restrictOnDelete();
            $table->timestamp('expires_at')->index();
            $table->boolean('is_simulated')->default(false);
            $table->timestamps();
        });
        Schema::create('segment_baselines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('segment_id')->constrained('route_segments')->cascadeOnDelete();
            $table->enum('day_type', ['weekday', 'weekend']);
            $table->unsignedTinyInteger('hour');
            $table->double('avg_speed_kph');
            $table->unsignedInteger('sample_count');
            $table->boolean('is_simulated')->default(false);
            $table->unique(['segment_id', 'day_type', 'hour', 'is_simulated'], 'baseline_window_unique');
        });
        Schema::create('segment_speed_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('segment_id')->constrained('route_segments')->cascadeOnDelete();
            $table->timestamp('window_start');
            $table->double('avg_speed_kph');
            $table->unsignedInteger('sample_count');
            $table->boolean('is_simulated')->default(false);
            $table->unique(['segment_id', 'window_start', 'is_simulated'], 'speed_window_unique');
        });
        Schema::create('eta_predictions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->restrictOnDelete();
            $table->foreignId('stop_id')->constrained()->restrictOnDelete();
            $table->timestamp('predicted_at');
            $table->timestamp('predicted_arrival_at');
            $table->timestamp('actual_arrival_at')->nullable();
            $table->string('method', 80);
            $table->boolean('is_simulated')->default(false);
        });
    }

    public function down(): void
    {
        foreach (['eta_predictions', 'segment_speed_stats', 'segment_baselines', 'incident_reports', 'vehicle_status', 'location_pings', 'trips', 'vehicles'] as $name) {
            Schema::dropIfExists($name);
        }
        foreach (['route_segments', 'route_stops', 'stops', 'routes'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('is_simulated'));
        }
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['role', 'is_simulated']));
    }
};
