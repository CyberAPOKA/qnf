<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->uuid('idempotency_key')->nullable()->unique()->after('external_id');
        });

        DB::table('payments')->whereNull('idempotency_key')->orderBy('id')->chunkById(100, function ($payments): void {
            foreach ($payments as $payment) {
                DB::table('payments')->where('id', $payment->id)->update([
                    'idempotency_key' => (string) Str::uuid(),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn('idempotency_key');
        });
    }
};
