<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('document_subtemplates')->update(['is_required' => false]);
    }

    public function down(): void
    {
        //
    }
};
