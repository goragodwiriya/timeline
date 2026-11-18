# คู่มือขยายระบบ

สองงานที่ทำบ่อยที่สุด เขียนแยกกันเพราะขนาดงานต่างกันมาก

| อยากได้อะไร | ทำที่ไหน | เวลาที่ใช้ |
|---|---|---|
| เห็นข้อมูลเพิ่มจากระบบที่ต่อไว้แล้ว | แก้ mapper ของแอปนั้นไฟล์เดียว | สิบนาที |
| ต่อเว็บใหม่เข้ามา | เพิ่มโค้ดกลาง + เขียน mapper + ผูกที่ Hub | ครึ่งวัน |

---

## 1. เพิ่มข้อมูลที่ส่งกลับมาให้ดูอย่างเดียว

ใช้ฟิลด์ `facts` (`TIMELINE-PROTOCOL.md` §6.6) — ต้นทางจัดรูปข้อความมาให้เสร็จ Hub แค่แสดง

### แก้ที่ไฟล์เดียว

| แอปแบบไหน | ไฟล์ |
|---|---|
| ลูกของ adminframework | `<แอป>/modules/<โมดูล>/models/timeline.php` |
| นัดหมาย (ในตัว Hub) | `Gcms/Timeline/LocalProvider.php` |
| ระบบที่ไม่ใช่ Now.js | คอนโทรลเลอร์ที่ตอบ `timeline/items` ของระบบนั้น |

> รายชื่อระบบที่ต่อไว้จริงของเครื่องนี้ กับไฟล์ mapper ของแต่ละตัว อยู่ใน
> `docs/OPERATIONS.md` ซึ่งไม่ได้อยู่ใน repo · ดูจากหน้า `/connections` ก็ได้เหมือนกัน

### ขั้นตอน

**1) ดูก่อนว่าคอลัมน์มีจริง** อย่าเดาชื่อคอลัมน์เด็ดขาด

```bash
mysql -u<user> -p<pass> -e "SHOW COLUMNS FROM <db>.<table>"
```

ถ้าปลายทางอยู่บนเซิร์ฟเวอร์ ต้องดู schema ของ **เซิร์ฟเวอร์** ไม่ใช่สำเนาบนเครื่องนี้ — เคยพลาดมาแล้ว
เพราะสำเนาบนเครื่องพัฒนามีข้อมูลไม่ครบและบางตารางเก่ากว่า

**2) ดึงคอลัมน์เพิ่มใน query** ที่มีอยู่ ไม่ยิง query ใหม่ต่อแถว

```php
->select('id', 'url', 'name', 'email', 'phone', 'phone2', 'province', 'package_price')
```

ถ้าข้อมูลอยู่คนละตาราง ให้อ่านรวดเดียวทั้งตารางแล้วรวมใน PHP เหมือน `customerMoney()` —
sync หนึ่งรอบมีหลายร้อยแถว การยิง query ต่อแถวทำให้รอบ sync ยาวเป็นนาที

**3) ประกอบ `facts`** คืนเป็น array เรียงตามลำดับที่อยากให้แสดง

```php
'facts' => [
    ['label' => 'ลูกค้า', 'value' => $customer],
    ['label' => 'ชำระล่าสุด', 'value' => date('j/n/Y', $ts).' · '.number_format($amount, 2).' บาท'],
],
```

รูปย่อก็ได้ ถ้าประกอบทีละบรรทัดจะสั้นกว่ามาก

```php
'facts' => ['ลูกค้า' => $customer, 'ชำระล่าสุด' => $text],
```

**กติกาที่ `Item::facts()` บังคับให้**

| ข้อ | ค่า |
|---|---|
| จำนวนบรรทัด | ≤ 10 (เกินถูกตัด) |
| `label` | ≤ 40 ตัวอักษร |
| `value` | ≤ 120 ตัวอักษร |
| บรรทัดที่ค่าว่าง | ถูกตัดทิ้ง |

**4) เขียนค่าที่ติดลบหรือว่างให้อ่านออก** อย่าปล่อยตัวเลขดิบ

