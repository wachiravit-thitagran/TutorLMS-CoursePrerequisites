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
| 7 | WooCommerce add to cart | `PurchaseGuard` | `purchase` | ✅ |
| 8 | Native eCommerce checkout | `PurchaseGuard` | `purchase` | ⚠️ ต้องยืนยัน hook |
| 9 | เปิด URL ของ Lesson ตรง ๆ | `ContentAccessGuard` | `content` | ✅ |
| 10 | เปิด URL ของ Quiz ตรง ๆ | `ContentAccessGuard` | `quiz` | ✅ |
| 11 | เปิด URL ของ Assignment ตรง ๆ | `ContentAccessGuard` | `content` | ✅ |
| 12 | Zoom / Google Meet lesson | `ContentAccessGuard` | `content` | ✅ (ผ่าน content post types) |
| 13 | ดาวน์โหลดไฟล์แนบ | — | `download` | ❌ v1.2 |
| 14 | Tutor REST API อ่านรายการคอร์ส | — | `api` | ❌ v1.3 |
| 15 | Membership / subscription auto-enroll | — | `enroll` | ❌ v1.2 |

## หมายเหตุด้านพฤติกรรมที่ตั้งใจ

**หน้าคอร์สเองไม่ถูก redirect** — คอร์สที่ล็อกยังเปิดดูได้ (ยกเว้นโหมด `hidden`
ซึ่งตอบ 404 ทั้งจากรายการและ URL ตรง)
เพราะหน้านั้นคือที่ที่อธิบายว่าทำไมถึงล็อกและต้องทำอะไรต่อ
การเตะผู้เรียนออกจากหน้าที่อธิบายเหตุผล คือ UX ที่แย่โดยไม่ได้เพิ่มความปลอดภัยเลย

**ผู้ที่สมัครเรียนไปแล้วจะไม่ถูกตัดสิทธิ์ย้อนหลัง** — ถ้าผู้สอนเพิ่ม prerequisite
ทีหลัง ผู้เรียนที่กำลังเรียนอยู่จะยังเข้าเนื้อหาได้ (`tlp_grandfather_existing_enrolment`)
ล็อกมีผลกับ "การเข้าใหม่" เท่านั้น ปิดพฤติกรรมนี้ได้ด้วย filter ถ้าต้องการบังคับย้อนหลัง
