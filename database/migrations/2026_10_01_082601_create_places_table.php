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
        Schema::create('places', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('floor_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('type')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_searchable')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['floor_id', 'slug']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('places');
    }
};
