<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medicines', function (Blueprint $table): void {
            if (! Schema::hasColumn('medicines', 'category')) {
                $table->string('category')->nullable()->after('sku')->index();
            }
            if (! Schema::hasColumn('medicines', 'dosage_form')) {
                $table->string('dosage_form')->nullable()->after('category');
            }
            if (! Schema::hasColumn('medicines', 'strength')) {
                $table->string('strength')->nullable()->after('dosage_form');
            }
            if (! Schema::hasColumn('medicines', 'manufacturer')) {
                $table->string('manufacturer')->nullable()->after('strength');
            }
            if (! Schema::hasColumn('medicines', 'batch_number')) {
                $table->string('batch_number')->nullable()->after('manufacturer');
            }
            if (! Schema::hasColumn('medicines', 'storage_location')) {
                $table->string('storage_location')->nullable()->after('batch_number');
            }
            if (! Schema::hasColumn('medicines', 'status')) {
                $table->string('status')->default('active')->after('unit_price')->index();
            }
        });

        Schema::create('medicine_stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('medicine_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type')->index();
            $table->integer('delta');
            $table->unsignedInteger('quantity_before');
            $table->unsignedInteger('quantity_after');
            $table->string('reason');
            $table->string('reference')->nullable();
            $table->timestamps();
            $table->index(['medicine_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medicine_stock_movements');

        Schema::table('medicines', function (Blueprint $table): void {
            foreach (['category', 'dosage_form', 'strength', 'manufacturer', 'batch_number', 'storage_location', 'status'] as $column) {
                if (Schema::hasColumn('medicines', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
