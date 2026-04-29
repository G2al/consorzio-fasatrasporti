<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_deadline_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('uploaded_document_id')->constrained('uploaded_documents')->cascadeOnDelete();
            $table->string('channel')->default('telegram');
            $table->string('deadline_type');
            $table->string('bucket');
            $table->date('deadline_date');
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->unique(
                ['uploaded_document_id', 'channel', 'deadline_type', 'bucket', 'deadline_date'],
                'deadline_notifications_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_deadline_notifications');
    }
};
