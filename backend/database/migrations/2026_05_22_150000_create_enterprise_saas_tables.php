<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE users MODIFY role VARCHAR(50) NOT NULL DEFAULT 'patient'");

        if (! Schema::hasTable('organizations')) {
            Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name')->index();
            $table->string('slug')->unique();
            $table->string('type')->default('clinic');
            $table->string('plan')->default('starter')->index();
            $table->string('status')->default('active')->index();
            $table->string('currency', 8)->default('USD');
            $table->json('settings')->nullable();
            $table->timestamps();
            });
        }

        if (! Schema::hasTable('branches')) {
            Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'name']);
            });
        }

        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'organization_id')) {
                $table->foreignId('organization_id')->nullable()->after('id')->constrained()->nullOnDelete();
            }
            if (! Schema::hasColumn('users', 'branch_id')) {
                $table->foreignId('branch_id')->nullable()->after('organization_id')->constrained()->nullOnDelete();
            }
            if (! Schema::hasColumn('users', 'two_factor_enabled')) {
                $table->boolean('two_factor_enabled')->default(false)->after('date_of_birth');
            }
            if (! Schema::hasColumn('users', 'two_factor_secret')) {
                $table->text('two_factor_secret')->nullable()->after('two_factor_enabled');
            }
            if (! Schema::hasColumn('users', 'last_seen_at')) {
                $table->timestamp('last_seen_at')->nullable()->after('two_factor_secret');
            }
        });

        Schema::create('staff_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('invited_by')->constrained('users')->cascadeOnDelete();
            $table->string('email')->index();
            $table->string('role');
            $table->string('token')->unique();
            $table->timestamp('accepted_at')->nullable();
            $table->dateTime('expires_at')->index();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action')->index();
            $table->string('auditable_type')->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->json('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
            $table->index(['auditable_type', 'auditable_id']);
        });

        Schema::create('user_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('device_name')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('last_active_at')->nullable();
            $table->timestamps();
        });

        Schema::create('patient_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('patient_number')->index();
            $table->json('allergies')->nullable();
            $table->json('chronic_conditions')->nullable();
            $table->json('emergency_contact')->nullable();
            $table->text('encrypted_summary')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'patient_number']);
        });

        Schema::create('vitals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->cascadeOnDelete();
            $table->decimal('temperature', 5, 2)->nullable();
            $table->unsignedSmallInteger('heart_rate')->nullable();
            $table->string('blood_pressure')->nullable();
            $table->decimal('weight', 6, 2)->nullable();
            $table->decimal('height', 6, 2)->nullable();
            $table->dateTime('recorded_at')->index();
            $table->timestamps();
        });

        Schema::create('ehr_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('doctor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type')->index();
            $table->string('title');
            $table->longText('encrypted_body');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'patient_id', 'type']);
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('invoice_number')->index();
            $table->string('status')->default('draft')->index();
            $table->string('currency', 8)->default('USD');
            $table->unsignedBigInteger('subtotal');
            $table->unsignedBigInteger('tax')->default(0);
            $table->unsignedBigInteger('total');
            $table->timestamp('due_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'invoice_number']);
        });

        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider')->index();
            $table->string('reference')->unique();
            $table->string('status')->index();
            $table->unsignedBigInteger('amount');
            $table->string('currency', 8)->default('USD');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('medicines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('sku')->nullable();
            $table->unsignedInteger('stock')->default(0);
            $table->unsignedInteger('reorder_level')->default(10);
            $table->unsignedBigInteger('unit_price')->default(0);
            $table->date('expires_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'name']);
        });

        Schema::create('lab_tests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('status')->default('requested')->index();
            $table->text('result_summary')->nullable();
            $table->string('report_path')->nullable();
            $table->timestamps();
        });

        Schema::create('insurance_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained('users')->cascadeOnDelete();
            $table->string('provider');
            $table->string('policy_number');
            $table->string('status')->default('submitted')->index();
            $table->unsignedBigInteger('claim_amount');
            $table->json('coverage')->nullable();
            $table->timestamps();
        });

        Schema::create('doctor_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('doctor_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->timestamps();
        });

        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('body');
            $table->string('audience')->default('all')->index();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->after('organization_id')->constrained()->nullOnDelete();
            $table->index(['organization_id', 'status', 'scheduled_at']);
        });

        Schema::table('medical_records', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->index(['organization_id', 'patient_id']);
        });
    }

    public function down(): void
    {
        Schema::table('medical_records', fn (Blueprint $table) => $table->dropConstrainedForeignId('organization_id'));
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
            $table->dropConstrainedForeignId('organization_id');
        });

        foreach ([
            'announcements', 'doctor_reviews', 'insurance_claims', 'lab_tests', 'medicines',
            'transactions', 'invoices', 'ehr_entries', 'vitals', 'patient_profiles',
            'user_sessions', 'audit_logs', 'staff_invitations', 'branches', 'organizations',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
