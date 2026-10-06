<?php
/**
 * MCP abilities tests.
 *
 * @package SpaceWork\TutorLearningPaths
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;
use SpaceWork\TutorLearningPaths\MCP;

if ( ! function_exists( 'wp_register_ability_category' ) ) {
	function wp_register_ability_category( $name, $args ) {
		$GLOBALS['tlp_mcp_categories'][ $name ] = $args;
	}
}

if ( ! function_exists( 'wp_register_ability' ) ) {
	function wp_register_ability( $name, $args ) {
		$GLOBALS['tlp_mcp_abilities'][ $name ] = $args;
	}
}

final class MCPTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['tlp_mcp_categories'] = array();
		$GLOBALS['tlp_mcp_abilities']  = array();
	}

	public function test_registers_prerequisite_abilities(): void {
		MCP::register_category();
		MCP::register_abilities();

		$this->assertArrayHasKey( 'tutorlms-prerequisites', $GLOBALS['tlp_mcp_categories'] );
		$this->assertArrayHasKey( 'tutorlms-prerequisites/check-access', $GLOBALS['tlp_mcp_abilities'] );
		$this->assertArrayHasKey( 'tutorlms-prerequisites/get-prerequisites', $GLOBALS['tlp_mcp_abilities'] );
		$this->assertArrayHasKey( 'tutorlms-prerequisites/get-dependent-courses', $GLOBALS['tlp_mcp_abilities'] );
		$this->assertArrayHasKey( 'tutorlms-prerequisites/flush-access-cache', $GLOBALS['tlp_mcp_abilities'] );
		$this->assertFalse( $GLOBALS['tlp_mcp_abilities']['tutorlms-prerequisites/flush-access-cache']['meta']['annotations']['readonly'] );
	}
}
