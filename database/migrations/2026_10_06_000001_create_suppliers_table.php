<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Suppliers that Purchase Orders draw volume from.
     *
     * One row per company/location pair. Guarded so it is safe on a server
     * where a partially applied version of this change may already exist.
     */
    public function up(): void
    {
        if (!Schema::hasTable('suppliers')) {
            Schema::create('suppliers', function (Blueprint $table) {
                $table->id();
                $table->string('company_name', 128);
                // '' rather than NULL so the (company_name, location) unique
                // index still holds for suppliers with no location recorded yet.
                $table->string('location', 128)->default('');
                $table->string('address', 255)->nullable();
                $table->string('attention', 128)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['company_name', 'location']);
                $table->index('is_active');
            });
        }

        $this->backfillSuppliersFromPurchaseOrders();
    }

    /**
     * Seed one supplier row per distinct supplier name already on Purchase
     * Orders. Runs on every migration pass, so it also repairs a server whose
     * schema was created earlier but never backfilled. Locations are left blank
     * for manual completion.
     *
     * Supplier names are normalized before de-duplication so that " Petron "
     * and "Petron" collapse into one supplier.
     */
    private function backfillSuppliersFromPurchaseOrders(): void
    {
        if (!Schema::hasTable('purchase_orders')) {
            return;
        }

        $existing = DB::table('suppliers')
            ->select('company_name', 'location')
            ->get()
            ->map(fn ($row) => $this->normalize($row->company_name) . "\0" . $this->locationKey($row->location))
            ->flip();

        $names = DB::table('purchase_orders')
            ->select('supplier_name')
            ->whereNotNull('supplier_name')
            ->distinct()
            ->pluck('supplier_name');

        foreach ($names as $name) {
            $companyName = $this->normalize($name);

            if ($companyName === '' || $existing->has($companyName . "\0")) {
                continue;
            }

            DB::table('suppliers')->insert([
                'company_name' => $companyName,
                'location' => '',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $existing->put($companyName . "\0", true);
        }
    }

    /**
     * Collapse whitespace and trim, so casing/spacing variants of a supplier
     * name do not create duplicate suppliers. MySQL's default collation is
     * already case-insensitive, so only whitespace needs handling.
     */
    private function normalize(?string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value ?? ''));
    }

    /**
     * A blank location is stored as '', but legacy rows may hold NULL.
     */
    private function locationKey(?string $value): string
    {
        return $this->normalize($value);
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
