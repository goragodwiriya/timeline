<?php
/**
 * @filesource Gcms/Search/SearchHit.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Gcms\Search;

/**
 * Normalized hit from any search provider before global ranking.
 *
 * @since 1.0
 */
class SearchHit
{
    /**
     * Logical type: document, product, etc.
     *
     * @var string
     */
    public $sourceType = '';

    /**
     * Provider-internal relevance 0..1 (field match, recency, etc.).
     *
     * @var float
     */
    public $relevance = 0.0;

    /**
     * relevance * type boost after aggregation.
     *
     * @var float
     */
    public $finalScore = 0.0;

    /**
     * @var string
     */
    public $title = '';

    /**
     * @var string
     */
    public $description = '';

    /**
     * @var string
     */
    public $url = '';

    /**
     * Section / module label for display.
     *
     * @var string
     */
    public $moduleTopic = '';

    /**
     * Sort tie-breaker (Y-m-d or empty).
     *
     * @var string
     */
    public $publishedDate = '';

    /**
     * @var int
     */
    public $sourceId = 0;

    /**
     * Extra fields for chat cards (optional).
     *
     * @var array
     */
    public $cardExtras = [];
}
