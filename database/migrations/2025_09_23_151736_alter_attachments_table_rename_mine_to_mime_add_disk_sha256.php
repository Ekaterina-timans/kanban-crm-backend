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
        Schema::table('attachments', function (Blueprint $table) {
            // 1) если раньше была опечатка "mine" — переименуем в "mime"
            //  Для renameColumn в Laravel обычно нужен doctrine/dbal.
            if (Schema::hasColumn('attachments', 'mine') && !Schema::hasColumn('attachments', 'mime')) {
                $table->renameColumn('mine', 'mime');
            }

            // 2) диск и sha256 — опционально, но полезно
            if (!Schema::hasColumn('attachments', 'disk')) {
                $table->string('disk', 50)->default('public')->after('uploaded_by');
            }
            if (!Schema::hasColumn('attachments', 'sha256')) {
                $table->string('sha256', 64)->nullable()->after('size');
            }

            // 3) индексы — ускоряют выборки
            if (!Schema::hasColumn('attachments', 'created_at')) {
                // на случай очень старой схемы; обычно timestamps уже есть
                $table->timestamps();
            }
            $table->index('uploaded_by');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            if (Schema::hasColumn('attachments', 'sha256')) {
                $table->dropColumn('sha256');
            }
            if (Schema::hasColumn('attachments', 'disk')) {
                $table->dropColumn('disk');
            }
        });
    }
};
