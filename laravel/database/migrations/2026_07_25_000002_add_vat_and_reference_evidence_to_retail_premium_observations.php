<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('retail_premium_observations', function (Blueprint $table) {
            // The premium is an energy-price spread, so `vat_basis` describes the energy component
            // only. The monthly fee keeps its own basis, because it decides the fee-inclusive value.
            $table->string('fee_vat_basis', 16)->nullable()->after('vat_basis');
            $table->string('vat_basis_source', 32)->nullable()->after('fee_vat_basis');

            // Keep the wholesale reference as evidence even when the retail VAT basis is unknown,
            // so a later analysis can still measure pass-through from price differences.
            $table->decimal('reference_price_including_vat_cents_per_kwh', 9, 4)
                ->nullable()
                ->after('reference_price_cents_per_kwh');
            $table->decimal('reference_price_excluding_vat_cents_per_kwh', 9, 4)
                ->nullable()
                ->after('reference_price_including_vat_cents_per_kwh');
            $table->decimal('reference_settlement_price_eur_per_mwh', 12, 4)
                ->nullable()
                ->after('reference_price_excluding_vat_cents_per_kwh');
        });
    }

    public function down(): void
    {
        Schema::table('retail_premium_observations', function (Blueprint $table) {
            $table->dropColumn([
                'fee_vat_basis',
                'vat_basis_source',
                'reference_price_including_vat_cents_per_kwh',
                'reference_price_excluding_vat_cents_per_kwh',
                'reference_settlement_price_eur_per_mwh',
            ]);
        });
    }
};
