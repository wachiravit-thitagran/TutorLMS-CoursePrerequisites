# Requirements — Tutor Learning Paths

## เป้าหมาย

ระบบ Learning Path / Course Prerequisites สำหรับ **Tutor LMS Free**
เขียนขึ้นใหม่ทั้งหมด ไม่พึ่งพาโค้ดของ Tutor LMS Pro ขณะรันไทม์

## หลักการออกแบบ 3 ข้อ

1. **จุดตัดสินสิทธิ์มีจุดเดียว** — `AccessGate::evaluate()`
   ทุก entry point เรียกจุดเดียวกัน การมี permission check ซ้ำสองที่
   คือช่องโหว่ที่รอเวลาเกิด
2. **ป้องกันฝั่งเซิร์ฟเวอร์เสมอ** — การซ่อนปุ่มคือการนำเสนอ ไม่ใช่การป้องกัน
   ทุกอย่างที่ CSS/JS ซ่อนไว้ ต้องถูกปฏิเสธซ้ำที่เซิร์ฟเวอร์
3. **แยก Tutor ออกจาก domain logic** — ทุกการเรียก `tutor_utils()`
   อยู่หลัง `TutorAdapter` เพียงจุดเดียว

## ขอบเขต v1.0 (รุ่นนี้)

| ทำแล้ว | รายการ |
| --- | --- |
| ✅ | Course prerequisites หลายคอร์ส |
| ✅ | เงื่อนไข ALL / ANY / AT_LEAST / NONE |
| ✅ | Rule type: course completed, course enrolled |
| ✅ | Rule registry เปิดให้ปลั๊กอินอื่นเพิ่ม rule ได้ |
| ✅ | Access Gate กลาง + 8 context |
| ✅ | บล็อก enrollment: form, AJAX, REST, enroll_data |
| ✅ | บล็อกเปิด Lesson / Quiz / Assignment ผ่าน URL ตรง |
| ✅ | บล็อกการซื้อ (WooCommerce + โครง Native eCommerce) |
| ✅ | โหมดการมองเห็น 5 แบบ |
| ✅ | Circular dependency detection (DFS แบบ iterative) |
| ✅ | Tutor 4.x Course Builder / metabox editor + AJAX course search ที่กรองตามสิทธิ์ |
| ✅ | Settings UI + uninstall policy |
| ✅ | Locked course notice พร้อม checklist ความคืบหน้า |
| ✅ | Object cache + generation-based invalidation |
| ✅ | Activity log |
| ✅ | Admin / Instructor bypass |
| ✅ | Template override จาก theme |
| ✅ | Public API + action/filter hooks |
| ✅ | Uninstall policy (ค่าเริ่มต้น: เก็บข้อมูล) |

## นอกขอบเขต v1.0

| รุ่น | รายการ |
| --- | --- |
| v1.1 | Learning Path CPT, Stage builder, progress UI, My Learning Paths, manual override |
| v1.2 | Quiz score rule, date rule, certificate rule, manual approval, notifications, WPML |
| v1.3 | Reports, WP-CLI, REST API ของปลั๊กอินเอง, import/export, dependency graph UI |
| v2.0 | Branching paths, nested rule groups, path certificate, webhooks, rule SDK |

## Acceptance criteria ของ v1.0

1. ผู้เรียนสมัครคอร์สที่ถูกล็อกไม่ได้ ทั้งผ่านหน้าเว็บ AJAX และ REST
2. ผู้เรียนเปิด Lesson/Quiz ด้วย URL ตรงไม่ได้
3. เรียนจบคอร์สก่อนหน้า → คอร์สถัดไปปลดล็อกโดยไม่ต้องแก้ข้อมูลด้วยมือ
4. เปลี่ยน rule → cache ถูกล้าง
5. ตรวจ circular dependency ได้ก่อนบันทึก
6. ผู้ดูแลเห็นเหตุผลที่คอร์สถูกล็อก (rule results)
7. ไม่มี fatal error เมื่อปิด Tutor LMS
8. Deactivate แล้วข้อมูลไม่หาย
9. หน้า Archive ไม่เกิด N+1 query
10. ไม่มีการพึ่ง Tutor LMS Pro ขณะรันไทม์

## ข้อกำหนดด้าน clean-room

ใช้ add-on ทางการเป็นข้อมูลอ้างอิงด้าน **พฤติกรรมภายนอกและ UX** เท่านั้น
ห้ามคัดลอกโค้ด, ห้ามโหลดไฟล์จาก Pro, ห้ามเรียก private class,
ห้ามใช้ชื่อ class / namespace / database key ที่จงใจเลียนแบบ

prefix ที่ใช้ทั้งระบบ: `tlp_` / `SpaceWork\TutorLearningPaths`
