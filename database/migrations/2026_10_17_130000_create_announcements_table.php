<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Announcements: a banner an administrator writes in Sidebar Management for one module (a
     * sidebar page, by its menu_key). It shows on every page of that module while active and
     * inside its optional dates, for every entity (entity_id null) or one.
     */
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->string('menu_key', 120);
            $table->foreignId('entity_id')->nullable()->constrained('entities');
            $table->string('title', 150);
            $table->string('message', 2000);
            $table->string('tone', 20)->default('info');
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['menu_key', 'is_active'], 'announcements_menu_active_index');
        });

        $now = now();
        $today = now('Asia/Manila')->toDateString();

        // CPO left Mass Orders: told on Mass Orders, in each entity that orders CPO.
        foreach (DB::table('suppliers')->where('supplier_code', 'CPO')->distinct()->pluck('entity_id') as $entityId) {
            DB::table('announcements')->insert([
                'menu_key' => 'ordering.mass.mass-orders',
                'entity_id' => $entityId,
                'title' => 'CPO has moved to its own page: CPO Orders',
                'message' => 'CPO is no longer an Ordering Template in Mass Orders. To order CPO, open CPO Orders under Regular Mass Orders in the sidebar. '
                    .'There you pick the vendor the store bought from, enter the Cost from the invoice or receipt, and attach the invoice or receipt. '
                    .'Every CPO order then goes to Mass Orders Approval. CPO orders placed before this change are listed in CPO Orders too.',
                'tone' => 'info',
                'starts_on' => $today,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // The new templates that order the way CPO did: told on Mass Orders, in each entity that has them.
        foreach (DB::table('suppliers')->whereIn('supplier_code', ['AO', 'EO', 'ALOC'])->distinct()->pluck('entity_id') as $entityId) {
            DB::table('announcements')->insert([
                'menu_key' => 'ordering.mass.mass-orders',
                'entity_id' => $entityId,
                'title' => 'New Ordering Templates: Additional Order (AO), Emergency Order (EO) and Allocation (ALOC)',
                'message' => 'Additional Order, Emergency Order and Allocation can now be ordered here. Pick one as the Ordering Template in "Create New Mass Order". '
                    .'Any Delivery Date can be picked, except dates already closed by a final approved Month End Count. '
                    .'The template lists the items of GSI OT-BAKERY, GSI OT-PR, PUL OT-DG and Fruits and Vegetables, and a store appears in it once the template is in its delivery schedule. '
                    .'Every order goes to Mass Orders Approval. Approving it commits it right away, with no step in CS Mass Commits, and the store receives it in Inbound Orders.',
                'tone' => 'success',
                'starts_on' => $today,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // The receiving buttons, explained on Inbound Orders and each order's receiving page.
        DB::table('announcements')->insert([
            'menu_key' => 'receiving.inbound-orders',
            'entity_id' => null,
            'title' => 'How Zero All and Final Receive All work',
            'message' => "Zero All: use it when a delivery did not arrive. It sets every item not yet received into stock to 0 and marks it Unserved. "
                ."It needs no delivery receipt and no image, and it adds nothing to stock. Final Receive All is still needed to close the order.\n"
                ."Confirm Receive All: accepts every item still waiting at its committed quantity and marks the order received. "
                ."Nothing goes to stock yet, and the items can still be corrected.\n"
                ."Final Receive All: the last step. It adds the received quantities to stock and locks the item list, so no item can be received, edited or added after it. "
                ."It needs the delivery receipt and an image, unless every item is 0. Until it is done, the delivery is in no report and holds back the store's Month End Count. "
                .'Delivery receipts and images can still be changed after it.',
            'tone' => 'info',
            'starts_on' => $today,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
