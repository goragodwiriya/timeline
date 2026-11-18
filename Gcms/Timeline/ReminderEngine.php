<?php
/**
 * @filesource Gcms/Timeline/ReminderEngine.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Gcms\Timeline;

use Kotchasan\Language;

/**
 * ตัดสินว่าจะเตือนอะไร เมื่อไร และเตือนไปแล้วหรือยัง
 *
 * ทำงานสองจังหวะแยกกันชัดเจน
 *
 *   arm()  ตั้งนัดการเตือนให้ item ที่เพิ่งเปลี่ยน
 *   fire() ส่งอันที่ถึงเวลาแล้ว
 *
 * แยกกันเพราะ arm ต้องรันหลัง sync ทุกครั้ง (ข้อมูลเปลี่ยน = นัดเปลี่ยน)
 * ส่วน fire ต้องรันทุกรอบ cron ไม่ว่าจะมี sync หรือไม่
 *
 * @see HUB-DESIGN.md §6.2
 *
 * @since 1.0
 */
class ReminderEngine extends \Kotchasan\Model
{
    /**
     * เลยเวลานัดเกินเท่านี้แล้วไม่ต้องส่ง
     *
     * cron ตายไปสามวันแล้วกลับมา ถ้าไม่มีเพดานนี้จะได้ข้อความ 40 ฉบับรวดเดียว
     * ซึ่งแย่กว่าไม่เตือนเลย เพราะครั้งหน้าผู้ใช้จะปิดการแจ้งเตือนทิ้ง
     */
    const CATCHUP_HOURS = 12;

    /**
     * ตั้งนัดการเตือนของ item ที่ระบุ (หรือทั้งหมดเมื่อไม่ระบุ)
     *
     * ลบเฉพาะนัดที่ยังไม่ได้ส่ง แล้วสร้างใหม่ — อันที่ส่งไปแล้วห้ามแตะ
     * ประวัติว่าเคยเตือนอะไรไปแล้วต้องอยู่ครบ ไม่งั้นจะเตือนซ้ำเรื่องเดิม
     *
     * @param array|null $itemIds จำกัดเฉพาะ item เหล่านี้ · null = ทั้งหมดที่ยังใช้งานอยู่
     *
     * @return int จำนวนนัดที่สร้าง
     */
    public static function arm($itemIds = null)
    {
        $rules = self::rules();
        if (empty($rules)) {
            return 0;
        }

        $where = [
            ['I.vanished_at', null],
            ['I.status', 'active']
        ];
        if ($itemIds !== null) {
            if (empty($itemIds)) {
                return 0;
            }
            $where[] = ['I.id', $itemIds];
        }

        $items = static::createQuery()
            ->select('I.id', 'I.kind', 'I.source_id', 'I.due_at', 'I.start_at', 'I.all_day', 'S.state')
            ->from('items I')
            ->join('item_state S', [['S.item_id', 'I.id']], 'LEFT')
            ->where($where)
            ->fetchAll(true);

        $db = \Kotchasan\DB::create();
        $now = time();
        $floor = $now - self::CATCHUP_HOURS * 3600;
        $created = 0;

        foreach ($items as $item) {
            // เรื่องที่ผู้ใช้กดจัดการแล้ว ไม่ต้องมีนัดเตือนเหลืออยู่เลย
            //
            // fire() ข้ามมันอยู่แล้วทุกใบ (skipReason: "ผู้ใช้จัดการแล้ว") การสร้าง
            // จึงได้แค่แถว skipped เปล่า ๆ · ที่สำคัญกว่านั้นคือมันทำให้ลบประวัติ
            // ไม่ขาด — ผู้ใช้ลบบันทึกการเตือนของนัดที่กดเสร็จไปแล้ว แต่รอบ cron
            // ถัดไปสร้างกลับมาให้ใหม่ ราวกับปุ่มลบไม่ทำงาน
            //
            // ปล่อยให้ $wanted ว่างแทนที่จะ continue ทันที เพื่อให้คิวที่ค้างอยู่
            // ถูกลบไปด้วย — กดว่าจัดการแล้วต้องเงียบทั้งชุด ไม่ใช่เงียบเฉพาะใบใหม่
            //
            // ไม่ผูกกับเวลาโดยตั้งใจ · เกณฑ์ "เฉพาะนัดย้อนหลัง" เคยเขียนไว้แล้วพบว่า
            // เปราะ — สคริปต์ที่ require load.php เฉย ๆ ได้ timezone UTC ส่วน cron.php
            // ที่ผ่าน Kotchasan::createWebApplication() ได้ Asia/Bangkok ผลลัพธ์
            // ต่างกันเจ็ดชั่วโมงโดยที่โค้ดเหมือนกันทุกตัวอักษร
            $handled = in_array($item['state'], ['done', 'dismissed'], true);

            $anchor = $item['due_at'] ?: $item['start_at'];
            if ($anchor === null) {
                continue;
            }
            $rule = self::matchRule($rules, $item['kind'], (int) $item['source_id']);
            if ($rule === null) {
                continue;
            }

            $itemId = (int) $item['id'];
            $sent = self::sentKeys($itemId);

            $wanted = [];
            foreach ($handled ? [] : self::schedule($rule, strtotime($anchor), (bool) $item['all_day']) as $key => $fireAt) {
                // ส่งไปแล้วไม่สร้างซ้ำ แม้วันครบกำหนดจะขยับ
                if (isset($sent[$key])) {
                    continue;
                }
                // เลยหน้าต่างตามทันไปแล้ว สร้างไว้ก็ถูกข้ามตอน fire อยู่ดี
                // — item ที่เกินกำหนดมาสิบปีจะสร้างนัดย้อนหลังเป็นพันแถว
                if ($fireAt < $floor) {
                    continue;
                }

                $wanted[$key] = date('Y-m-d H:i:s', $fireAt);
            }

            // ไม่มีอะไรเปลี่ยนก็ไม่ต้องแตะฐานข้อมูล — arm() รันทุกรอบ cron
            // ถ้าลบแล้วสร้างใหม่ทุกครั้งจะเป็นการเขียนเปล่า ๆ ทุกห้านาทีตลอดไป
            // และ id ของนัดจะวิ่งหนีไปเรื่อยจนตามอ่านในบันทึกไม่ได้
            //
            // เรียงคีย์ก่อนเทียบ — `===` ของ array ในภาษานี้ถือว่าลำดับคีย์ต่างกัน
            // คือไม่เท่ากัน และลำดับที่ได้จากฐานข้อมูลไม่เคยตรงกับลำดับที่ schedule()
            // ผลิต การเทียบจึงไม่มีวันเป็นจริงเลยถ้าไม่เรียงก่อน
            $current = self::pending($itemId);
            ksort($current);
            ksort($wanted);
            if ($current === $wanted) {
                continue;
            }

            $db->delete('reminders', [['item_id', $itemId], ['sent_at', null]], 0);

            foreach ($wanted as $key => $fireAt) {
                $db->insert('reminders', [
                    'item_id' => $itemId,
                    'offset_key' => $key,
                    'fire_at' => $fireAt,
                    'channel' => '',
                    'status' => 'pending'
                ]);
                ++$created;
            }
        }

        return $created;
    }

