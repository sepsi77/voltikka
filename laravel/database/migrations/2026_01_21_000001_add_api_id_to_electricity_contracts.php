<?php

use App\Models\Company;
use App\Models\ElectricityContract;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Migration to switch from API-provided UUIDs to custom contract IDs.
 *
 * New ID format: {random}-{company-slug}-{contract-slug}
 * Example: x7k9m2-fortum-tuntisahko
 *
 * The original API UUID is preserved in the api_id column for sync purposes.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Step 1: Add api_id column (nullable initially)
        Schema::table('electricity_contracts', function (Blueprint $table) {
            $table->string('api_id')->after('id')->nullable();
        });

        // Step 2: Copy current id to api_id
        DB::statement('UPDATE electricity_contracts SET api_id = id');

        // Step 3: Generate new IDs for existing contracts and update all related tables
        // We need to do this in PHP to use the slug generation logic
        $contracts = DB::table('electricity_contracts')->get();

        // Disable foreign key checks for the migration (works for SQLite and MySQL)
        Schema::disableForeignKeyConstraints();

        foreach ($contracts as $contract) {
            $oldId = $contract->id;
            $newId = $this->generateId($contract->company_name, $contract->name);

            // Update the contract ID first
            DB::table('electricity_contracts')
                ->where('id', $oldId)
                ->update(['id' => $newId]);

            // Update related tables
            DB::table('contract_postcode')
                ->where('contract_id', $oldId)
                ->update(['contract_id' => $newId]);

            DB::table('price_components')
                ->where('electricity_contract_id', $oldId)
                ->update(['electricity_contract_id' => $newId]);

            DB::table('electricity_sources')
                ->where('contract_id', $oldId)
                ->update(['contract_id' => $newId]);

            DB::table('active_contracts')
                ->where('id', $oldId)
                ->update(['id' => $newId]);
        }

        // Re-enable foreign key checks
        Schema::enableForeignKeyConstraints();

        // Step 4: Make api_id required and add unique index
        Schema::table('electricity_contracts', function (Blueprint $table) {
            $table->string('api_id')->nullable(false)->unique()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Restore original IDs from api_id
        $contracts = DB::table('electricity_contracts')->get();

        // Disable foreign key checks for the migration
        Schema::disableForeignKeyConstraints();

        foreach ($contracts as $contract) {
            $newId = $contract->id;
            $oldId = $contract->api_id;

            // Update the contract ID back to api_id
            DB::table('electricity_contracts')
                ->where('id', $newId)
                ->update(['id' => $oldId]);

            // Update related tables
            DB::table('contract_postcode')
                ->where('contract_id', $newId)
                ->update(['contract_id' => $oldId]);

            DB::table('price_components')
                ->where('electricity_contract_id', $newId)
                ->update(['electricity_contract_id' => $oldId]);

            DB::table('electricity_sources')
                ->where('contract_id', $newId)
                ->update(['contract_id' => $oldId]);

            DB::table('active_contracts')
                ->where('id', $newId)
                ->update(['id' => $oldId]);
        }

        // Re-enable foreign key checks
        Schema::enableForeignKeyConstraints();

        // Remove api_id column
        Schema::table('electricity_contracts', function (Blueprint $table) {
            $table->dropColumn('api_id');
        });
    }

    /**
     * Generate a custom contract ID.
     * Format: {random}-{company-slug}-{contract-slug}
     */
    private function generateId(string $companyName, string $contractName): string
    {
        $random = strtolower(Str::random(6));
        $companySlug = $this->generateSlug($companyName);
        $contractSlug = $this->generateSlug($contractName);

        return "{$random}-{$companySlug}-{$contractSlug}";
    }

    /**
     * Generate a Finnish-compatible slug from a name.
     * Matches the logic in ElectricityContract::generateSlug.
     */
    private function generateSlug(string $name): string
    {
        $slug = mb_strtolower($name);
        $slug = str_replace(['ä', 'ö', 'å'], ['a', 'o', 'a'], $slug);
        $slug = preg_replace('/[^\w\s-]/u', '', $slug);
        $slug = preg_replace('/[\s_]+/', '-', $slug);
        $slug = preg_replace('/-+/', '-', $slug);
        return trim($slug, '-');
    }
};
