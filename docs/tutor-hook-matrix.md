# Tutor LMS Hook Matrix

รายการ hook / API ของ Tutor LMS ที่ปลั๊กอินนี้พึ่งพา พร้อมสถานะการตรวจสอบ

> **สำคัญ:** ชื่อ hook คือส่วนที่เปราะที่สุดของการทำ integration กับ LMS
> ทั้งหมดถูกรวมไว้ที่ `src/Infrastructure/TutorLMS/HookMap.php` เพียงไฟล์เดียว
> ถ้า Tutor เปลี่ยนชื่อ hook ให้แก้ที่นั่นจุดเดียว ไม่ต้องไล่แก้ใน Guards

## สถานะหมายถึงอะไร

| สถานะ | ความหมาย |
| --- | --- |
| **CI ทุก push** | `tests/Contract/HookContractTest.php` ยืนยันจากซอร์ส Tutor จริงในทุก push ผ่าน job `hooks` ของ `.github/workflows/tests.yml` — matrix เป็นเวอร์ชัน Tutor: `3.0.2`, `3.9.6`, `4.0.1`, `4.0.4`, `latest`, `dev` |
| **CI ทุก push (มีตั้งแต่ X)** | CI ยืนยันแบบรู้เวอร์ชัน: ตั้งแต่ `X` ขึ้นไป assert ว่า **มี**, ต่ำกว่านั้น assert ว่า **ไม่มี** — ไม่ใช่ `markTestSkipped()` ทุก leg ของ matrix จึงยืนยันอะไรอย่างหนึ่งจริง ๆ ถ้า Tutor back-port ลงมา CI จะแดงพร้อมบอกให้ลด floor |
| **ไม่พบ 3.0.2–4.0.4** | ตรวจซอร์สแล้ว **ไม่มีจริง** ในช่วงเวอร์ชันนั้น เก็บชื่อไว้เป็นสำรอง (ผูก hook ที่ไม่มีจริงไม่มีค่าใช้จ่าย) แต่อย่านับว่าเป็นด่านที่ทำงาน |
| **ปักหมุด** | ชื่อของโปรเจกต์อื่น (ตอนนี้มีแค่ WooCommerce) อ่านซอร์สของ Tutor ยืนยันไม่ได้ จึงตรวจจากซอร์สโปรเจกต์นั้นครั้งเดียวแล้วปักค่าไว้ที่ `HookContractTest::EXTERNAL_NAMES` แก้ชื่อใน `HookMap` โดยไม่แก้ที่ปักหมุด = CI แดง |
| **ยืนยันมือ** | ตรวจจากซอร์สด้วยมือแล้ว แต่ **ยังไม่มี** อะไรใน CI คอยเฝ้า |

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

**และมันไม่ได้ตรวจชื่อที่ไม่เคยเข้ามาใน `HookMap`** — นี่คือรอยรั่วที่ทำให้
`PurchaseGuard` ผูกอยู่กับ `tutor_before_checkout_process` (ชื่อที่ Tutor
**ไม่เคยมี** เลยตั้งแต่ 3.0.2 ถึง 4.0.4) ได้นานโดย CI เขียวตลอด
ปิดด้วย `tests/Contract/PluginHookSourceTest.php` ซึ่งอ่านซอร์ส **ของปลั๊กอินเราเอง**
แล้วบังคับสองข้อ:

1. ห้ามเขียนชื่อ hook ของ Tutor เป็น literal ที่ไหนนอก `HookMap` —
   การต่อ prefix กับชื่อจาก `HookMap` (`'tutor_action_' . $action`,
   `'wp_ajax_' . $action`) ยังทำได้ เพราะส่วนที่เน่าได้คือตัวแปร
2. accessor ใน `HookMap` ต้องมีคนเรียกใช้จริง — entry ที่ไม่มีใครใช้
   ทำให้ดูเหมือนมีด่านทั้งที่ไม่มี

ชุดนี้ไม่ต้องมี Tutor และไม่ต้องมี WordPress แต่อยู่ใน suite เดียวกัน
เพราะมันคือสิ่งที่ทำให้ `HookContractTest` เชื่อถือได้

## 1. Enrollment

