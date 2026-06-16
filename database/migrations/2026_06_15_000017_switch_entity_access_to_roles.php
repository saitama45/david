<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Switch entity access from per-user to per-role.
 * A user can access an entity if any of their roles is granted that entity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entity_role', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['entity_id', 'role_id']);
        });

        // Grant the existing entity (Nonos) to every current role so all users
        // keep their access after the switch.
        $nonosId = DB::table('entities')->where('code', 'NONOS')->value('id');
        if ($nonosId) {
            $now = now();
            $rows = DB::table('roles')->pluck('id')->map(fn ($roleId) => [
                'entity_id' => $nonosId,
                'role_id' => $roleId,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();

            if ($rows) {
                DB::table('entity_role')->insert($rows);
            }
        }

        Schema::dropIfExists('user_entity');
    }

    public function down(): void
    {
        Schema::create('user_entity', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'entity_id']);
        });

        Schema::dropIfExists('entity_role');
    }
};
