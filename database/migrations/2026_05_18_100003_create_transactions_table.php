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
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained();
            $table->enum('type', [
                'credit',
                'debit',
                'transfer_in',
                'transfer_out',
                'withdrawal_reserved',
                'withdrawal_refund',
            ]);
            $table->decimal('amount', 20, 4);
            $table->decimal('balance_after', 20, 4);
            $table->string('reference_id', 255)->unique();
            $table->string('reference_type', 100)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['wallet_id', 'created_at']);
            $table->index('type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
