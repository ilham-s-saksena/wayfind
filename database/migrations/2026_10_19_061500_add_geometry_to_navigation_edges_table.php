<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('navigation_edges', function (Blueprint $table): void {
            $table->dropUnique(['from_node_id', 'to_node_id']);
            $table->string('authored_feature_id')->nullable();
            $table->json('geometry')->nullable();
            $table->unique(['from_node_id', 'to_node_id', 'authored_feature_id'], 'navigation_edges_authored_path_unique');
        });
    }

    public function down(): void
    {
        Schema::table('navigation_edges', function (Blueprint $table): void {
            $table->dropUnique('navigation_edges_authored_path_unique');
            $table->dropColumn('geometry');
            $table->dropColumn('authored_feature_id');
            $table->unique(['from_node_id', 'to_node_id']);
        });
    }
};
