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
        Schema::table('stock_news_articles', function (Blueprint $table) {
            $table->text('title')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('stock_news_articles', function (Blueprint $table) {
            $table->string('title')->nullable()->change();
        });
    }
};
