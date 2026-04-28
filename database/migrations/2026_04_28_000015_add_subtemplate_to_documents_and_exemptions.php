<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('uploaded_documents', 'subtemplate_id')) {
            Schema::table('uploaded_documents', function (Blueprint $table): void {
                $table->foreignId('subtemplate_id')
                    ->nullable()
                    ->after('template_id')
                    ->constrained('document_subtemplates')
                    ->nullOnDelete();

                $table->index(['template_id', 'subtemplate_id'], 'uploaded_documents_template_subtemplate_index');
            });
        }

        if (! Schema::hasColumn('document_exemptions', 'subtemplate_id')) {
            Schema::table('document_exemptions', function (Blueprint $table): void {
                $table->index('template_id', 'document_exemptions_template_id_index');
            });

            Schema::table('document_exemptions', function (Blueprint $table): void {
                $table->dropUnique('document_exemptions_unique_owner');
                $table->foreignId('subtemplate_id')
                    ->nullable()
                    ->after('template_id')
                    ->constrained('document_subtemplates')
                    ->nullOnDelete();

                $table->index(['template_id', 'subtemplate_id'], 'document_exemptions_template_subtemplate_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('document_exemptions', 'subtemplate_id')) {
            Schema::table('document_exemptions', function (Blueprint $table): void {
                $table->dropIndex('document_exemptions_template_subtemplate_index');
                $table->dropConstrainedForeignId('subtemplate_id');
                $table->unique(['template_id', 'exemptable_type', 'exemptable_id'], 'document_exemptions_unique_owner');
                $table->dropIndex('document_exemptions_template_id_index');
            });
        }

        if (Schema::hasColumn('uploaded_documents', 'subtemplate_id')) {
            Schema::table('uploaded_documents', function (Blueprint $table): void {
                $table->dropIndex('uploaded_documents_template_subtemplate_index');
                $table->dropConstrainedForeignId('subtemplate_id');
            });
        }
    }
};
