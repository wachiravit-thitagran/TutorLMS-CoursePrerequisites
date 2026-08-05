# Access Entry Points

ทุกเส้นทางที่ผู้เรียนอาจเข้าถึงคอร์สได้ และชั้นที่ป้องกันไว้

หลักการเดียวของทั้งระบบ: **มีจุดตัดสินสิทธิ์จุดเดียวคือ `AccessGate::evaluate()`**
ทุก entry point ทำหน้าที่แค่แปลง request เป็น `(user_id, course_id, AccessContext)`
แล้วส่งให้ Gate ตัดสิน — การ duplicate logic คือช่องโหว่ในอนาคต

## แผนผัง

```
Browser / AJAX / REST / WooCommerce
              │
      Guard (แปลง request)
              │
       AccessGate::evaluate()
              │
        RuleEngine  ← RuleRegistry
              │
     AccessResult (allow | deny)
              │
        Responder (JSON 403 | WP_Error | redirect | wp_die)
```

## ตาราง entry point

| # | เส้นทาง | Guard | Context | สถานะ v1.0 |
| --- | --- | --- | --- | --- |
| 1 | Course archive / search / category | `VisibilityGuard` | `view` | ✅ |
| 2 | Related courses, shortcode listing | `VisibilityGuard` | `view` | ✅ |
| 2a | URL หน้าคอร์สโดยตรง เมื่อใช้โหมด hidden | `VisibilityGuard::guard_singular` | `view` | ✅ (ตอบ 404) |
| 3 | ปุ่ม Enroll (form post) | `EnrollmentGuard::guard_action` | `enroll` | ✅ |
| 4 | admin-ajax enrollment | `EnrollmentGuard::guard_ajax` | `enroll` | ✅ |
| 5 | REST enrollment | `EnrollmentGuard::guard_rest` | `enroll` | ✅ |
| 6 | เขียน enrollment record | `EnrollmentGuard::guard_enroll_data` | `enroll` | ✅ |
| 7 | WooCommerce add to cart | `PurchaseGuard::validate_woo_add_to_cart` | `purchase` | ✅ CI ยืนยันชื่อทุก push |
| 8 | Native eCommerce: ใส่ตะกร้า / เปิดหน้า checkout / กดจ่าย (Tutor ≥ 4.0) | `PurchaseGuard::validate_native_purchase` | `purchase` | ✅ CI ยืนยันชื่อทุก push |
| 8a | Native eCommerce: submit checkout (ทุกเวอร์ชัน รวม 3.x ที่ไม่มีด่านซื้อ) | `PurchaseGuard::guard_native_checkout` | `purchase` | ✅ CI ยืนยันชื่อทุก push |
| 9 | เปิด URL ของ Lesson ตรง ๆ | `ContentAccessGuard` | `content` | ✅ |
| 10 | เปิด URL ของ Quiz ตรง ๆ | `ContentAccessGuard` | `quiz` | ✅ |
| 11 | เปิด URL ของ Assignment ตรง ๆ | `ContentAccessGuard` | `content` | ✅ |
| 12 | Zoom / Google Meet lesson | `ContentAccessGuard` | `content` | ✅ (ผ่าน content post types) |
| 13 | ดาวน์โหลดไฟล์แนบ | — | `download` | ❌ v1.2 |
| 14 | Tutor REST API อ่านรายการคอร์ส | — | `api` | ❌ v1.3 |
| 15 | Membership / subscription auto-enroll | — | `enroll` | ❌ v1.2 |

## แถว 7–8a: CI ยืนยันอะไรบ้าง

ทุกชื่อของทั้งสามด่านอยู่ใน `HookMap` แล้ว job `hooks` จึงยืนยันว่ามันยังมีอยู่จริง
ในซอร์ส Tutor ทุกเวอร์ชันของ matrix (`3.0.2`, `3.9.6`, `4.0.1`, `4.0.4`, `latest`, `dev`)
ทุก push — รายละเอียดชื่อและที่ประกาศต่อเวอร์ชันอยู่ใน
[`tutor-hook-matrix.md`](tutor-hook-matrix.md) หัวข้อ 6

- **แถว 7** ใช้ `woocommerce_add_to_cart_validation` ซึ่งเป็น hook ของ WooCommerce
  ไม่ใช่ของ Tutor CI จึง **ปักหมุด** ชื่อไว้แทนการหาในซอร์ส Tutor
  (ไม่มี matrix เวอร์ชัน Woo เพราะชื่อ hook ของ Woo เสถียรพอ — ระบุไว้ตรง ๆ ไม่ปล่อยให้เดา)
- **แถว 8** ใช้ `tutor_can_purchase_course` ซึ่ง **มีตั้งแต่ Tutor 4.0.0**
  CI assert ว่ามีบน leg 4.x และ assert ว่า **ไม่มี** บน leg 3.x
- **แถว 8a** ใช้ `tutor_action_tutor_pay_now` ซึ่งมีทุกเวอร์ชัน
  เป็นด่านเดียวที่ Tutor 3.x มีให้ และเป็นด่านสำรองของ 4.x

**พฤติกรรมที่พิสูจน์แล้ว** (`tests/Integration/PurchaseGuardTest.php`):
ปฏิเสธคอร์สที่ล็อกและปล่อยผ่านเมื่อเรียน prerequisite จบ ทั้งสามด่าน
รวมถึงกรณีตะกร้าหลายคอร์ส, subscription checkout (ส่ง plan ID ไม่ใช่ course ID),
โหมด "purchasable" ที่ตั้งใจให้ซื้อล่วงหน้าได้, audit log และการผูก hook ตามชื่อใน `HookMap`

**ที่ยังไม่พิสูจน์:** ตัวการตอบกลับของแถว 8a — `Responder::deny()` จบ request
ด้วย redirect + `exit` หรือ `wp_die()` ซึ่ง test ไม่รอด จึงแยก "การตัดสิน"
(`refusals_for_posted_checkout()` — ทดสอบแล้ว) ออกจาก "การตอบ" (ไม่ได้ทดสอบ)
ข้อจำกัดเดียวกันนี้มีอยู่แล้วกับ `EnrollmentGuard::guard_action()` และ `guard_ajax()`
เป็นสมบัติของ `Responder` ไม่ใช่ของ guard และยังไม่มีการรัน checkout จริงของ Tutor
ตั้งแต่ต้นจนจบใน CI (ต้องมี Woo + payment gateway ใน CI ด้วย)

## หมายเหตุด้านพฤติกรรมที่ตั้งใจ

**หน้าคอร์สเองไม่ถูก redirect** — คอร์สที่ล็อกยังเปิดดูได้ (ยกเว้นโหมด `hidden`
ซึ่งตอบ 404 ทั้งจากรายการและ URL ตรง)
เพราะหน้านั้นคือที่ที่อธิบายว่าทำไมถึงล็อกและต้องทำอะไรต่อ
การเตะผู้เรียนออกจากหน้าที่อธิบายเหตุผล คือ UX ที่แย่โดยไม่ได้เพิ่มความปลอดภัยเลย

**ผู้ที่สมัครเรียนไปแล้วจะไม่ถูกตัดสิทธิ์ย้อนหลัง** — ถ้าผู้สอนเพิ่ม prerequisite
ทีหลัง ผู้เรียนที่กำลังเรียนอยู่จะยังเข้าเนื้อหาได้ (`tlp_grandfather_existing_enrolment`)
ล็อกมีผลกับ "การเข้าใหม่" เท่านั้น ปิดพฤติกรรมนี้ได้ด้วย filter ถ้าต้องการบังคับย้อนหลัง