    /**
     * ลบนัดที่ยังไม่ได้ส่งของ item ที่ไม่มีกฎรองรับแล้ว
     *
     * arm() สร้างนัดล่วงหน้าเป็นวัน ๆ และเมื่อกฎถูกปิด มันแค่ข้ามการสร้างใหม่
     * ไม่ได้ลบของเก่า — ปิดสวิตช์แล้วยังได้ข้อความชนิดนั้นต่ออีกหลายวันจนคิว
     * หมด ซึ่งอ่านออกมาเหมือนสวิตช์ไม่ทำงาน
     *
     * ลบเฉพาะที่ยังไม่ได้ส่ง · ประวัติว่าเคยเตือนอะไรไปแล้วต้องอยู่ครบ
     *
     * @return int จำนวนนัดที่ลบ
     */
    public static function prune()
    {
        $rules = self::rules();

        $rows = static::createQuery()
            ->select('R.id', 'I.kind', 'I.source_id')
            ->from('reminders R')
            ->join('items I', [['I.id', 'R.item_id']], 'INNER')
            ->where([['R.sent_at', null]])
            ->fetchAll(true);

        $orphans = [];
        foreach ($rows as $row) {
            if (self::matchRule($rules, (string) $row['kind'], (int) $row['source_id']) === null) {
                $orphans[] = (int) $row['id'];
            }
        }

        if (empty($orphans)) {
            return 0;
        }

        \Kotchasan\DB::create()->delete('reminders', [['id', $orphans]], 0);

        return count($orphans);
    }

