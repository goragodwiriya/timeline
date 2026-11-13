<?php
/**
 * @filesource Gcms/Search/SearchAggregator.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Gcms\Search;

/**
 * Merges provider hits and ranks by relevance * per-type boost (e.g. product > document).
 *
 * @since 1.0
 */
class SearchAggregator
{
    /**
     * @var SearchRegistry
     */
    private $registry;

    /**
     * Multipliers by sourceType (product should beat document at equal relevance).
     *
     * @var array<string,float>
     */
    private $typeBoost;

    /**
     * @param SearchRegistry   $registry
     * @param array<string,float> $typeBoost
     */
    public function __construct(SearchRegistry $registry, array $typeBoost = [])
    {
        $this->registry = $registry;
        $this->typeBoost = $typeBoost + [
            'product' => 1.25,
            'document' => 1.0
        ];
    }

    /**
     * @param SearchContext $context
     *
     * @return SearchHit[]
     */
    public function search(SearchContext $context): array
    {
        $merged = [];
        foreach ($this->registry->all() as $provider) {
            $hits = $provider->search($context);
            if (!is_array($hits)) {
                continue;
            }
            foreach ($hits as $hit) {
                if (!$hit instanceof SearchHit) {
                    continue;
                }
                $boost = 1.0;
                if ($hit->sourceType !== '') {
                    $boost = (float) ($this->typeBoost[$hit->sourceType] ?? 1.0);
                }
                $rel = min(1.0, max(0.0, (float) $hit->relevance));
                $hit->finalScore = $rel * $boost;
                $merged[] = $hit;
            }
        }

        usort($merged, static function (SearchHit $a, SearchHit $b) {
            $scoreA = $a->finalScore;
            $scoreB = $b->finalScore;
            if ($scoreA < $scoreB) {
                return 1;
            }
            if ($scoreA > $scoreB) {
                return -1;
            }

            return strcmp((string) $b->publishedDate, (string) $a->publishedDate);
        });

        $limit = max(1, (int) $context->totalLimit);

        return array_slice($merged, 0, $limit);
    }
}
