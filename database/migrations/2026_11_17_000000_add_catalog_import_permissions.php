<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ext_server_creator_catalog_permissions', function (Blueprint $table): void {
            $table->unsignedInteger('user_id')->primary();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('ext_server_creator_catalog_eggs', function (Blueprint $table): void {
            $table->string('catalog_id', 191)->primary();
            $table->unsignedInteger('egg_id');
            $table->foreign('egg_id')->references('id')->on('eggs')->cascadeOnDelete();
        });

        Schema::table('ext_server_creator_games', function (Blueprint $table): void {
            $table->unsignedInteger('owner_id')->nullable()->index();
            $table->foreign('owner_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ext_server_creator_games', function (Blueprint $table): void {
            $table->dropForeign(['owner_id']);
            $table->dropIndex(['owner_id']);
            $table->dropColumn('owner_id');
        });
        Schema::dropIfExists('ext_server_creator_catalog_eggs');
        Schema::dropIfExists('ext_server_creator_catalog_permissions');
    }
};
