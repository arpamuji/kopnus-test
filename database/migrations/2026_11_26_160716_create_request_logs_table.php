<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('request_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('trace_id')->unique();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('method');
            $table->string('route');
            $table->string('operation')->nullable();
            $table->smallInteger('status_code')->nullable();
            $table->string('outcome')->nullable();
            $table->string('error_type')->nullable();
            $table->string('idempotency_key')->nullable();
            $table->string('idempotency_status')->nullable();
            $table->string('request_fingerprint', 64)->nullable();
            $table->json('response_payload')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index('status_code');
        });

        // Partial index so only financial mutations with a key are deduplicated.
        DB::statement(
            "CREATE UNIQUE INDEX request_logs_idempotency_unique
             ON request_logs (user_id, operation, idempotency_key)
             WHERE operation IN ('deposit', 'withdraw') AND idempotency_key IS NOT NULL"
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS request_logs_idempotency_unique');

        Schema::dropIfExists('request_logs');
    }
};
