<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('navigation_edges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('from_node_id')
                ->constrained('navigation_nodes')
                ->cascadeOnDelete();

            $table->foreignUuid('to_node_id')
                ->constrained('navigation_nodes')
                ->cascadeOnDelete();
            $table->decimal('distance', 12, 4)->default(0);
            $table->boolean('accessible')->default(true);
            $table->unique(['from_node_id', 'to_node_id']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('navigation_edges');
    }
};
