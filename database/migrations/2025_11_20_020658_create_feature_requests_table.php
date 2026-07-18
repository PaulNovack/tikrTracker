<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('feature_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('email');
            $table->string('title');
            $table->text('description');
            $table->enum('category', ['ui_ux', 'functionality', 'integration', 'performance', 'other'])->default('other');
            $table->enum('status', ['submitted', 'under_review', 'planned', 'in_development', 'completed', 'rejected'])->default('submitted');
            $table->integer('votes')->default(0);
            $table->text('admin_notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('feature_requests');
    }
};
