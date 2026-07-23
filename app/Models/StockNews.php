<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $symbol
 * @property string|null $sentiment
 * @property float|null $confidence
 * @property int|null $sentiment_score_1_100
 * @property int $headline_count
 * @property int $positive_count
 * @property int $negative_count
 * @property int $neutral_count
 * @property string|null $top_finding
 * @property string|null $top_matched_phrase
 * @property string|null $top_source
 * @property string|null $top_title
 * @property int|null $top_article_score
 * @property bool $top_article_text_extracted
 * @property string|null $top_evidence
 * @property string|null $top_url
 * @property bool $is_error
 * @property string|null $error_message
 * @property \Illuminate\Support\Carbon $fetched_at_utc
 * @property \Illuminate\Support\Carbon $created_at
 */
class StockNews extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'symbol',
        'sentiment',
        'confidence',
        'sentiment_score_1_100',
        'headline_count',
        'positive_count',
        'negative_count',
        'neutral_count',
        'top_finding',
        'top_matched_phrase',
        'top_source',
        'top_title',
        'top_article_score',
        'top_article_text_extracted',
        'top_evidence',
        'top_url',
        'is_error',
        'error_message',
        'fetched_at_utc',
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'decimal:2',
            'headline_count' => 'integer',
            'positive_count' => 'integer',
            'negative_count' => 'integer',
            'neutral_count' => 'integer',
            'top_article_score' => 'integer',
            'top_article_text_extracted' => 'boolean',
            'is_error' => 'boolean',
            'fetched_at_utc' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<StockNewsArticle>
     */
    public function articles(): HasMany
    {
        return $this->hasMany(StockNewsArticle::class);
    }
}
