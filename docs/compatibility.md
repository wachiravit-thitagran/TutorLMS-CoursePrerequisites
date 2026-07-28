# Compatibility

## ขั้นต่ำ

| ส่วนประกอบ | ขั้นต่ำ | ทดสอบถึง |
| --- | --- | --- |
| PHP | 8.1 | 8.3 |
| WordPress | 6.4 | 6.8 |
| Tutor LMS (Free) | 3.0 | 4.0 |

ค่าทั้งหมดอยู่ใน `src/Compatibility.php`

## เมื่อ Tutor LMS ไม่ทำงาน

ปลั๊กอิน **ไม่ fatal error** พฤติกรรมคือ:

1. `Plugin::boot()` ตรวจ `Compatibility::is_satisfied()` แล้วหยุดก่อนลงทะเบียน guard ใด ๆ
2. แสดง admin notice
3. **ไม่ลบข้อมูล** — กฎและการตั้งค่าคงอยู่ และกลับมาทำงานทันทีเมื่อ Tutor กลับมา

## เมื่อ add-on Course Prerequisites ของ Tutor Pro เปิดอยู่

ตรวจพบผ่าน `TutorAdapter::official_prerequisites_active()` โดยไม่มีการเรียกโค้ดของ Pro

ค่าเริ่มต้น `conflict_policy = warn` คือแสดง notice แล้วให้ผู้ดูแลตัดสินใจ
เพราะการปิดระบบใดระบบหนึ่งให้อัตโนมัติ อาจทำให้สิทธิ์ของผู้เรียนเปลี่ยนโดยไม่มีใครรู้

เลือก `disable_self` ได้จากหน้าตั้งค่าเพื่อหยุด guards ของปลั๊กอินนี้ แต่หน้าตั้งค่า
ยังคงเปิดอยู่เพื่อให้ผู้ดูแลย้อนกลับได้

## Object cache

`AccessCache` ใช้ `wp_cache_*` group `tlp_access`

- ไซต์ที่มี Redis / Memcached drop-in → cache ข้ามรีเควสต์อัตโนมัติ
- ไซต์ที่ไม่มี → เป็น in-memory ต่อรีเควสต์ ยังคงตัดปัญหา N+1 ในหน้าเดียวได้
- **ไม่ใช้ transient แยกต่อผู้ใช้** เพราะจะพอกใน options table โดยไม่มีใครล้าง

การ invalidate ใช้ generation counter (`tlp_rules_version`) ไม่ใช่การไล่ลบ key
เพราะ distributed object cache enumerate key ไม่ได้

## Multilingual

ยังไม่ทำใน v1.0 ประเด็นที่ต้องแก้ใน v1.2:
- WPML/Polylang สร้าง course ID คนละตัวต่อภาษา → กฎที่ชี้ไปคอร์สภาษาอังกฤษ
  จะไม่ผ่านสำหรับผู้เรียนที่เรียนฉบับภาษาไทย ต้องมี translation mapping ที่ `TutorAdapter`

## ที่ต้องทดสอบซ้ำทุกครั้งที่ Tutor ออกเวอร์ชันใหม่

- Course Builder (ฟิลด์ยังโผล่ไหม)
- Enrollment ทั้ง 4 เส้นทาง
- Course completion → ปลดล็อกคอร์สถัดไป
- Learning interface / lesson URL ตรง
- WooCommerce enrollment
