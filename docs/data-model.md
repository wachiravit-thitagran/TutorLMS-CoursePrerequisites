# Data Model (v1.0)

## หลักการ

- **กฎ (rules) อยู่ในตารางจริง** ไม่ใช่ serialized meta
  เพราะ v1.3 ต้องตอบคำถาม "คอร์สไหนพึ่งคอร์สนี้บ้าง" และ "ผู้เรียนคนไหนติดค้าง"
  โดยไม่ต้อง unserialize ทั้งแคตตาล็อก
- **การตั้งค่าระดับคอร์สอยู่ใน post meta เดียว** (`_tlp_config` เป็น JSON)
  เพราะอ่านพร้อมกันเสมอ และ key เดียวทำให้ `uninstall.php` ลบได้ครบจริง

## `wp_tlp_course_rules`

| Column | Type | หมายเหตุ |
| --- | --- | --- |
| `id` | BIGINT UNSIGNED PK | |
| `target_course_id` | BIGINT UNSIGNED | คอร์สที่ถูกล็อก |
| `rule_type` | VARCHAR(64) | slug ที่ลงทะเบียนไว้ใน `RuleRegistry` |
| `operator` | VARCHAR(32) | ความหมายขึ้นกับ rule type (v1.0 ยังไม่ใช้) |
| `source_id` | BIGINT UNSIGNED | course / quiz / path ที่กฎอ้างถึง |
| `value` | LONGTEXT | payload เฉพาะ type เก็บเป็น JSON |
| `rule_group` | SMALLINT | เตรียมไว้สำหรับ nested group ใน v2.0 |
| `position` | SMALLINT | ลำดับแสดงผล |
| `enabled` | TINYINT(1) | |
| `created_at` / `updated_at` | DATETIME | UTC |

**Index**

| Index | คอลัมน์ | ใช้ตอบคำถาม |
| --- | --- | --- |
| `target_enabled` | `(target_course_id, enabled)` | "กฎอะไรปกป้องคอร์สนี้" — read ที่ร้อนที่สุด |
| `source_type` | `(source_id, rule_type)` | "คอร์สไหนพึ่งคอร์สนี้" — ใช้ตรวจ cycle |
| `rule_group` | `(target_course_id, rule_group, position)` | เรียงลำดับตอนแสดงผล |

## `wp_tlp_activity_log`

เก็บเฉพาะเหตุการณ์ที่มีความหมาย: กฎเปลี่ยน, ถูกปฏิเสธ, ปลดล็อก, override
**ไม่เก็บ page view** — ถ้าเก็บ แถวที่สำคัญจริงจะจมหายไป

| Column | หมายเหตุ |
| --- | --- |
| `event` | ค่าคงที่ใน `ActivityLog` |
| `user_id` | ผู้เรียนที่เกี่ยวข้อง |
| `actor_id` | ผู้ที่ทำให้เกิดเหตุการณ์ (สำหรับ override) |
| `course_id`, `object_id` | |
| `reason` | reason_code |
| `context` | JSON |

## `_tlp_config` (post meta ของคอร์ส)

```json
{
  "enabled": true,
  "logic": "ALL",
  "min_required": 1,
  "visibility": "visible_locked",
  "locked_text": "",
  "redirect_to": 0,
  "admin_bypass": true,
  "instructor_bypass": true
}
```

## ตารางที่ **ยัง** ไม่มีใน v1.0

`wp_tlp_paths`, `wp_tlp_stages`, `wp_tlp_stage_courses`, `wp_tlp_user_progress`,
`wp_tlp_user_unlocks` — ทั้งหมดเป็นของ Learning Path ซึ่งเป็น v1.1
`Migrator::steps()` เตรียมโครงไว้แล้วให้เพิ่มเป็นขั้น ๆ ได้

## Versioning

- `tlp_db_version` — เวอร์ชัน schema, bump ที่ `Schema::DB_VERSION`
- `tlp_rules_version` — generation counter ของ cache ไม่ใช่ schema
  (ดู `AccessCache`) การ bump ค่านี้ทำให้ cached decision ทั้งไซต์กลายเป็น orphan
