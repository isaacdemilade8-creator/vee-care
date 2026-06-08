<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'avatar_url')) {
                $table->string('avatar_url')->nullable()->after('phone');
            }

            if (! Schema::hasColumn('users', 'bio')) {
                $table->text('bio')->nullable()->after('avatar_url');
            }

            if (! Schema::hasColumn('users', 'location')) {
                $table->string('location')->nullable()->after('bio');
            }

            if (! Schema::hasColumn('users', 'website')) {
                $table->string('website')->nullable()->after('location');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            foreach (['website', 'location', 'bio', 'avatar_url'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
