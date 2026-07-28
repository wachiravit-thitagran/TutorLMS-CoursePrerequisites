=== Tutor Learning Paths ===
Contributors: spacework
Tags: tutor lms, lms, prerequisites, learning path, course
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Course prerequisites and learning paths for Tutor LMS, enforced on the server.

== Description ==

Lock a course until the learner has finished the courses that come before it.

Hiding the enrol button is not protection. This plugin refuses enrolment,
purchase and direct content access at the server, so a hand-crafted request is
turned away exactly like a click on a hidden button would be.

= What it does =

* Require one or more prerequisite courses before enrolment
* Combine requirements with ALL, ANY, AT LEAST N, or NONE
* Choose what a locked course looks like: visible but locked, hidden, preview
  only, purchasable in advance, or not purchasable
* Configure completed-course or enrolled-course requirements in both the
  Tutor 4.x Course Builder and the classic editor
* Block enrolment through the form, admin-ajax and REST alike
* Block direct lesson, quiz and assignment URLs
* Detect circular prerequisites before they are saved, so no course can become
  permanently unreachable
* Show learners a checklist of what they have finished and what is left
* Let other plugins add their own rule types through a filter

= Data =

Deactivating the plugin removes nothing. Uninstalling removes nothing either,
unless you change the uninstall policy in the settings first.

= Independence =

This plugin is an independent implementation. It contains no Tutor LMS Pro code
and calls none at runtime. If the official Course Prerequisites add-on is also
active, the plugin says so and lets you decide which one to keep.

== Installation ==

1. Install and activate Tutor LMS.
2. Upload the plugin and activate it.
3. Edit a course and open "Learning Path & Prerequisites".
4. Set site-wide defaults and uninstall behaviour under Settings ->
   Tutor Learning Paths.

== Frequently Asked Questions ==

= A learner is mid-course and I added a prerequisite. Are they locked out? =

No. Existing enrolments keep access to content; the lock governs new entry only.
Developers can change this with the `tlp_grandfather_existing_enrolment` filter.

= Does this work with WooCommerce? =

Yes. A locked course cannot be added to the cart, unless you set it to
"purchasable but not accessible", which sells advance access on purpose.

== Changelog ==

= 1.0.0 =
* Initial release: course prerequisites with server-side enforcement, Tutor
  Course Builder integration, settings, privacy-aware search, and query-safe
  archive filtering.
