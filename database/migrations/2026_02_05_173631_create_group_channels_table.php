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
        Schema::create('group_channels', function (Blueprint $table) {
            $table->id();

            $table->foreignId('group_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('provider', 32);         // telegram, max, etc
            $table->string('display_name', 120);    // how it appears in UI

            $table->string('status', 16)->default('disabled'); // active|disabled|error

            $table->json('settings')->nullable();   // provider-agnostic config
            $table->json('secrets')->nullable();    // tokens/keys later (store encrypted at app level)

            $table->timestamps();

            // пока ограничим: один провайдер на группу (потом можно расширить)
            $table->unique(['group_id', 'provider']);
            $table->index(['group_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('group_channels');
    }
};