```php
// "ค้างชำระ -26,000" อ่านแล้วเข้าใจผิดว่าระบบคำนวณพลาด
$facts[] = $accrued < 0
    ? ['label' => 'ชำระดอกเบี้ยเกิน', 'value' => Currency::format(abs($accrued)).' '.$unit]
    : ['label' => 'ดอกเบี้ยค้างชำระ', 'value' => Currency::format($accrued).' '.$unit];
```

**5) ทดสอบที่ต้นทางก่อน** ไม่ต้องรอ sync

```bash
php -r '
include "<path ของแอปต้นทาง>/load.php";
$app = Kotchasan::createWebApplication("Gcms\Config");
$m = new \Myapp\Timeline\Model();
foreach (array_slice($m->items(new DateTime("-90 days"), new DateTime("+12 months")), 0, 3) as $raw) {
  $it = \Gcms\Timeline\Item::normalize($raw, "Asia/Bangkok");
  echo $it["title"], "\n";
  foreach ((array) $it["facts"] as $f) printf("   %-20s %s\n", $f["label"], $f["value"]);
}'
```

`Item::normalize()` คือตัวเดียวกับที่ endpoint ใช้ — ผ่านตรงนี้แปลว่าผ่านจริง

**6) sync แล้วดูผล**

```bash
# ที่ Hub
php -r '
include "<path ของ Hub>/load.php";
$app = Kotchasan::createWebApplication("Gcms\Config");
$src = \Kotchasan\DB::create()->first("sources", [["slug","<slug ของระบบนั้น>"]]);
echo json_encode(\Gcms\Timeline\SyncEngine::runOne($src), JSON_UNESCAPED_UNICODE), "\n";'
```

ข้อมูลจะขึ้นสามที่พร้อมกันโดยไม่ต้องแก้อะไรเพิ่ม

- การ์ดบนหน้า "วันนี้"
- `/find <ชื่อ>` ในแชต เมื่อเจอรายเดียว
- `meta_json` / `facts_json` ในฐานข้อมูล สำหรับงานที่ต้องอ่านย้อนหลัง

### สิ่งที่ไม่ต้องทำ

ไม่ต้องแก้อะไรที่ Hub เลย — ไม่ต้องเพิ่มคอลัมน์ ไม่ต้องแก้เทมเพลต ไม่ต้องแก้ API
`facts` ถูกเก็บเป็น JSON และหน้าจอวนแสดงทุกบรรทัดที่ส่งมา

> **`facts` อยู่ใน `payload_hash`** ตัวเลขเปลี่ยนอย่างเดียวก็นับว่า item เปลี่ยน
> sync รอบถัดไปจึงเห็นและอัปเดตให้เอง

---

## 2. ต่อเว็บใหม่เข้ามาเป็นระบบต้นทาง

### 2.1 ถ้าเว็บนั้นสร้างจาก adminframework (ทางที่ง่าย)

โค้ดกลางทำงานให้หมดแล้ว เหลือแค่บอกว่า "ข้อมูลของฉันแปลงเป็น item ได้อย่างไร"

**ก) ก๊อปโค้ดกลางจาก adminframework**

```bash
SRC=<path ของ adminframework>
DST=<path ของแอปใหม่>
cp -r $SRC/Gcms/Timeline           $DST/Gcms/
cp -r $SRC/modules/timeline        $DST/modules/
cp    $SRC/install/timeline.sql    $DST/install/
```

> ก๊อปแค่ `modules/timeline` ไม่พอ — จะได้ `Class "Gcms\Timeline\Guard" not found`
> ตอนที่ Hub เรียกเข้ามา ซึ่งเป็น 500 ที่ไม่มีอะไรบอกสาเหตุ

**ข) `.htaccess` ต้องมี `CGIPassAuth On`**

```apache
<IfModule mod_proxy_fcgi.c>
  CGIPassAuth On
</IfModule>
```

`mod_proxy_fcgi` ตัดหัวข้อ `Authorization` ทิ้งก่อนถึง PHP — ไม่ใส่บรรทัดนี้ token จะหายทั้งที่ส่งมาถูก
แล้วได้ 401 ที่หาสาเหตุไม่เจอ **เจอมาแล้วห้าครั้ง กับห้าแอป**

