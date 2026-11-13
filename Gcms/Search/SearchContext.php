<?php
/**
 * @filesource Gcms/Search/SearchContext.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Gcms\Search;

/**
 * Shared context for modular site search.
 *
 * @since 1.0
 */
class SearchContext
{
    /**
     * Normalized query string (no command prefix).
     *
     * @var string
     */
    public $query = '';

    /**
     * Max hits returned after merge and ranking.
     *
     * @var int
     */
    public $totalLimit = 8;

    /**
     * Soft cap per provider before merge.
     *
     * @var int
     */
    public $perProviderLimit = 12;
}
