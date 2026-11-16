<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ext_server_creator_audit', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedInteger('actor_id');
            $table->string('actor_name', 191);
            $table->string('action', 64);
            $table->string('target', 191);
            $table->json('details');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ext_server_creator_audit');
    }
};
