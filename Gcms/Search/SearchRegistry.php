<?php
/**
 * @filesource Gcms/Search/SearchRegistry.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Gcms\Search;

/**
 * @since 1.0
 */
class SearchRegistry
{
    /**
     * @var SearchProviderInterface[]
     */
    private $providers = [];

    /**
     * @param SearchProviderInterface $provider
     *
     * @return $this
     */
    public function register(SearchProviderInterface $provider)
    {
        $this->providers[$provider->sourceType()] = $provider;

        return $this;
    }

    /**
     * @return SearchProviderInterface[]
     */
    public function all(): array
    {
        return array_values($this->providers);
    }

    /**
     * Built-in providers; modules can extend via future hooks.
     *
     * ว่างไว้ตั้งใจ — Hub จะลงทะเบียน TimelineSearchProvider (ค้นข้าม item ของทุก
     * source) ที่นี่ ส่วน provider ของ gcms_chat ผูกกับโมดูล document/product
     * ที่ Hub ไม่มี จึงไม่ได้นำมาด้วย
     *
     * @return self
     */
    public static function defaults(): self
    {
        return new self();
    }
}
