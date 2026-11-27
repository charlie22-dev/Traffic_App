<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('segment_speed_stats', fn (Blueprint $t) => $t->timestamp('computed_at')->nullable()->index());
        Schema::table('segment_baselines', function (Blueprint $t) {
            $t->unsignedInteger('day_count')->default(0);
            $t->timestamp('computed_at')->nullable();
        });
        Schema::table('location_pings', fn (Blueprint $t) => $t->index(['segment_id', 'is_simulated', 'recorded_at'], 'segment_samples_index'));
        Schema::table('eta_predictions', function (Blueprint $t) {
            $t->foreignId('trip_id')->nullable()->constrained()->restrictOnDelete();
            $t->timestamp('naive_arrival_at')->nullable();
            $t->string('actual_arrival_source')->nullable();
            $t->json('context')->nullable();
            $t->index(['trip_id', 'stop_id', 'predicted_at']);
        });
        Schema::create('stop_arrivals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $t->foreignId('stop_id')->constrained()->restrictOnDelete();
            $t->timestamp('arrived_at');
            $t->string('source');
            $t->boolean('is_simulated');
            $t->unique(['trip_id', 'stop_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stop_arrivals');
        Schema::table('eta_predictions', function (Blueprint $t) {
            $t->dropIndex(['trip_id', 'stop_id', 'predicted_at']);
            $t->dropConstrainedForeignId('trip_id');
            $t->dropColumn(['naive_arrival_at', 'actual_arrival_source', 'context']);
        });
        Schema::table('location_pings', fn (Blueprint $t) => $t->dropIndex('segment_samples_index'));
        Schema::table('segment_baselines', fn (Blueprint $t) => $t->dropColumn(['day_count', 'computed_at']));
        Schema::table('segment_speed_stats', fn (Blueprint $t) => $t->dropColumn('computed_at'));
    }
};
