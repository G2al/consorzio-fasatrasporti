<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uploaded_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->constrained('document_templates')->cascadeOnDelete();
            $table->morphs('documentable');
            $table->string('file_path');
            $table->string('status')->default('pending')->index();
            $table->date('expiry_date')->nullable();
            $table->text('admin_notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uploaded_documents');
    }
};
