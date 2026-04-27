<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('responsible_phone')->nullable()->after('responsible_name');
            $table->string('approval_status')->default('approved')->index()->after('role');
            $table->timestamp('approved_at')->nullable()->after('approval_status');
        });

        DB::table('users')
            ->where('role', 'company')
            ->whereNull('approved_at')
            ->update(['approved_at' => now()]);

        Schema::table('employees', function (Blueprint $table): void {
            $table->string('phone')->nullable()->after('last_name');
            $table->dropColumn('tax_code');
        });

        Schema::create('vehicle_capacities', function (Blueprint $table): void {
            $table->id();
            $table->unsignedSmallInteger('seats')->unique();
            $table->timestamps();
        });

        Schema::table('vehicles', function (Blueprint $table): void {
            $table->unsignedSmallInteger('capacity')->default(7)->after('plate');
            $table->dropColumn('brand_model');
        });

        Schema::dropIfExists('uploaded_document_versions');
    }

    public function down(): void
    {
        Schema::create('uploaded_document_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('uploaded_document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('template_id')->constrained('document_templates')->cascadeOnDelete();
            $table->string('file_path');
            $table->string('status')->default('pending');
            $table->date('expiry_date')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('admin_notes')->nullable();
            $table->timestamp('versioned_at')->nullable();
            $table->timestamps();
        });

        Schema::table('vehicles', function (Blueprint $table): void {
            $table->string('brand_model')->default('')->after('user_id');
            $table->dropColumn('capacity');
        });

        Schema::dropIfExists('vehicle_capacities');

        Schema::table('employees', function (Blueprint $table): void {
            $table->string('tax_code')->default('')->after('last_name');
            $table->dropColumn('phone');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['responsible_phone', 'approval_status', 'approved_at']);
        });
    }
};
