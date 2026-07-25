<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Drop lingering PostgreSQL ENUM types (survive DROP TABLE / migrate:fresh)
        DB::statement("DROP TYPE IF EXISTS rentals_status_enum CASCADE");
        DB::statement("DROP TYPE IF EXISTS boats_status_enum CASCADE");

        Schema::create('rentals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('boat_id')->index();
            $table->unsignedBigInteger('worker_id')->index();
            $table->timestamp('started_at');
            $table->timestamp('expected_end_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('actual_end_at')->nullable();
            $table->integer('overtime_seconds')->default(0);
            $table->enum('status', ['active', 'completed', 'overdue', 'overridden'])->default('active')->index();
            $table->unsignedBigInteger('ended_by')->nullable();
            $table->boolean('customer_returned')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('ended_by')->references('id')->on('users')->onDelete('set null');
        });

        Schema::create('boats', function (Blueprint $table) {
            $table->id();
            $table->integer('boat_number')->unique()->index();
            $table->string('name')->nullable();
            $table->enum('status', ['available', 'occupied', 'warning', 'awaiting_confirmation', 'overdue', 'maintenance'])
                ->default('available')->index();
            $table->unsignedBigInteger('current_rental_id')->nullable();
            $table->string('color_hex', 7)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('current_rental_id')->references('id')->on('rentals')->onDelete('set null');
        });

        Schema::table('rentals', function (Blueprint $table) {
            $table->foreign('boat_id')->references('id')->on('boats')->onDelete('cascade');
            $table->foreign('worker_id')->references('id')->on('users')->onDelete('cascade');
            $table->index(['boat_id', 'status'], 'rentals_boat_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('rentals', function (Blueprint $table) {
            $table->dropForeign(['boat_id']);
            $table->dropForeign(['worker_id']);
        });
        Schema::table('boats', function (Blueprint $table) {
            $table->dropForeign(['current_rental_id']);
        });
        Schema::dropIfExists('boats');
        Schema::dropIfExists('rentals');

        // Clean up PostgreSQL ENUM types
        DB::statement("DROP TYPE IF EXISTS rentals_status_enum CASCADE");
        DB::statement("DROP TYPE IF EXISTS boats_status_enum CASCADE");
    }
};
