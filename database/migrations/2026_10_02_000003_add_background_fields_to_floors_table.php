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
        Schema::table('floors', function (Blueprint $table) {
            $table->string('background_image')->nullable()->after('map_data');
            $table->boolean('background_visible')->default(true)->after('background_image');
            $table->decimal('background_opacity', 3, 2)->default(0.50)->after('background_visible');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('floors', function (Blueprint $table) {
            $table->dropColumn(['background_image', 'background_visible', 'background_opacity']);
        });
    }
};
