<?php
/**
 * @filesource Gcms/Search/SearchProviderInterface.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Gcms\Search;

/**
 * Each module implements search for its own data; results are merged centrally.
 *
 * @since 1.0
 */
interface SearchProviderInterface
{
    /**
     * Stable key for ranking boosts (document, product, ...).
     *
     * @return string
     */
    public function sourceType(): string;

    /**
     * @param SearchContext $context
     *
     * @return SearchHit[]
     */
    public function search(SearchContext $context): array;
}
