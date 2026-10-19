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
        Schema::create('navigation_nodes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('floor_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('type')->default('path');
            $table->decimal('x', 12, 8)->default(0);
            $table->decimal('y', 12, 8)->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('navigation_nodes');
    }
};
