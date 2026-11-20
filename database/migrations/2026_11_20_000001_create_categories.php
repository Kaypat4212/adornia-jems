<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        $names = collect(['Rings', 'Earrings', 'Necklaces', 'Bracelets'])
            ->merge(DB::table('products')->distinct()->pluck('type'))
            ->unique();

        foreach ($names as $name) {
            DB::table('categories')->insertOrIgnore([
                'name' => $name,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('products', function (Blueprint $table): void {
            $table->foreign('type')->references('name')->on('categories')
                ->cascadeOnUpdate()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropForeign(['type']);
        });

        Schema::dropIfExists('categories');
    }
};
