<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->timestamp('password_set_at')
                ->nullable()
                ->after('password')
                ->comment('使用者首次設定密碼的時間。null 表示 OAuth-only 註冊,密碼是 Str::random 占位');
        });

        // Backfill heuristic for existing rows:
        // - Members WITHOUT any SNS binding registered via the email/OTP flow,
        //   so they definitely have a real password → mark as set.
        // - Members WITH SNS bindings might be OAuth-only or might have set a
        //   password later — we cannot tell from password alone (Str::random
        //   placeholder vs hashed real password are indistinguishable). Leave
        //   their password_set_at null; they'll see the "Set Password" UI on
        //   next visit, which is the conservative choice.
        DB::statement("
            UPDATE members
            SET password_set_at = COALESCE(updated_at, created_at)
            WHERE id NOT IN (SELECT DISTINCT member_id FROM member_sns)
              AND password IS NOT NULL
        ");
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn('password_set_at');
        });
    }
};
