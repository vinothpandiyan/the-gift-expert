<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->restrictOnDelete();
            $table->string('provider_key', 80);
            $table->enum('status', ['pending', 'running', 'completed', 'failed', 'completed_with_errors'])->default('pending');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('items_total')->default(0);
            $table->unsignedInteger('items_succeeded')->default(0);
            $table->unsignedInteger('items_failed')->default(0);
            $table->unsignedInteger('items_skipped')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_runs');
    }
};
