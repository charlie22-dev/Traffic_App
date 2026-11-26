<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('routes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 80)->unique();
            $table->string('name');
            $table->string('mode', 20);
            $table->string('direction', 120);
            $table->string('color', 7);
            $table->json('geometry_geojson');
            $table->double('length_m');
            $table->boolean('is_active')->default(true);
            $table->string('verification_status', 60);
            $table->json('source_metadata');
            $table->timestamps();
        });
        Schema::create('stops', function (Blueprint $table) {
            $table->id();
            $table->string('code', 100)->unique();
            $table->string('name');
            $table->double('lat');
            $table->double('lng');
            $table->string('source');
            $table->string('verification_status', 60);
            $table->timestamps();
        });
        Schema::create('route_stops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('route_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stop_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('sequence');
            $table->double('distance_along_route_m');
            $table->double('offset_m');
            // A loop can visit the same stop twice; sequence is the identity.
            $table->unique(['route_id', 'sequence']);
        });
        Schema::create('route_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('route_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->double('start_m');
            $table->double('end_m');
            $table->double('length_m');
            $table->json('polyline');
            $table->unique(['route_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('route_segments');
        Schema::dropIfExists('route_stops');
        Schema::dropIfExists('stops');
        Schema::dropIfExists('routes');
    }
};
