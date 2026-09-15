<?php
/**
 * Tests for Result.
 *
 * @package WDOD\WooConnector\Tests
 */

namespace WDOD\WooConnector\Tests\Unit;

use WDOD\WooConnector\Result;
use PHPUnit\Framework\TestCase;

/**
 * Class ResultTest.
 */
class ResultTest extends TestCase {

	/**
	 * Success results are never retryable.
	 *
	 * @return void
	 */
	public function test_success(): void {
		$result = Result::success( 200, 'HTTP 200 OK' );

		$this->assertTrue( $result->is_ok() );
		$this->assertSame( 200, $result->get_http_code() );
		$this->assertSame( 'HTTP 200 OK', $result->get_message() );
		$this->assertFalse( $result->is_retryable() );
	}

	/**
	 * Failure results carry code and message.
	 *
	 * @return void
	 */
	public function test_failure_values(): void {
		$result = Result::failure( 404, 'HTTP 404 Not Found' );

		$this->assertFalse( $result->is_ok() );
		$this->assertSame( 404, $result->get_http_code() );
		$this->assertSame( 'HTTP 404 Not Found', $result->get_message() );
	}

	/**
	 * Retryable classification matrix.
	 *
	 * @return array
	 */
	public function retryable_provider(): array {
		return [
			'network error (0)'    => [ 0, true ],
			'200 treated as fail'  => [ 200, false ],
			'404 not found'        => [ 404, false ],
			'429 rate limited'     => [ 429, true ],
			'500 server error'     => [ 500, true ],
			'503 unavailable'      => [ 503, true ],
			'400 bad request'      => [ 400, false ],
			'401 unauthorized'     => [ 401, false ],
			'301 redirect'         => [ 301, false ],
		];
	}

	/**
	 * Only 0, 429 and 5xx are retryable.
	 *
	 * @dataProvider retryable_provider
	 *
	 * @param int  $code     HTTP code.
	 * @param bool $expected Expected retryable flag.
	 * @return void
	 */
	public function test_retryable_classification( int $code, bool $expected ): void {
		$this->assertSame( $expected, Result::failure( $code, 'x' )->is_retryable() );
	}
}