**ค) ตั้งค่าใน `Gcms/Config.php`**

```php
public $timeline_slug = 'myapp';        // ต้องตรงกับ slug ที่ตั้งไว้ฝั่ง Hub
public $timeline_name = 'ชื่อที่คนอ่าน';
public $timeline_mappers = [
    \Myapp\Timeline\Model::class
];
```

**ง) เขียน mapper** ไว้ใน **โมดูลของตัวเอง** ไม่ใช่ใน `modules/timeline`

```
modules/<โมดูล>/models/timeline.php   →   \<โมดูล>\Timeline\Model
```

เหตุผล: `modules/timeline` เป็นโค้ดกลางที่ก๊อปทับได้ตรง ๆ ถ้าวาง mapper ไว้ในนั้น
การอัปเดตโค้ดกลางครั้งเดียวจะลบ mapper ของทุกแอปทิ้ง

โครงขั้นต่ำ

```php
namespace Myapp\Timeline;

use Gcms\Timeline\MapperInterface;

class Model extends \Kotchasan\Model implements MapperInterface
{
    public function kinds()
    {
        return ['expiry.contract'];       // ประกาศไว้ให้ Hub ตั้งกฎเตือนล่วงหน้าได้
    }

    public function items(\DateTimeInterface $from, \DateTimeInterface $to)
    {
        $out = [];
        foreach (/* query ของคุณ */ as $row) {
            $out[] = [
                'uid'      => 'contract:'.$row['id'],   // ต้องนิ่งตลอดอายุของเรื่อง
                'kind'     => 'expiry.contract',
                'title'    => $row['name'],
                'subtitle' => 'สรุปสั้น ๆ หนึ่งบรรทัด',
                'due_at'   => $row['expire_date'],      // unix / Y-m-d / DateTime ก็ได้
                'all_day'  => true,
                'status'   => 'active',
                'priority' => 'normal',
                'facts'    => ['ผู้ติดต่อ' => $row['contact']],
                'links'    => [[
                    'label'   => 'เปิดในระบบ',
                    'url'     => rtrim(WEB_URL, '/').'/index.php?module=contract&id='.$row['id'],
                    'primary' => true
                ]]
            ];
        }

        return $out;
    }

    public function actions()
    {
        return [];                        // อ่านอย่างเดียวก็ส่ง array ว่าง
    }

    public function handleAction($uid, $action, array $params)
    {
        return null;                      // null = ไม่ใช่ของ mapper ตัวนี้
    }
}
```

**กติกาสามข้อที่พลาดบ่อย**

1. **`uid` ต้องนิ่ง** อย่าใส่วันที่ลงไป — สัญญาหนึ่งใบมีวันครบกำหนดที่ยังมีชีวิตอยู่ได้ครั้งละหนึ่ง
   ต่ออายุแล้วให้ `due_at` ขยับ ถ้าใส่วันที่ใน uid ระบบจะเห็นเป็นเรื่องใหม่ทุกครั้ง
   แล้วประวัติการเตือนกับสถานะที่ผู้ใช้กดไว้จะหายหมด
2. **ของที่เกินกำหนดต้องส่งมาเสมอ** แม้เก่ากว่า `$from` — หนี้ที่ค้างมาสองปีต้องไม่หายไปจากจอ
   เพราะกรอบเวลา · กรองด้วย `$to` อย่างเดียวพอ
3. **ต้องมี `due_at` หรือ `start_at` อย่างน้อยหนึ่ง** ไม่มีเวลาก็ไม่ใช่ timeline item
   `Item::normalize()` จะปฏิเสธพร้อมบอกว่า uid ไหน

**จ) ติดตั้งตารางและออก token**

```bash
php <แอปใหม่>/install/index.php     # หรือรัน install/timeline.sql เอง
```

แล้วออก token ในหน้าตั้งค่า API ของแอปนั้น

**ฉ) ผูกที่ Hub** เปิดหน้า "การเชื่อมต่อ" → **เพิ่มแอป** → กรอก

| ช่อง | ค่า |
|---|---|
| ชื่อย่อ | ตรงกับ `$cfg->timeline_slug` เป๊ะ ๆ |
| ที่อยู่ API | ถึงส่วนก่อน `/timeline/...` เช่น `https://example.com/api` |
| Token | ที่เพิ่งออก |

