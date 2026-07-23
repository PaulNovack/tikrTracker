<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_news', function (Blueprint $table) {
            $table->id();
            $table->string('symbol', 16);
            $table->string('sentiment')->nullable();
            $table->decimal('confidence', 6, 2)->nullable();
            $table->unsignedTinyInteger('sentiment_score_1_100')->nullable();
            $table->unsignedSmallInteger('headline_count')->default(0);
            $table->unsignedSmallInteger('positive_count')->default(0);
            $table->unsignedSmallInteger('negative_count')->default(0);
            $table->unsignedSmallInteger('neutral_count')->default(0);
            $table->string('top_finding')->nullable();
            $table->string('top_matched_phrase')->nullable();
            $table->string('top_source')->nullable();
            $table->string('top_title')->nullable();
            $table->unsignedTinyInteger('top_article_score')->nullable();
            $table->boolean('top_article_text_extracted')->default(false);
            $table->text('top_evidence')->nullable();
            $table->string('top_url', 2048)->nullable();
            $table->boolean('is_error')->default(false);
            $table->text('error_message')->nullable();
            $table->dateTime('fetched_at_utc');
            $table->timestamp('created_at')->useCurrent();

            $table->index('symbol');
            $table->index('fetched_at_utc');
            $table->index(['symbol', 'fetched_at_utc']);
        });

        Schema::create('stock_news_articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_news_id')->constrained('stock_news')->cascadeOnDelete();
            $table->string('symbol', 16);
            $table->string('title')->nullable();
            $table->string('source')->nullable();
            $table->string('url', 2048)->nullable();
            $table->boolean('article_text_extracted')->default(false);
            $table->string('sentiment')->nullable();
            $table->decimal('impact', 8, 4)->nullable();
            $table->unsignedTinyInteger('score_1_100')->nullable();
            $table->string('finding_category')->nullable();
            $table->string('matched_phrase')->nullable();
            $table->text('evidence')->nullable();
            $table->decimal('evidence_positive', 6, 4)->nullable();
            $table->decimal('evidence_negative', 6, 4)->nullable();
            $table->decimal('evidence_neutral', 6, 4)->nullable();
            $table->dateTime('fetched_at_utc');

            $table->index('symbol');
            $table->index('fetched_at_utc');
            $table->index(['symbol', 'fetched_at_utc']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_news_articles');
        Schema::dropIfExists('stock_news');
    }
};
