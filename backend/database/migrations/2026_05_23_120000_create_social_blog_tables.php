<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_follows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('follower_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('following_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['follower_id', 'following_id']);
            $table->index(['following_id', 'created_at']);
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('repost_id')->nullable()->constrained('posts')->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->text('body');
            $table->string('image_url')->nullable();
            $table->unsignedInteger('share_count')->default(0);
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
            $table->index(['repost_id', 'created_at']);
        });

        Schema::create('post_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();
            $table->index(['post_id', 'created_at']);
        });

        Schema::create('post_interactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->timestamps();
            $table->unique(['post_id', 'user_id', 'type']);
            $table->index(['user_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_interactions');
        Schema::dropIfExists('post_comments');
        Schema::dropIfExists('posts');
        Schema::dropIfExists('user_follows');
    }
};