กด **ทดสอบการเชื่อมต่อ** ก่อนบันทึกเสมอ — มันเรียก `manifest` จริงและบอกว่าพลาดตรงไหน
ดีกว่าบันทึกแล้วรอรอบ sync แล้วค่อยมาหาสาเหตุ

**ช) ตั้งกฎเตือน** หน้าเดียวกัน ส่วน "การแจ้งเตือนเข้าแชต" → **เพิ่มกฎ**
เลือกชนิด เลือกระบบ กรอกช่วงล่วงหน้าเป็น `30, 7, 1`

### 2.2 ถ้าเว็บนั้นไม่ใช่ Now.js

ทำเป็น endpoint อะไรก็ได้ที่ตอบตาม `TIMELINE-PROTOCOL.md` — Hub ไม่สนใจว่าเบื้องหลังเป็นภาษาอะไร
ทำมาแล้วสองแบบ — PHP เปล่า + SQLite ที่ตอบซอง `{ok, data, meta}` และ PHP ไม่มีเฟรมเวิร์ก
ที่ตอบซอง `{success, code, message, data}` · Hub อ่านได้ทั้งคู่ (`TIMELINE-PROTOCOL.md` §3.4)

ต้องมีสามเส้นทาง

```
GET  {base}/timeline/manifest    บอกว่าเป็นใคร ผลิต kind อะไรได้บ้าง
GET  {base}/timeline/items       snapshot ทั้งหมดในกรอบเวลา
POST {base}/timeline/actions     ลงมือทำ (ข้ามได้ถ้าอ่านอย่างเดียว)
```

ทุกเส้นทางต้องรับ `Authorization: Bearer <token>` และตอบ 401 เมื่อ token ผิด
รายละเอียดซองข้อมูล การแบ่งหน้า และรูปแบบ error อยู่ใน `TIMELINE-PROTOCOL.md` §3–§5

### 2.3 ไม่มีทางลัดแบบ push แล้ว

เคยมี `POST /api/timeline/push` ให้ระบบต้นทางยิงเรื่องเข้ามาเองโดยไม่ต้องเขียน provider
**ถอดออกทั้งหมดแล้ว** (คอนโทรลเลอร์ · คอลัมน์ `sources.push_secret` · ช่องกรอกในฟอร์ม)
เพราะสิ่งที่มาทางนั้นคือเหตุการณ์ที่เกิดแล้วจบ ซึ่งไม่มีกำหนดเวลาให้เตือน และไม่มีทาง
reconcile — ไม่ใช่งานของ Hub (`HUB-DESIGN.md` §1.1 · `TIMELINE-PROTOCOL.md` §9)

ระบบที่ส่ง webhook ได้อยู่แล้วยังต้องเขียนสามเส้นทางตาม §2.2 เหมือนกัน

---

## 3. รายการตรวจก่อนบอกว่าเสร็จ

- [ ] `manifest` ตอบ 200 พร้อม `slug` ที่ตรงกับที่ตั้งไว้ฝั่ง Hub
- [ ] `items` ตอบ `meta.complete = true` และ `meta.total` ตรงกับจำนวนจริง
- [ ] token ผิด → 401 (ไม่ใช่ 500 และไม่ใช่ 200 ที่ข้อมูลว่าง)
- [ ] เรียกซ้ำสองครั้งได้ผลเหมือนเดิม (`uid` นิ่ง)
- [ ] ของที่เกินกำหนดยังส่งมาแม้เก่ากว่ากรอบเวลา
- [ ] ระบบย่อยพัง → ตอบ `meta.complete = false` ไม่ใช่ส่ง item ไม่ครบเงียบ ๆ
- [ ] กด "ทดสอบการเชื่อมต่อ" ที่หน้า "การเชื่อมต่อ" แล้วเขียว
- [ ] sync จริงหนึ่งรอบแล้ว `last_status = ok`
- [ ] ตั้งกฎเตือนของชนิดใหม่แล้ว (ไม่งั้นจะตกไปใช้กฎ `*` ซึ่งปิดอยู่)