    /**
     * ส่งการเตือนที่ถึงเวลาแล้ว
     *
     * @return array ['sent' => int, 'skipped' => int, 'failed' => int]
     */
    public static function fire()
    {
        $now = time();
        $result = ['sent' => 0, 'skipped' => 0, 'failed' => 0];

        $rows = static::createQuery()
            ->select('R.id', 'R.item_id', 'R.offset_key', 'R.fire_at',
                'I.kind', 'I.title', 'I.subtitle', 'I.due_at', 'I.start_at', 'I.all_day',
                'I.status', 'I.priority', 'I.links_json', 'I.source_id',
                // ข้อความเตือนต้องครบในตัวเอง จึงต้องดึงข้อมูลประกอบมาด้วย
                // ไม่ใช่ดึงแค่ชื่อกับวัน แล้วให้คนไปเปิดเว็บหาต่อ
                'I.facts_json', 'I.contact_json', 'I.hint_json',
                'Src.name AS source_name',
                'S.state', 'S.snooze_until')
            ->from('reminders R')
            ->join('items I', [['I.id', 'R.item_id']], 'INNER')
            ->join('sources Src', [['Src.id', 'I.source_id']], 'INNER')
            ->join('item_state S', [['S.item_id', 'I.id']], 'LEFT')
            ->where([
                ['R.sent_at', null],
                ['R.status', 'pending'],
                ['R.fire_at', '<=', date('Y-m-d H:i:s', $now)]
            ])
            ->orderBy('R.fire_at')
            ->fetchAll(true);

        if (empty($rows)) {
            return $result;
        }

        $rules = self::rules();
        $db = \Kotchasan\DB::create();

        foreach ($rows as $row) {
            $skip = self::skipReason($row, $now);
            if ($skip !== null) {
                $db->update('reminders', ['id', (int) $row['id']], [
                    'status' => 'skipped',
                    'sent_at' => date('Y-m-d H:i:s'),
                    'error' => $skip
                ]);
                ++$result['skipped'];
                continue;
            }

            // ช่องทางไม่ได้มาจากกฎ — ส่งทุกช่องทางที่ตั้งค่าไว้ ด้วยข้อความเดียวกัน
            // กฎตอบแค่ว่า "เตือนเรื่องนี้ไหม และล่วงหน้าเท่าไร"
            $channels = Notifier::channels();
            $error = Notifier::send($row, $channels);

            // ยังไม่ได้ตั้งค่าช่องทางใดเลย ไม่ใช่การส่งที่ล้มเหลว — ปล่อยค้างไว้
            // ให้ได้ส่งจริงเมื่อผู้ใช้ตั้งค่าเสร็จ ถ้าเผลอปิดนัดตรงนี้ การเตือน
            // ทุกอันระหว่างที่ยังตั้งค่าไม่เสร็จจะหายไปโดยไม่มีใครรู้
            if ($error === Language::get(Notifier::NO_CHANNEL)) {
                $db->update('reminders', ['id', (int) $row['id']], ['error' => $error]);
                ++$result['failed'];
                continue;
            }

            // ส่งได้บางช่องทางไม่ใช่ความล้มเหลว — บันทึกว่าไปถึงที่ไหนจริง
            // พร้อมเหตุผลของช่องทางที่พลาด ไม่ใช่เหมารวมว่าล้มเหลวทั้งหมด
            $delivered = Notifier::lastDelivered();
            $status = 'failed';
            if ($error === '') {
                $status = 'sent';
            } elseif (!empty($delivered)) {
                $status = 'partial';
            }

            $db->update('reminders', ['id', (int) $row['id']], [
                'status' => $status,
                'sent_at' => date('Y-m-d H:i:s'),
                'channel' => implode(',', $delivered),
                'error' => mb_substr($error, 0, 255)
            ]);

            if ($status === 'failed') {
                ++$result['failed'];
            } else {
                ++$result['sent'];
            }
        }

        return $result;
    }

    /**
     * เหตุผลที่ไม่ต้องส่งนัดนี้ · null = ส่งได้
     *
     * @param array $row
     * @param int   $now
     *
     * @return string|null
     */
    private static function skipReason(array $row, $now)
    {
        if ($now - strtotime($row['fire_at']) > self::CATCHUP_HOURS * 3600) {
            return Language::get('Past the catch-up window');
        }
        if ($row['status'] !== 'active') {
            return Language::sprintf('The source changed the status to %s', $row['status']);
        }
        if (in_array($row['state'], ['done', 'dismissed'], true)) {
            return Language::get('Handled by the user');
        }
        if ($row['state'] === 'snoozed' && !empty($row['snooze_until'])
            && strtotime($row['snooze_until']) > $now) {
            return Language::get('Snoozed');
        }

        return null;
    }

