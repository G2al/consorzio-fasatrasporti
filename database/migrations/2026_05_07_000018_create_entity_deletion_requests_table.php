<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entity_deletion_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('users')->cascadeOnDelete();
            $table->morphs('deletable');
            $table->string('snapshot_label');
            $table->string('snapshot_secondary')->nullable();
            $table->text('requested_reason')->nullable();
            $table->string('status')->default('pending');
            $table->text('admin_notes')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['deletable_type', 'deletable_id', 'status'], 'entity_deletion_requests_target_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_deletion_requests');
    }
};
