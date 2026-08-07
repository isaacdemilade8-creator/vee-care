<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('type')->default('hospital')->index();
            $table->string('plan')->default('starter')->index();
            $table->string('status')->default('pending')->index();
            $table->string('currency', 8)->default('USD');
            $table->string('database_name')->unique();
            $table->string('database_host')->nullable();
            $table->string('database_port')->nullable();
            $table->string('database_username')->nullable();
            $table->text('database_password')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
