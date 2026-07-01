<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add columns to users table
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'point_de_vente_id')) {
                $table->unsignedBigInteger('point_de_vente_id')->nullable();
                $table->foreign('point_de_vente_id')->references('id')->on('points_de_vente')->onDelete('set null');
            }
            if (! Schema::hasColumn('users', 'caissier_status')) {
                $table->enum('caissier_status', ['en_attente', 'active', 'inactive'])->nullable();
            }
            if (! Schema::hasColumn('users', 'caissier_role')) {
                $table->string('caissier_role')->nullable();
            }
            if (! Schema::hasColumn('users', 'username')) {
                $table->string('username')->nullable();
            }
        });

        $map = [];

        // 2. Insert all rows from caissiers into users
        if (Schema::hasTable('caissiers')) {
            foreach (DB::table('caissiers')->get() as $c) {
                $newUserId = DB::table('users')->insertGetId([
                    'first_name' => $c->first_name,
                    'last_name' => $c->last_name,
                    'email' => $c->email,
                    'username' => Str::slug($c->first_name.'.'.$c->last_name),
                    'phone' => $c->phone,
                    'password' => Hash::make('password'),
                    'caissier_role' => 'CAISSIER',
                    'is_active' => ($c->status === 'active') ? 1 : 0,
                    'point_de_vente_id' => $c->point_de_vente_id,
                    'caissier_status' => $c->status,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $map[$c->id] = $newUserId;
            }
        }

        // 3. Update FK in sales table
        Schema::table('sales', function (Blueprint $table) {
            try {
                $table->dropForeign(['caissier_id']);
            } catch (Exception $e) {
                // Ignore if foreign key doesn't exist
            }
            $table->renameColumn('caissier_id', 'user_id');
        });

        if (! empty($map)) {
            foreach ($map as $oldId => $newId) {
                DB::table('sales')->where('user_id', $oldId)->update(['user_id' => $newId]);
            }
        }

        Schema::table('sales', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });

        // 4. Update FK in plannings table
        Schema::table('plannings', function (Blueprint $table) {
            try {
                $table->dropForeign(['caissier_id']);
            } catch (Exception $e) {
                // Ignore if foreign key doesn't exist
            }
            $table->renameColumn('caissier_id', 'user_id');
        });

        if (! empty($map)) {
            foreach ($map as $oldId => $newId) {
                DB::table('plannings')->where('user_id', $oldId)->update(['user_id' => $newId]);
            }
        }

        Schema::table('plannings', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });

        // 5. Drop the caissiers table entirely
        Schema::dropIfExists('caissiers');
    }

    public function down(): void
    {
        // 1. Re-create caissiers table
        Schema::dropIfExists('caissiers');
        Schema::create('caissiers', function (Blueprint $table) {
            $table->id();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->unique();
            $table->string('phone')->nullable();
            $table->string('status')->default('en_attente');
            $table->unsignedBigInteger('point_de_vente_id')->nullable();
            $table->timestamps();

            $table->foreign('point_de_vente_id')->references('id')->on('points_de_vente')->onDelete('set null');
        });

        $col = Schema::hasColumn('users', 'caissier_role') ? 'caissier_role' : (Schema::hasColumn('users', 'role') ? 'role' : null);

        if ($col) {
            // 2. Re-populate caissiers from users WHERE role/caissier_role = 'CAISSIER'
            $cashiers = DB::table('users')->where($col, 'CAISSIER')->get();
            $map = [];
            foreach ($cashiers as $u) {
                $oldCaissierId = DB::table('caissiers')->insertGetId([
                    'first_name' => $u->first_name,
                    'last_name' => $u->last_name,
                    'email' => $u->email,
                    'phone' => $u->phone,
                    'status' => $u->caissier_status ?? 'en_attente',
                    'point_de_vente_id' => $u->point_de_vente_id,
                    'created_at' => $u->created_at,
                    'updated_at' => $u->updated_at,
                ]);
                $map[$u->id] = $oldCaissierId;
            }

            // 3. Restore caissier_id FK in sales
            Schema::table('sales', function (Blueprint $table) {
                try {
                    $table->dropForeign(['user_id']);
                } catch (Exception $e) {
                    // Ignore if foreign key doesn't exist
                }
                $table->renameColumn('user_id', 'caissier_id');
            });

            if (! empty($map)) {
                foreach ($map as $newId => $oldId) {
                    DB::table('sales')->where('caissier_id', $newId)->update(['caissier_id' => $oldId]);
                }
            }

            Schema::table('sales', function (Blueprint $table) {
                $table->foreign('caissier_id')->references('id')->on('users')->onDelete('cascade');
            });

            // 4. Restore caissier_id FK in plannings
            Schema::table('plannings', function (Blueprint $table) {
                try {
                    $table->dropForeign(['user_id']);
                } catch (Exception $e) {
                    // Ignore if foreign key doesn't exist
                }
                $table->renameColumn('user_id', 'caissier_id');
            });

            if (! empty($map)) {
                foreach ($map as $newId => $oldId) {
                    DB::table('plannings')->where('caissier_id', $newId)->update(['caissier_id' => $oldId]);
                }
            }

            Schema::table('plannings', function (Blueprint $table) {
                $table->foreign('caissier_id')->references('id')->on('users')->onDelete('cascade');
            });

            // Delete migrated CAISSIER rows from users
            DB::table('users')->where($col, 'CAISSIER')->delete();

            // 5. Remove columns from users
            Schema::table('users', function (Blueprint $table) use ($col) {
                try {
                    $table->dropForeign(['point_de_vente_id']);
                } catch (Exception $e) {
                }
                $table->dropColumn(['point_de_vente_id', 'caissier_status', $col, 'username']);
            });
        }
    }
};
