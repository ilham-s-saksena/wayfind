<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('places', function (Blueprint $table): void {
            $table->foreignUuid('access_node_id')
                ->nullable()
                ->constrained('navigation_nodes')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('places')->whereNotNull('access_node_id')->exists()) {
            throw new LogicException('Clear place access-node assignments before rolling back this migration.');
        }

        Schema::table('places', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('access_node_id');
        });
    }
};
