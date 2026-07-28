# Testing

## สองชุด แยกกันชัดเจน

| ชุด | รันด้วยอะไร | ใช้เวลา | ตอบคำถามอะไร |
| --- | --- | --- | --- |
| **Unit** | PHP อย่างเดียว ไม่มี WordPress ไม่มี DB | ~1 วินาที | ตรรกะถูกไหม (ALL/ANY/AT_LEAST, cycle detection) |
| **Integration** | WordPress จริง + Tutor LMS จริง + MySQL | ~2–5 นาที | ตรรกะต่อสายกับข้อมูลจริงถูกไหม |

กฎง่าย ๆ: ถ้า test ต้องการให้ WordPress โหลด มันคือ integration test
ทุกอย่างใต้ `src/Domain/` ถูกเขียนให้ทดสอบได้โดยไม่ต้องมี WordPress — นั่นคือเหตุผลที่แยก layer ไว้ตั้งแต่แรก

## รันในเครื่อง

### Unit

```bash
composer install
composer test:unit
```

### Integration

ต้องมี MySQL และ `svn` ในเครื่อง

```bash
# ครั้งเดียว
bin/install-wp-tests.sh wordpress_test root '' 127.0.0.1 latest
bin/install-tutor.sh                 # หรือ bin/install-tutor.sh 4.0.0 เพื่อ pin เวอร์ชัน

# ทุกครั้ง
composer test:integration
```

ตัวแปรที่ปรับได้: `WP_TESTS_DIR`, `WP_CORE_DIR`, `TUTOR_VERSION`

## เมื่อ integration suite แดง — รันอันนี้ก่อน

```bash
composer test:contract
```

`SeederContractTest` คือ canary ของทั้งชุด มันตรวจว่า **สิ่งที่ seeder เขียน คือสิ่งที่ Tutor ถือว่าเป็นจริง**

- ถ้า contract test ผ่าน แต่ test อื่นแดง → ตรรกะของเราพัง
- ถ้า contract test แดง → Tutor เปลี่ยนวิธีเก็บข้อมูล ไปแก้ `Seeder` และ `TutorAdapter`

ถ้าไม่มีชั้นนี้ การที่ Tutor เปลี่ยน storage จะทำให้ test แดง 30 ตัวพร้อมกันโดยไม่มีตัวไหนบอกสาเหตุ

## Seed scenarios

`tests/Fixtures/Scenarios.php` เก็บ "โลก" ที่ seed ไว้เป็นรูปแบบมาตรฐาน
test บรรยายพฤติกรรม scenario บรรยายโลก — แยกกันไว้เพื่อไม่ให้ทุกไฟล์ test
มี setup 40 บรรทัดที่ค่อย ๆ ไม่ตรงกัน

| Scenario | รูปร่าง | ใช้ทดสอบ |
| --- | --- | --- |
| `linear_chain(n)` | A → B → C | การปลดล็อกทีละขั้น |
| `diamond()` | 1 → (2, 3) → 4 | ALL ข้ามสองสาย + ต้องไม่ถูกมองว่าเป็น cycle |
| `any_of(n)` | ปลดล็อกด้วยตัวใดตัวหนึ่ง | ANY |
| `at_least(n, min)` | วิชาเลือก n ตัว ต้องผ่าน min | AT_LEAST |
| `none_of()` | จบคอร์ส X แล้วปิดคอร์สนี้ | NONE (exclusion) |
| `mixed_visibility()` | 1 คอร์สต่อ 1 โหมดการมองเห็น | visibility 5 แบบ |
| `broken_reference()` | prerequisite ถูกลบหลังบันทึกกฎ | คอร์สต้องไม่ล็อกถาวร และต้องไม่ผ่าน ANY ฟรี ๆ |
| `cycle_attempt()` | เตรียม A→B ไว้ให้ลองปิดวง | การปฏิเสธตอนบันทึก |
| `wide_catalogue(n)` | n คอร์ส gate ครึ่งหนึ่ง | query budget / N+1 |
| `instructor_owned()` | คอร์สของผู้สอนคนหนึ่ง | bypass ของผู้สอน |
| `gated_content()` | คอร์สล็อกที่มี lesson + quiz จริง | เปิด URL ตรง |

สถานะผู้เรียนที่ seed ได้: ยังไม่สมัคร / สมัครแล้ว / เรียนจบ
`Seeder::complete()` จะสมัครให้อัตโนมัติก่อน เพราะ Tutor ไม่มีทางมีสถานะ "จบแต่ไม่ได้สมัคร"
การ seed สถานะที่แอปไปถึงไม่ได้ คือการทดสอบสิ่งที่ไม่มีอยู่จริง

## CI matrix

| Job | ขอบเขต |
| --- | --- |
| `lint` | `php -l` ทุกไฟล์ + PHPCS |
| `unit` | PHP 8.1 / 8.2 / 8.3 / 8.4 |
| `integration` | PHP 8.1–8.4 × WP 6.4 / 6.6 / 6.8 / latest / nightly (18 ชุด) |

**ที่ตั้งเป็น non-blocking โดยตั้งใจ:** PHP 8.4 และ WP nightly
สองอย่างนี้คือสัญญาณเตือนล่วงหน้า ไม่ใช่ประตูกั้นการ merge
ถ้าทำให้ PR แดง ทีมจะเรียนรู้ที่จะมองข้ามมัน แล้วก็จะพลาดตอนที่มันสำคัญจริง

**ที่ exclude:** PHP 8.4 × WP 6.4/6.6 — สองเวอร์ชันนั้นออกก่อน PHP 8.4
จะแดงด้วยเหตุผลที่ไม่เกี่ยวกับปลั๊กอินนี้เลย

**Job `results`** มีไว้เพราะ `continue-on-error` ทำให้ job รายงานว่าสำเร็จ
แม้ leg ทดลองจะพัง — job นี้จึงตรวจผลจริงอีกชั้นก่อนอนุญาตให้ merge

**Scheduled run ทุกวันจันทร์** ไม่ได้มีไว้ตรวจโค้ดเรา แต่ตรวจว่า Tutor
ออกเวอร์ชันใหม่ที่ทำให้ integration พังหรือยัง — ให้เรารู้ก่อนลูกค้า

## Query budget

`QueryBudgetTest` ยืนยันเพดาน ไม่ใช่ตัวเลขเป๊ะ ๆ
การ assert จำนวน query แบบเป๊ะทำให้ทุกการเปลี่ยนแปลงของ WordPress ทำ build แดง
แต่การ assert เพดานยังจับสิ่งที่ควรจับได้ คือ query ที่แอบเข้าไปอยู่ใน loop

ตัวที่สำคัญที่สุดคือ `test_the_archive_does_not_scale_its_queries_with_the_catalogue`:
เพิ่มคอร์ส 4 เท่า จำนวน query ต้องไม่เพิ่ม 4 เท่า

## สิ่งที่ยังไม่มี

- E2E ผ่านเบราว์เซอร์จริง (Playwright) — รอ v1.1 ที่มี Learning Path UI
- ทดสอบ WooCommerce checkout เต็มรูปแบบ — ต้องติดตั้ง Woo ใน CI ด้วย
- Multisite
- ทดสอบร่วมกับ Tutor LMS Pro (ไม่มีให้ดาวน์โหลดสาธารณะ)