| Hook / Action | ใช้ที่ | สถานะ |
| --- | --- | --- |
| `tutor_before_enroll` | ก่อน `EnrollmentModel::do_enroll()` | **CI ทุก push** |
| `tutor_before_enrol_to_course` | `EnrollmentGuard::guard_action()` | **ไม่พบ 3.0.2–4.0.4** — accessor ผ่านได้เพราะ `tutor_before_enroll` มีจริง |
| `wp_ajax_tutor_course_enrollment` | ผูก priority 0 เพื่อตัดก่อน handler ของ Tutor | **CI ทุก push** |
| `wp_ajax_tutor_enrol_course` | สำรอง | **ไม่พบ 3.0.2–4.0.4** |
| `wp_ajax_tutor_place_free_order` | เส้นทางคอร์สฟรีใน Native eCommerce | **ไม่พบ 3.0.2–4.0.4** — Native eCommerce ไม่ได้ใช้ชื่อนี้ |
| REST `/tutor/v1/enrollments`, `/tutor/v1/course-enroll` | `rest_pre_dispatch` | route **ไม่มีใน Tutor free เลย** ทุกเวอร์ชันที่ตรวจ — CI ยืนยันได้แค่ว่า namespace `tutor/v1` ยังเป็นของ Tutor |
| `tutor_enroll_data` (filter) | `HookMap::enrolment_data_filters()` → `EnrollmentGuard::guard_enroll_data()` — ด่านสุดท้าย | **CI ทุก push** — 3.0.2 `classes/Utils.php:2614`, 3.9.6 `classes/Utils.php:2501`, 4.0.1 / 4.0.4 `models/EnrollmentModel.php:97` |

**REST fragment ทำไมยังเก็บไว้:** `rest_pre_dispatch` เทียบ fragment กับ route ที่
**ผู้เรียก** ขอมา ไม่ใช่กับ route ที่ Tutor ลงทะเบียน ด่านนี้จึงเผื่อไว้สำหรับ Pro
และแอปมือถือที่สมัครเรียนผ่าน REST การ assert ว่า route มีอยู่ในซอร์ส Tutor free
จะทำให้ CI แดงทั้งที่ guard ไม่ได้ผิด — สิ่งที่ตรวจได้จริงจึงเป็น namespace

**`tutor_enroll_data` ย้ายเข้า `HookMap` แล้ว** (`enrolment_data_filters()`)
สังเกตว่าที่ประกาศย้ายไฟล์ระหว่าง 3.9.6 กับ 4.0.1 (`classes/Utils.php` →
`models/EnrollmentModel.php`) — ชื่อเท่านั้นที่คงที่ ซึ่งเป็นเหตุผลที่ contract test
ตรวจ "ชื่อยังถูกประกาศอยู่ไหม" ไม่ใช่ "ยังอยู่ไฟล์เดิมไหม"

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
| `tutor_course_complete_after` | `HookMap::course_completed_actions()` → `Plugin::register_invalidation()` | **CI ทุก push** |
| `tutor_course_completed` | สำรอง | **ไม่พบ 3.0.2–4.0.4** |
| `tutor_after_enroll` | `HookMap::after_enrol_actions()` → `Plugin::register_invalidation()` | **CI ทุก push** — 3.0.2 `classes/Utils.php:2631`, 3.9.6 `classes/Utils.php:2518`, 4.0.1 / 4.0.4 `models/EnrollmentModel.php:114` |

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

ทุกชื่อในหัวข้อนี้อยู่ใน `HookMap` แล้ว ไม่มีอะไรเหลือเป็น literal ใน
`PurchaseGuard` (บังคับด้วย `PluginHookSourceTest`)

### 6.1 WooCommerce (entry point #7)

| ชื่อ | `HookMap` accessor | ประกาศที่ | สถานะ |
| --- | --- | --- | --- |
| `woocommerce_add_to_cart_validation` | `woo_add_to_cart_filter()` | WooCommerce เอง: `includes/class-wc-form-handler.php:983`, `:1015`, `:1065` และ `includes/class-wc-ajax.php:520` (ตรวจกับ woocommerce/woocommerce trunk, 11.1.0-dev) | **ปักหมุด** |
| `_tutor_course_product_id` (post meta) | `course_product_meta_key()` | 3.0.2 `classes/Course.php:54`, 3.9.6 `classes/Course.php:56`, 4.0.1 / 4.0.4 `classes/Course.php:60` (`Course::COURSE_PRODUCT_ID_META`) | **CI ทุก push** |

