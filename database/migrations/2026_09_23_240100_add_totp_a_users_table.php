<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->text('totp_secret')->nullable()->after('metodo_auth');
            $table->timestamp('totp_confirmed_at')->nullable()->after('totp_secret');
            $table->json('totp_recovery_codes')->nullable()->after('totp_confirmed_at');
            $table->unsignedBigInteger('totp_last_timestep')->nullable()->after('totp_recovery_codes');
            $table->text('motivo_password')->nullable()->after('totp_last_timestep');
            $table->timestamp('invitacion_expira_en')->nullable()->after('invitacion_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'totp_secret',
                'totp_confirmed_at',
                'totp_recovery_codes',
                'totp_last_timestep',
                'motivo_password',
                'invitacion_expira_en',
            ]);
        });
    }
};
