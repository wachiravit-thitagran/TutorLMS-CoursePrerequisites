# Tutor LMS Hook Matrix

รายการ hook / API ของ Tutor LMS ที่ปลั๊กอินนี้พึ่งพา พร้อมสถานะการตรวจสอบ

> **สำคัญ:** ชื่อ hook คือส่วนที่เปราะที่สุดของการทำ integration กับ LMS
> ทั้งหมดถูกรวมไว้ที่ `src/Infrastructure/TutorLMS/HookMap.php` เพียงไฟล์เดียว
> ถ้า Tutor เปลี่ยนชื่อ hook ให้แก้ที่นั่นจุดเดียว ไม่ต้องไล่แก้ใน Guards

## สถานะหมายถึงอะไร

| สถานะ | ความหมาย |
| --- | --- |
| **CI ทุก push** | `tests/Contract/HookContractTest.php` ยืนยันจากซอร์ส Tutor จริงในทุก push ผ่าน job `hooks` ของ `.github/workflows/tests.yml` — matrix เป็นเวอร์ชัน Tutor: `3.0.2`, `3.9.6`, `4.0.1`, `4.0.4`, `latest`, `dev` |
| **ไม่พบ 3.0.2–4.0.4** | ตรวจซอร์สแล้ว **ไม่มีจริง** ในช่วงเวอร์ชันนั้น เก็บชื่อไว้เป็นสำรอง (ผูก hook ที่ไม่มีจริงไม่มีค่าใช้จ่าย) แต่อย่านับว่าเป็นด่านที่ทำงาน |
| **ยืนยันมือ** | ตรวจจากซอร์สด้วยมือแล้ว แต่ **ยังไม่มี** อะไรใน CI คอยเฝ้า |
| **มั่นใจสูง** | เป็น hook ของ WordPress / WooCommerce เอง ไม่ได้พึ่ง Tutor |

`3.0.2` คือพื้นที่ `Compatibility::MIN_TUTOR` ประกาศว่ารองรับ ส่วน `4.0.1`
คือเวอร์ชันที่ production (bia-learn.psu.ac.th) ใช้อยู่

**เช็คในเครื่อง:**

```bash
TUTOR_PLUGIN_DIR="$PWD/.tutor" bin/install-tutor.sh 4.0.4
TUTOR_DIR="$PWD/.tutor/tutor" composer test:hooks
```

**สิ่งที่ contract test นี้ไม่ได้พิสูจน์:** hook มีอยู่ ≠ hook ถูกยิงบนหน้าที่เราต้องการ
เรื่องนั้นเป็นงานของ `tests/Integration/CourseLockNoticeTest.php`
ซึ่งเรนเดอร์หน้าคอร์สที่ถูกล็อกจริงแล้วนับจำนวนประกาศ

## 1. Enrollment

| Hook / Action | ใช้ที่ | สถานะ |
| --- | --- | --- |
| `tutor_before_enroll` | ก่อน `EnrollmentModel::do_enroll()` | **CI ทุก push** |
| `tutor_before_enrol_to_course` | `EnrollmentGuard::guard_action()` | **ไม่พบ 3.0.2–4.0.4** — accessor ผ่านได้เพราะ `tutor_before_enroll` มีจริง |
| `wp_ajax_tutor_course_enrollment` | ผูก priority 0 เพื่อตัดก่อน handler ของ Tutor | **CI ทุก push** |
| `wp_ajax_tutor_enrol_course` | สำรอง | **ไม่พบ 3.0.2–4.0.4** |
| `wp_ajax_tutor_place_free_order` | เส้นทางคอร์สฟรีใน Native eCommerce | **ไม่พบ 3.0.2–4.0.4** — Native eCommerce ไม่ได้ใช้ชื่อนี้ |
| REST `/tutor/v1/enrollments`, `/tutor/v1/course-enroll` | `rest_pre_dispatch` | route **ไม่มีใน Tutor free เลย** ทุกเวอร์ชันที่ตรวจ — CI ยืนยันได้แค่ว่า namespace `tutor/v1` ยังเป็นของ Tutor |
| `tutor_enroll_data` (filter) | `EnrollmentGuard::guard_enroll_data()` — ด่านสุดท้าย | **ยืนยันมือ 3.0.2–4.0.4** — ผูกตรงใน `EnrollmentGuard` ไม่ได้อยู่ใน `HookMap` จึงไม่มี CI คุม |