**ทำไมไม่มี matrix เวอร์ชัน WooCommerce:** ชื่อ hook ของ Woo เสถียรในระดับที่ Tutor
ไม่ใช่ — `woocommerce_add_to_cart_validation` อยู่มาตั้งแต่ Woo 1.x และ Woo ไม่เปลี่ยน
ชื่อ public hook โดยไม่มี deprecation shim การเพิ่มแกนเวอร์ชัน Woo เข้ามาจะจ่าย
runner หลายเท่าเพื่อข้อมูลที่ไม่มี พูดออกมาตรง ๆ ดีกว่าปล่อยให้เป็นข้อสันนิษฐานเงียบ ๆ
สิ่งที่ CI ทำแทนคือ **ปักหมุด** ชื่อไว้ ใครแก้ชื่อใน `HookMap` แล้วไม่ได้ไปแก้ที่ปักหมุด
CI แดงทันที และการต้องแก้ที่ปักหมุดคือสัญญาณว่า "ไปเปิดซอร์ส Woo ดูอีกครั้ง"

**ที่ Woo ไม่ยิง filter นี้:** `WC_Cart::add_to_cart()` **ไม่ได้** apply filter นี้เอง
ตัวที่ apply คือ form handler กับ AJAX handler ดังนั้น helper `tutor_add_to_cart()`
ของ Tutor (ซึ่งเรียก `WC_Cart::add_to_cart()` ตรง ๆ ผ่าน `ecommerce/Cart/WooCart.php`)
จะข้ามด่านนี้ Tutor free ไม่ได้เรียก helper ตัวนั้นเลย — ปุ่มบนหน้าคอร์สคือ
`<form>` ที่ post `name="add-to-cart"` (`templates/single/course/add-to-cart-woocommerce.php:69`)
และปุ่มใน loop คือปุ่ม `ajax_add_to_cart` ของ Woo เอง ทั้งสองเส้นทางผ่าน filter ปกติ
แต่ Pro / แอปมือถืออาจเรียก helper นั้น เส้นทางนั้นจึงถูกจับที่ `tutor_enroll_data` ทีหลัง

### 6.2 Native eCommerce (entry point #8)

| ชื่อ | `HookMap` accessor | ประกาศที่ | สถานะ |
| --- | --- | --- | --- |
| `tutor_can_purchase_course` (filter) | `purchase_gate_filters()` | 4.0.1 / 4.0.4 `ecommerce/CartController.php:227` (add to cart), `ecommerce/CheckoutController.php:648` (pay now), `ecommerce/CheckoutController.php:1055` (โหลดหน้า checkout) — **ไม่มีใน 3.0.2 / 3.9.6 / 3.9.12** | **CI ทุก push (มีตั้งแต่ 4.0.0)** |
| `tutor_action_tutor_pay_now` | `native_checkout_actions()` | 3.0.2 `ecommerce/CheckoutController.php:96`, 3.9.6 / 4.0.1 / 4.0.4 `ecommerce/CheckoutController.php:108` — dispatch จาก `classes/Tutor.php::init_action()` (`do_action( 'tutor_action_' . $tutor_action )`) | **CI ทุก push** |
| `object_ids` (request field) | `checkout_object_ids_field()` | 3.0.2 `ecommerce/CheckoutController.php:396`, 3.9.6 `:580`, 4.0.1 / 4.0.4 `:597` | **CI ทุก push** |
| ~~`tutor_before_checkout_process`~~ | — | **ไม่มีในเวอร์ชันใดเลย 3.0.2 → 4.0.4** | **ลบแล้ว** |

**สิ่งที่ผิดอยู่เดิม:** `PurchaseGuard` ผูก `tutor_before_checkout_process` ซึ่ง Tutor
ไม่เคยประกาศ ด่านแรกของการซื้อจึงไม่เคยทำงานเลย ผู้เรียนยังถูกจับได้ที่
`guard_enroll_data` แต่นั่นคือ "หลังจ่ายเงิน" ไม่ใช่ "ตอน checkout" ซึ่งไม่ตรงกับที่
`readme.txt` สัญญาไว้

