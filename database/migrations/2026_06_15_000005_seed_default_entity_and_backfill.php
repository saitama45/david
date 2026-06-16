<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Create the default "Nonos" entity if it does not already exist.
        $entityId = DB::table('entities')->where('code', 'NONOS')->value('id');

        if (! $entityId) {
            $entityId = DB::table('entities')->insertGetId([
                'name' => 'Nonos',
                'code' => 'NONOS',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Every existing branch belongs to Nonos.
        DB::table('store_branches')->whereNull('entity_id')->update(['entity_id' => $entityId]);

        // Grant all existing users access to Nonos and set it as their active entity.
        $userIds = DB::table('users')->pluck('id');
        $now = now();

        foreach ($userIds as $userId) {
            $exists = DB::table('user_entity')
                ->where('user_id', $userId)
                ->where('entity_id', $entityId)
                ->exists();

            if (! $exists) {
                DB::table('user_entity')->insert([
                    'user_id' => $userId,
                    'entity_id' => $entityId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        DB::table('users')->whereNull('last_entity_id')->update(['last_entity_id' => $entityId]);
    }

    public function down(): void
    {
        $entityId = DB::table('entities')->where('code', 'NONOS')->value('id');

        if ($entityId) {
            DB::table('users')->where('last_entity_id', $entityId)->update(['last_entity_id' => null]);
            DB::table('user_entity')->where('entity_id', $entityId)->delete();
            DB::table('store_branches')->where('entity_id', $entityId)->update(['entity_id' => null]);
            DB::table('entities')->where('id', $entityId)->delete();
        }
    }
};
