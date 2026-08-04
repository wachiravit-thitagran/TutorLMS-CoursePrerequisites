<?php
/**
 * PHPUnit bootstrap for the Tutor hook contract suite.
 *
 * Reuses the unit bootstrap - no WordPress, no database - because the contract
 * reads Tutor LMS's source off disk rather than running it. That is what makes
 * running the suite against six Tutor versions cheap enough to do on every push.
 *
 * @package SpaceWork\TutorLearningPaths
 */

declare( strict_types = 1 );

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/Contract/TutorSource.php';