**ทำไมต้องสองชื่อ ไม่ใช่ชื่อเดียว:** สองอันนี้ **ไม่ใช่ตัวสำรองของกันและกัน**
แต่ครอบเวอร์ชันคนละช่วง

- Tutor 4.0.0 ขึ้นไปมี `tutor_can_purchase_course` ซึ่งเป็น "ด่านซื้อ" ที่ Tutor
  ทำมาเพื่อเรื่องนี้จริง ๆ คืน `WP_Error` = ปฏิเสธ และ Tutor เอาข้อความไปแสดงให้ผู้เรียน
  ยิงครบทั้งสามจุดที่การซื้อเริ่มได้ (ใส่ตะกร้า / เปิดหน้า checkout / กดจ่าย)
- Tutor 3.0.x–3.9.x **ไม่มีด่านซื้ออะไรเลย** ไม่ใช่ว่าใช้ชื่ออื่น สิ่งที่เหลือให้กั้น
  จึงเป็นตัว submit ของ checkout เอง — `tutor_action_tutor_pay_now` ผูก priority 0
  ตัดหน้า handler ของ Tutor แบบเดียวกับที่ `EnrollmentGuard` ทำกับ AJAX
  ผลลัพธ์ผ่าน `Responder::deny()` (redirect กลับหน้าคอร์สพร้อม `tlp_locked`)
  แทนที่จะเป็นข้อความในหน้า checkout

`HookContractTest::test_the_native_purchase_path_has_a_gate_on_every_supported_tutor()`
เขียนเรื่องนี้ไว้เป็น assertion: leg 4.x assert ว่า `tutor_can_purchase_course` **มี**,
leg 3.x assert ว่า **ไม่มี** — ไม่ใช่ skip เพื่อไม่ให้ใครมา "แก้" leg 3.0.2 ที่แดง
ด้วยการลบด่านเก่าออก

**ข้อจำกัดที่รู้ตัว:** บน leg 3.0.2 / 3.9.6 การพิมพ์ชื่อ `tutor_can_purchase_course`
ผิดจะแยกไม่ออกจาก "ไม่มีจริงตามที่คาด" — ทั้งสองกรณีคือ "ไม่พบ" leg ที่จับ typo ตัวนี้ได้
คือ 4.0.1 / 4.0.4 / `latest` / `dev` (พิสูจน์แล้วว่าแดงจริงเมื่อแก้ชื่อให้ผิด)

**`tutor_pay_incomplete_order` ตั้งใจไม่ผูก:** request นั้นส่ง order ID มา ไม่ใช่ course ID
การแปลงกลับต้องเข้าไปเรียก order model ของ Tutor และ order ที่จ่ายซ้ำก็ถูกกั้นไปแล้ว
ตอนสร้าง

**`tutor_before_order_create` ตั้งใจไม่ผูก:** มีจริงทุกเวอร์ชัน (3.0.2
`ecommerce/OrderController.php:262` → 4.0.4 `:235`) และดูน่าใช้ แต่กลไกปฏิเสธของมันแย่
ค่าที่ filter คืนไปเข้า `OrderModel::create_order()` ตรง ๆ คืน `WP_Error` แล้ว type error
คืน `array()` แล้ว `$wpdb->insert()` พังจนกลายเป็น exception ที่ไม่มีใคร catch ใน
`CheckoutController::pay_now()` (fatal error) และ exception จาก action ก็เดินเส้นเดียวกัน
ด่านที่ปฏิเสธด้วยการทำให้ระบบพังไม่ใช่ด่าน — `tutor_action_tutor_pay_now` ยิงก่อนหน้านั้น
และปฏิเสธได้อย่างสะอาด

## 7. สิ่งที่ตั้งใจ **ไม่** ทำ

- ไม่เรียก class, method หรือไฟล์ใด ๆ ของ Tutor LMS Pro
- ไม่โหลดไฟล์จาก add-on `tutor-prerequisites`
- ตรวจว่า add-on ทางการเปิดอยู่หรือไม่ **เพื่อเตือนผู้ดูแลเท่านั้น**
  (`TutorAdapter::official_prerequisites_active()`)
