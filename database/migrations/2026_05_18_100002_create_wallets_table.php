<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained();
            $table->enum('type', ['salary', 'savings']);
            $table->char('currency', 3);
            $table->decimal('balance', 20, 4)->default(0);
            $table->boolean('is_locked')->default(false);
            $table->timestamps();

            $table->unique(['employee_id', 'type'], 'wallets_employee_id_type_unique');
            $table->index('type');
            $table->index('is_locked');
        });

        DB::statement('ALTER TABLE wallets ADD CONSTRAINT wallets_balance_non_negative CHECK (balance >= 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('wallets') && Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE wallets DROP CONSTRAINT wallets_balance_non_negative');
        }

        Schema::dropIfExists('wallets');
    }
};
