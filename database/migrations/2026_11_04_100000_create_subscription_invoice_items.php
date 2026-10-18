<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Baris tagihan langganan (ADR 0025 C2): paket + add-on dalam satu tagihan. subscription_invoices.amount = jumlah
 * baris; kolom plan_* tetap (paket konteks tagihan) agar riwayat & webhook tidak berubah. Tagihan lama = satu baris paket.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_invoice_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('invoice_id')->constrained('subscription_invoices')->cascadeOnDelete();
            // plan / addon
            $table->string('type', 10);
            $table->foreignUuid('plan_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('addon_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 120);
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->decimal('unit_price', 15, 2);
            $table->decimal('amount', 15, 2);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('invoice_id');
        });

        $now = now();
        DB::table('subscription_invoices')->orderBy('id')->each(function (object $invoice) use ($now): void {
            DB::table('subscription_invoice_items')->insert([
                'id' => (string) Str::uuid7(), 'invoice_id' => $invoice->id, 'type' => 'plan', 'plan_id' => $invoice->plan_id,
                'name' => ($invoice->kind === 'upgrade' ? 'Upgrade paket ' : 'Paket ').$invoice->plan_name,
                'quantity' => 1, 'unit_price' => $invoice->amount, 'amount' => $invoice->amount, 'sort_order' => 0,
                'created_at' => $invoice->created_at ?? $now, 'updated_at' => $now,
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_invoice_items');
    }
};
