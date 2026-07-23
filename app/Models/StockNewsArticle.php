<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $stock_news_id
 * @property string $symbol
 * @property string|null $title
 * @property string|null $source
 * @property string|null $url
 * @property string|null $pub_date
 * @property bool $article_text_extracted
 * @property string|null $sentiment
 * @property float|null $impact
 * @property int|null $score_1_100
 * @property string|null $finding_category
 * @property string|null $matched_phrase
 * @property string|null $evidence
 * @property float|null $evidence_positive
 * @property float|null $evidence_negative
 * @property float|null $evidence_neutral
 * @property \Illuminate\Support\Carbon $fetched_at_utc
 */
class StockNewsArticle extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'stock_news_id',
        'symbol',
        'title',
        'source',
        'url',
        'pub_date',
        'article_text_extracted',
        'sentiment',
        'impact',
        'score_1_100',
        'finding_category',
        'matched_phrase',
        'evidence',
        'evidence_positive',
        'evidence_negative',
        'evidence_neutral',
        'fetched_at_utc',
    ];

    protected function casts(): array
    {
        return [
            'article_text_extracted' => 'boolean',
            'impact' => 'decimal:4',
            'score_1_100' => 'integer',
            'evidence_positive' => 'decimal:4',
            'evidence_negative' => 'decimal:4',
            'evidence_neutral' => 'decimal:4',
            'fetched_at_utc' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<StockNews, $this>
     */
    public function stockNews(): BelongsTo
    {
        return $this->belongsTo(StockNews::class);
    }
}
