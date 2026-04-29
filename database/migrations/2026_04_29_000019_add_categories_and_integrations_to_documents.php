<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_templates', function (Blueprint $table): void {
            if (! Schema::hasColumn('document_templates', 'category_id')) {
                $table->foreignId('category_id')
                    ->nullable()
                    ->after('section_id')
                    ->constrained('document_categories')
                    ->nullOnDelete();
            }
        });

        Schema::table('uploaded_documents', function (Blueprint $table): void {
            if (! Schema::hasColumn('uploaded_documents', 'parent_uploaded_document_id')) {
                $table->foreignId('parent_uploaded_document_id')
                    ->nullable()
                    ->after('subtemplate_id')
                    ->constrained('uploaded_documents')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('uploaded_documents', 'integration_name')) {
                $table->string('integration_name')->nullable()->after('parent_uploaded_document_id');
            }

            if (! Schema::hasColumn('uploaded_documents', 'integration_notes')) {
                $table->text('integration_notes')->nullable()->after('integration_name');
            }

            $table->index(['parent_uploaded_document_id', 'status'], 'uploaded_documents_parent_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('uploaded_documents', function (Blueprint $table): void {
            if (Schema::hasColumn('uploaded_documents', 'parent_uploaded_document_id')) {
                $table->dropIndex('uploaded_documents_parent_status_index');
                $table->dropConstrainedForeignId('parent_uploaded_document_id');
            }

            if (Schema::hasColumn('uploaded_documents', 'integration_name')) {
                $table->dropColumn('integration_name');
            }

            if (Schema::hasColumn('uploaded_documents', 'integration_notes')) {
                $table->dropColumn('integration_notes');
            }
        });

        Schema::table('document_templates', function (Blueprint $table): void {
            if (Schema::hasColumn('document_templates', 'category_id')) {
                $table->dropConstrainedForeignId('category_id');
            }
        });
    }
};