    /**
     * เวลาที่ต้องเตือนทั้งชุดของ item หนึ่งใบ
     *
     * @param array $rule
     * @param int   $anchor วันครบกำหนด
     * @param bool  $allDay
     *
     * @return array [offset_key => timestamp]
     */
    private static function schedule(array $rule, $anchor, $allDay)
    {
        $plan = [];

        foreach ($rule['offsets'] as $offset) {
            try {
                $interval = new \DateInterval($offset);
            } catch (\Exception $e) {
                continue;
            }
            // งานทั้งวันไม่มีเวลาในตัว ช่วงที่สั้นกว่าหนึ่งวันจึงไม่มีความหมาย
            //
            // ทุกช่วงถูกดันไปเป็นแปดโมงของวันนั้นอยู่แล้ว "ก่อน 1 ชั่วโมง" กับ
            // "ก่อน 1 วัน" จึงตกเวลาเดียวกันเป๊ะ แล้วยิงสองข้อความพร้อมกัน
            if ($allDay && self::isSubDay($interval)) {
                continue;
            }

            $fire = (new \DateTime('@'.$anchor))
                ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
                ->sub($interval);

            // งานทั้งวันไม่มีเวลาในตัว เตือนตอนเช้าแทนเที่ยงคืนซึ่งไม่มีใครดู
            if ($allDay) {
                $fire->setTime(8, 0);
            }

            $plan[$offset] = $fire->getTimestamp();
        }

        // เตือนซ้ำรายวันหลังเลยกำหนด สำหรับเรื่องที่ปล่อยไว้ไม่ได้ เช่นหนี้ค้าง
        for ($n = 1; $n <= (int) $rule['max_repeat']; ++$n) {
            $fire = (new \DateTime('@'.$anchor))
                ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
                ->modify('+'.$n.' days');
            if ($allDay) {
                $fire->setTime(8, 0);
            }
            $plan['overdue:'.$n] = $fire->getTimestamp();
        }

        return $plan;
    }

    /**
     * ช่วงเวลานี้สั้นกว่าหนึ่งวันแต่ไม่ใช่ศูนย์หรือเปล่า
     *
     * ศูนย์ (`PT0S` = เตือนตรงวันนั้น) ยังใช้ได้กับงานทั้งวัน จึงไม่นับ
     *
     * @param \DateInterval $interval
     *
     * @return bool
     */
    private static function isSubDay(\DateInterval $interval)
    {
        if ($interval->y > 0 || $interval->m > 0 || $interval->d > 0) {
            return false;
        }

        return $interval->h > 0 || $interval->i > 0 || $interval->s > 0;
    }

    /**
     * นัดที่ยังไม่ได้ส่งของ item นี้ ในรูปเดียวกับที่ schedule() ผลิต
     *
     * @param int $itemId
     *
     * @return array [offset_key => 'Y-m-d H:i:s']
     */
    private static function pending($itemId)
    {
        $rows = static::createQuery()
            ->select('offset_key', 'fire_at')
            ->from('reminders')
            ->where([['item_id', (int) $itemId], ['sent_at', null]])
            ->fetchAll(true);

        $current = [];
        foreach ($rows as $row) {
            $current[$row['offset_key']] = $row['fire_at'];
        }

        return $current;
    }

    /**
     * offset ที่ส่งไปแล้วของ item นี้
     *
     * @param int $itemId
     *
     * @return array
     */
    private static function sentKeys($itemId)
    {
        $rows = static::createQuery()
            ->select('offset_key')
            ->from('reminders')
            ->where([['item_id', (int) $itemId], ['sent_at', '!=', null]])
            ->fetchAll(true);

        $keys = [];
        foreach ($rows as $row) {
            $keys[$row['offset_key']] = true;
        }

        return $keys;
    }

    /**
     * กฎการเตือนทั้งหมด
     *
     * @return array
     */
    public static function rules()
    {
        $rows = static::createQuery()
            ->select('source_id', 'kind', 'offsets_json', 'max_repeat')
            ->from('reminder_rules')
            ->where(['enabled', 1])
            ->fetchAll(true);

        // จัดกลุ่มตามระบบต้นทาง — คีย์ 0 คือกฎที่ใช้กับทุกระบบ
        $rules = [];
        foreach ($rows as $row) {
            $rules[(int) $row['source_id']][$row['kind']] = [
                'offsets' => (array) json_decode((string) $row['offsets_json'], true),
                'max_repeat' => (int) $row['max_repeat']
            ];
        }

        return $rules;
    }

