<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('uploaded_documents', function (Blueprint $table): void {
            $table->string('internal_expiry_name')->nullable()->after('expiry_date');
            $table->date('internal_expiry_date')->nullable()->after('internal_expiry_name');
        });
    }

    public function down(): void
    {
        Schema::table('uploaded_documents', function (Blueprint $table): void {
            $table->dropColumn(['internal_expiry_name', 'internal_expiry_date']);
        });
    }
};
