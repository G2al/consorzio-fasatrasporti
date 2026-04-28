<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_exemptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('template_id')->constrained('document_templates')->cascadeOnDelete();
            $table->morphs('exemptable');
            $table->string('status')->default('pending')->index();
            $table->text('requested_reason')->nullable();
            $table->text('admin_notes')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['template_id', 'exemptable_type', 'exemptable_id'], 'document_exemptions_unique_owner');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_exemptions');
    }
};
