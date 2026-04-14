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
        Schema::table('members', function (Blueprint $table) {
            $table->index('status');
            $table->index('created_at');
            $table->index('last_login_at');
        });

        Schema::table('member_tag', function (Blueprint $table) {
            $table->index('tag_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['created_at']);
            $table->dropIndex(['last_login_at']);
        });

        Schema::table('member_tag', function (Blueprint $table) {
            $table->dropIndex(['tag_id']);
        });
    }
};