**REST fragment ทำไมยังเก็บไว้:** `rest_pre_dispatch` เทียบ fragment กับ route ที่
**ผู้เรียก** ขอมา ไม่ใช่กับ route ที่ Tutor ลงทะเบียน ด่านนี้จึงเผื่อไว้สำหรับ Pro
และแอปมือถือที่สมัครเรียนผ่าน REST การ assert ว่า route มีอยู่ในซอร์ส Tutor free
จะทำให้ CI แดงทั้งที่ guard ไม่ได้ผิด — สิ่งที่ตรวจได้จริงจึงเป็น namespace

**`tutor_enroll_data` และ `tutor_before_checkout_process` ควรย้ายเข้า `HookMap`**
เพื่อให้ contract test ครอบ แต่ตัวหลังจะทำให้ CI แดงทันที (ดูหัวข้อ 6)
ต้องแก้ guard ก่อน

## 2. ประกาศบนหน้าคอร์ส (course page notice)

`HookMap::course_page_notice_actions()` → `CourseLock::render_notice()`

| Hook | ไฟล์เทมเพลตของ Tutor | สถานะ |
| --- | --- | --- |
| `tutor_course/single/before/inner-wrap` | `templates/single-course.php` | **CI ทุก push** |
| `tutor_course/single/before/content` | `templates/single/course/course-content.php` | **CI ทุก push** |
| `tutor_course/single/entry/after` | `templates/single/course/course-entry-box.php` | **CI ทุก push** |
| `the_content` (filter) | — | fallback ตัวสุดท้าย สำหรับธีมที่เรนเดอร์คำอธิบายคอร์สผ่าน main loop |

**ทำไมสามชื่อ:** ทั้งสามอยู่ใน **ไฟล์เทมเพลตต่างกัน** ธีมที่ override เทมเพลตของ Tutor
มักทับหนึ่งหรือสองไฟล์ ไม่ทับทั้งหมด การกระจายชื่อข้ามไฟล์คือสิ่งที่ทำให้ประกาศไม่หาย
`HookContractTest::test_the_course_page_notice_has_a_foothold_in_more_than_one_template()`
บังคับข้อนี้ไว้ — ถ้า Tutor รวมเทมเพลตจนเหลือไฟล์เดียว CI จะเตือน

**ทำไมเลิกพึ่ง `the_content` เป็นทางหลัก:** `templates/single-course.php` ของ Tutor
เรนเดอร์คอร์สด้วย `get_the_ID()` และ **ไม่เคยเรียก `the_post()`** บน main query
ดังนั้น `in_the_loop()` เป็น false ทั้งหน้า filter ที่ guard ด้วย `in_the_loop()`
จึงไม่เคยทำงาน ผลคือคอร์สที่ถูกล็อกแสดงปุ่มสมัครเรียนตามปกติ **โดยไม่มีคำอธิบายใด ๆ**
(พบจริงบน bia-learn.psu.ac.th, Tutor 4.0.1)
การบังคับสิทธิ์ไม่ได้พลาด — Guards ทำงานปกติ — หายไปแค่คำอธิบาย
ซึ่งเป็นอาการที่แย่ที่สุดเพราะไม่มี error ให้เห็น

**ยิงซ้ำหลาย hook แล้วจะซ้ำไหม:** ไม่ `CourseLock::$notice_handled` ถูกตั้งเป็น true
ตัวแรกที่ทำงาน ตัวหลังคืนค่าออกทันที `Plugin::boot()` สร้าง `CourseLock` หนึ่งตัวต่อหนึ่ง
request จึงเท่ากับ "หนึ่งครั้งต่อ request" และ
`CourseLockNoticeTest::test_the_notice_renders_once_even_when_every_candidate_hook_fires()`
ยิงทุก hook พร้อม `the_content` ในหนึ่ง request แล้ว assert ว่าได้ 1 ชิ้นพอดี

## 3. Completion (ใช้ล้าง cache)

| Hook | ใช้ที่ | สถานะ |
| --- | --- | --- |
| `tutor_course_complete_after` | `Plugin::register_invalidation()` | **CI ทุก push** |
| `tutor_course_completed` | สำรอง | **ไม่พบ 3.0.2–4.0.4** |
| `tutor_after_enroll` | `Plugin::register_invalidation()` | **ยืนยันมือ 3.0.2–4.0.4** — ผูกตรงใน `Plugin` ไม่ได้อยู่ใน `HookMap` |

