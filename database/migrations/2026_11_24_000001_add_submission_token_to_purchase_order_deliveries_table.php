<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One form render, one ATL.
     *
     * The ATL save endpoints had nothing tying a submitted form to a single
     * record: every POST that passed validation inserted another delivery row.
     * A double-click on Save, a refresh of the POST, or Back followed by Save
     * therefore wrote the same ATL twice - two rows, same PO, same volume, and
     * (for the Doyen-issued forms that leave the number blank) both reading
     * "Not yet issued".
     *
     * The token travels with the form. The unique index is what makes a second
     * write impossible even when two requests arrive at the same instant and
     * both pass the application-level check.
     */
    public function up(): void
    {
        if (!Schema::hasTable('purchase_order_deliveries')
            || Schema::hasColumn('purchase_order_deliveries', 'submission_token')) {
            return;
        }

        Schema::table('purchase_order_deliveries', function (Blueprint $table) {
            $table->string('submission_token', 64)->nullable()->after('reference_no');
            $table->unique('submission_token');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('purchase_order_deliveries')
            || !Schema::hasColumn('purchase_order_deliveries', 'submission_token')) {
            return;
        }

        Schema::table('purchase_order_deliveries', function (Blueprint $table) {
            $table->dropUnique(['submission_token']);
            $table->dropColumn('submission_token');
        });
    }
};
