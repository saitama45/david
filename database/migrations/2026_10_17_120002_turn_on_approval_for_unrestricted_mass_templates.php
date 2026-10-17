<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Additional Order (AO), Emergency Order (EO) and Allocation (ALOC) mass orders now always
     * wait in Mass Orders Approval, whose approval commits them, as CPO's did. Their Ordering
     * Template Approval switch is turned on so it shows that; it can no longer be turned off.
     */
    public function up(): void
    {
        DB::table('suppliers')->whereIn('supplier_code', ['AO', 'EO', 'ALOC'])->update(['is_forapproval_massorders' => true]);
    }

    public function down(): void
    {
        // Left on: turning it off would not bring the old flow back, the code decides it.
    }
};