หากทั้งหมดไม่มีจริง ผลคือ cache จะค้างจนหมดอายุตาม TTL (ค่าเริ่มต้น 15 นาที)
ไม่ถึงขั้นทำให้สิทธิ์ผิด แต่คอร์สจะปลดล็อกช้า — ถือเป็น **บั๊กที่ต้องแก้ก่อน release**

## 4. Course Builder

| API | ใช้ที่ | สถานะ |
| --- | --- | --- |
| `tutor_before_course_builder_load` | enqueue ก่อน Tutor พิมพ์หน้า builder | **CI ทุก push** |
| JS `Tutor.CourseBuilder.Additional.registerContent()` | ลง editor ที่ `bottom_of_sidebar` | ยืนยันมือ 4.0.4 + `CourseBuilderContractTest` |

Builder ของ 4.x เป็น React แต่ปลั๊กอินลง component ผ่าน extension API ของ Tutor
และบันทึกผ่าน AJAX endpoint ของตัวเอง
(`tlp_save_course_rules`) ซึ่งทำให้ validation และ cycle detection ทำงานเหมือนกัน
ทั้งจาก builder และจาก classic editor

## 5. ฟังก์ชัน / โครงสร้างข้อมูลของ Tutor

ทุกอย่างในตารางนี้เรียกผ่าน `TutorAdapter` เท่านั้น และมี fallback เป็น SQL ตรง
ตารางนี้เป็นเรื่อง **ฟังก์ชันและ storage** ไม่ใช่ชื่อ hook — `HookContractTest`
จึงไม่ครอบ คนที่เฝ้าอยู่คือ `SeederContractTest` ในชุด integration

| สิ่งที่ใช้ | ทางหลัก | ทาง fallback | สถานะ |
| --- | --- | --- | --- |
| ตรวจสมัครเรียน | `tutor_utils()->is_enrolled()` | `wp_posts` post_type `tutor_enrolled`, `post_status = completed` | `SeederContractTest` (integration) |
| ตรวจเรียนจบ | `tutor_utils()->is_completed_course()` | `wp_comments` `comment_type = course_completed` | `SeederContractTest` (integration) |
| หา course จาก lesson/quiz | `tutor_utils()->get_course_id_by_subcontent()` | ไต่ `post_parent` ขึ้นไป 2 ชั้น | `SeederContractTest` (integration) |
| post type ของคอร์ส | `tutor()->course_post_type` | ค่าคงที่ `courses` | มั่นใจสูง |
| ตรวจ instructor | `tutor_utils()->is_instructor_of_this_course()` | `post_author` | ต้องยืนยัน |

## 6. eCommerce

| จุด | ใช้ที่ | สถานะ |
| --- | --- | --- |
| `woocommerce_add_to_cart_validation` | `PurchaseGuard` | มั่นใจสูง (เป็น hook ของ Woo) |
| `_tutor_course_product_id` (post meta) | reverse lookup product → course | **ยืนยันมือ 3.0.2–4.0.4** |
| `tutor_before_checkout_process` | `PurchaseGuard::validate_native_checkout()` | **ไม่พบ 3.0.2–4.0.4** — ด่านนี้ไม่ทำงานจริง ต้องหาชื่อที่ Native eCommerce ใช้แล้วแก้ |

`PurchaseGuard` ยังปลอดภัยอยู่เพราะ `EnrollmentGuard::guard_enroll_data()`
เป็นด่านสุดท้ายที่ปฏิเสธการเขียน record การสมัครเรียน แต่ผู้ซื้อจะถูกปฏิเสธช้ากว่าที่ควร
(หลังจ่ายเงิน ไม่ใช่ตอน checkout) — **ค้างเป็นงานที่ต้องแก้**

## 7. สิ่งที่ตั้งใจ **ไม่** ทำ

- ไม่เรียก class, method หรือไฟล์ใด ๆ ของ Tutor LMS Pro
- ไม่โหลดไฟล์จาก add-on `tutor-prerequisites`
- ตรวจว่า add-on ทางการเปิดอยู่หรือไม่ **เพื่อเตือนผู้ดูแลเท่านั้น**
  (`TutorAdapter::official_prerequisites_active()`)
