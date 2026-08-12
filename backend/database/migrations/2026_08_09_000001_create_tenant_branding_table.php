<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hospital branding configuration (control database).
     *
     * One row per tenant. A null field means "inherit the Vee-Care default",
     * so a freshly provisioned hospital renders with the platform brand until
     * it customizes a value. All values are validated safe formats before they
     * are stored (colors as #RRGGBB/#RRGGBBAA, URLs restricted to safe
     * schemes, fonts from the allowlist).
     */
    public function up(): void
    {
        Schema::create('tenant_branding', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('logo')->nullable();
            $table->string('favicon')->nullable();
            $table->string('primary_color', 9)->nullable();
            $table->string('secondary_color', 9)->nullable();
            $table->string('accent_color', 9)->nullable();
            $table->string('font_family', 80)->nullable();
            $table->timestamps();

            $table->unique('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_branding');
    }
};