    /**
     * กฎ "ขึ้นหน้ากระดานไหม" ของทุกชนิด
     *
     * อ่านทุกแถวไม่สนใจ `enabled` — สองสวิตช์นี้ไม่เกี่ยวกัน กฎที่ปิดการส่งแชต
     * ไว้ยังต้องบอกได้ว่าให้แสดงบนจอหรือเปล่า ถ้าใช้ rules() ตัวเดิมซึ่งกรอง
     * `enabled = 1` ทิ้ง กฎที่ปิดแชตไว้จะกลายเป็น "ไม่มีกฎ" แล้วตกไปใช้ค่า
     * ปริยายคือแสดง ซึ่งตรงข้ามกับที่ตั้งไว้
     *
     * รูปร่างเหมือน rules() เป๊ะ เพื่อให้ matchRule() ตัวเดียวใช้ได้กับทั้งสอง
     * เรื่อง — ลำดับ wildcard ต้องเหมือนกัน ไม่งั้นคนตั้งค่าจะเดาไม่ถูกว่า
     * `alert.*` ครอบอะไรบ้างในบริบทไหน
     *
     * @return array
     */
    public static function boardRules()
    {
        $rows = static::createQuery()
            ->select('source_id', 'kind', 'visible')
            ->from('reminder_rules')
            ->cacheOff()
            ->fetchAll(true);

        $rules = [];
        foreach ($rows as $row) {
            $rules[(int) $row['source_id']][$row['kind']] = ['visible' => !empty($row['visible'])];
        }

        return $rules;
    }

    /**
     * ชนิดนี้ควรขึ้นหน้ากระดานไหม
     *
     * ไม่มีกฎ = แสดง · ชนิดใหม่ที่ต้นทางเพิ่งประกาศต้องโผล่ให้เห็นเองทันที
     * การซ่อนต้องเป็นสิ่งที่มีคนตั้งใจสั่ง ไม่ใช่ผลข้างเคียงของการไม่ได้ตั้งค่า
     *
     * @param array  $rules ผลจาก boardRules()
     * @param string $kind
     * @param int    $sourceId
     *
     * @return bool
     */
    public static function isVisible(array $rules, $kind, $sourceId = 0)
    {
        $rule = self::matchRule($rules, $kind, $sourceId);

        return $rule === null || !empty($rule['visible']);
    }

    /**
     * เลือกกฎที่ตรงที่สุด — ชื่อเต็ม แล้วค่อยหมวด แล้วค่อยกฎกลาง
     *
     * ทำให้ชนิดใหม่ที่ยังไม่มีใครตั้งกฎให้ ได้การเตือนที่พอเข้าเค้าทันที
     * แทนที่จะเงียบไปเฉย ๆ โดยไม่มีใครรู้
     *
     * @param array  $rules
     * @param string $kind
     *
     * @return array|null
     */
    public static function matchRule(array $rules, $kind, $sourceId = 0)
    {
        // เจาะจงระบบต้นทางก่อนเสมอ แล้วค่อยถอยมาใช้กฎกลาง
        //
        // เว็บที่ผูกไว้แต่ละที่ต้องการช่วงเตือนไม่เท่ากัน — โฮสติ้งของ gcms.in.th
        // อยากรู้ล่วงหน้าสามสิบวันเพราะต้องแจ้งลูกค้า ส่วนใบรับรองของ BluHost
        // ต่ออัตโนมัติอยู่แล้วจึงเตือนสามวันพอ · ทั้งสองเป็น expiry เหมือนกัน
        // กฎที่ผูกกับชนิดอย่างเดียวจึงบังคับให้ทั้งสองใช้ค่าเดียวกัน
        $scopes = (int) $sourceId > 0 ? [(int) $sourceId, 0] : [0];
        foreach ($scopes as $scope) {
            $set = $rules[$scope] ?? [];
            if (isset($set[$kind])) {
                return $set[$kind];
            }
            $prefix = explode('.', $kind)[0].'.*';
            if (isset($set[$prefix])) {
                return $set[$prefix];
            }
            if (isset($set['*'])) {
                return $set['*'];
            }
        }

        return null;
    }
}
