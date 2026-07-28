# Tutor LMS Hook Matrix

รายการ hook / API ของ Tutor LMS ที่ปลั๊กอินนี้พึ่งพา พร้อมสถานะการตรวจสอบ

> **สำคัญ:** ชื่อ hook คือส่วนที่เปราะที่สุดของการทำ integration กับ LMS
> ทั้งหมดถูกรวมไว้ที่ `src/Infrastructure/TutorLMS/HookMap.php` เพียงไฟล์เดียว
> ถ้า Tutor เปลี่ยนชื่อ hook ให้แก้ที่นั่นจุดเดียว ไม่ต้องไล่แก้ใน Guards
>
> สถานะ `ยืนยัน 4.0.3` = ตรวจจากซอร์ส Tutor LMS Free 4.0.3 และมี contract/
> integration test ครอบพฤติกรรมที่ปลั๊กอินพึ่งพา

## 1. Enrollment

| Hook / Action | ใช้ที่ | สถานะ |
| --- | --- | --- |
| `tutor_before_enrol_to_course` | `EnrollmentGuard::guard_action()` | ต้องยืนยัน |
| `tutor_before_enroll` | ก่อน `EnrollmentModel::do_enroll()` | ยืนยัน 4.0.3 |
| `tutor_enroll_data` (filter) | `EnrollmentGuard::guard_enroll_data()` — ด่านสุดท้าย | ยืนยัน 4.0.3 |
| `wp_ajax_tutor_course_enrollment` | ผูก priority 0 เพื่อตัดก่อน handler ของ Tutor | ต้องยืนยัน |
| `wp_ajax_tutor_place_free_order` | เส้นทางคอร์สฟรีใน Native eCommerce | ต้องยืนยัน |
| REST `/tutor/v1/enrollments` | `rest_pre_dispatch` | ต้องยืนยัน |

**วิธียืนยัน:** `grep -rn "do_action( 'tutor_before" wp-content/plugins/tutor/`
และ `grep -rn "wp_ajax_" wp-content/plugins/tutor/classes/`

## 2. Completion (ใช้ล้าง cache)

| Hook | ใช้ที่ | สถานะ |
| --- | --- | --- |
| `tutor_course_complete_after` | `Plugin::register_invalidation()` | ยืนยัน 4.0.3 |
| `tutor_course_completed` | สำรอง | ต้องยืนยัน |

หากทั้งสองตัวไม่มีจริง ผลคือ cache จะค้างจนหมดอายุตาม TTL (ค่าเริ่มต้น 15 นาที)
ไม่ถึงขั้นทำให้สิทธิ์ผิด แต่คอร์สจะปลดล็อกช้า — ถือเป็น **บั๊กที่ต้องแก้ก่อน release**

## 3. Course Builder

| API | ใช้ที่ | สถานะ |
| --- | --- | --- |
| `tutor_before_course_builder_load` | enqueue ก่อน Tutor พิมพ์หน้า builder | ยืนยัน 4.0.3 |
| JS `Tutor.CourseBuilder.Additional.registerContent()` | ลง editor ที่ `bottom_of_sidebar` | ยืนยัน 4.0.3 |

Builder ของ 4.x เป็น React แต่ปลั๊กอินลง component ผ่าน extension API ของ Tutor
และบันทึกผ่าน AJAX endpoint ของตัวเอง
(`tlp_save_course_rules`) ซึ่งทำให้ validation และ cycle detection ทำงานเหมือนกัน
ทั้งจาก builder และจาก classic editor

## 4. ฟังก์ชัน / โครงสร้างข้อมูลของ Tutor

ทุกอย่างในตารางนี้เรียกผ่าน `TutorAdapter` เท่านั้น และมี fallback เป็น SQL ตรง

| สิ่งที่ใช้ | ทางหลัก | ทาง fallback | สถานะ |
| --- | --- | --- | --- |
| ตรวจสมัครเรียน | `tutor_utils()->is_enrolled()` | `wp_posts` post_type `tutor_enrolled`, `post_status = completed` | ต้องยืนยัน |
| ตรวจเรียนจบ | `tutor_utils()->is_completed_course()` | `wp_comments` `comment_type = course_completed` | ต้องยืนยัน |
| หา course จาก lesson/quiz | `tutor_utils()->get_course_id_by_subcontent()` | ไต่ `post_parent` ขึ้นไป 2 ชั้น | ต้องยืนยัน |
| post type ของคอร์ส | `tutor()->course_post_type` | ค่าคงที่ `courses` | มั่นใจสูง |
| ตรวจ instructor | `tutor_utils()->is_instructor_of_this_course()` | `post_author` | ต้องยืนยัน |

## 5. eCommerce

| จุด | ใช้ที่ | สถานะ |
| --- | --- | --- |
| `woocommerce_add_to_cart_validation` | `PurchaseGuard` | มั่นใจสูง (เป็น hook ของ Woo) |
| `_tutor_course_product_id` (post meta) | reverse lookup product → course | ต้องยืนยัน |
| `tutor_before_checkout_process` | Native eCommerce | ต้องยืนยัน |

## 6. สิ่งที่ตั้งใจ **ไม่** ทำ

- ไม่เรียก class, method หรือไฟล์ใด ๆ ของ Tutor LMS Pro
- ไม่โหลดไฟล์จาก add-on `tutor-prerequisites`
- ตรวจว่า add-on ทางการเปิดอยู่หรือไม่ **เพื่อเตือนผู้ดูแลเท่านั้น**
  (`TutorAdapter::official_prerequisites_active()`)
