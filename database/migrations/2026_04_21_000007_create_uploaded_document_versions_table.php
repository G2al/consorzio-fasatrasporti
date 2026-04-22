<?php

use App\Models\UploadedDocument;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uploaded_document_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(UploadedDocument::class)
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('template_id')
                ->constrained('document_templates')
                ->cascadeOnDelete();
            $table->string('file_path');
            $table->string('status')->default('pending');
            $table->date('expiry_date')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('admin_notes')->nullable();
            $table->timestamp('versioned_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uploaded_document_versions');
    }
};
