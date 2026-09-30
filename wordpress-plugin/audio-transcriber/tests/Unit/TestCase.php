<?php
/**
 * Base test case.
 *
 * @package AudioTranscriber
 */

namespace AudioTranscriber\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

abstract class TestCase extends PHPUnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\stubs(
			array(
				'untrailingslashit' => static fn( $s ) => rtrim( $s, '/\\' ),
				'is_wp_error'       => static fn( $thing ) => $thing instanceof \WP_Error,
				'wp_strip_all_tags' => static fn( $s ) => strip_tags( $s ),
			)
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}
}
