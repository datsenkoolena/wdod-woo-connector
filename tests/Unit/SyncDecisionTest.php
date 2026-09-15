<?php
/**
 * Tests for Sync_Service::decide().
 *
 * @package WDOD\WooConnector\Tests
 */

namespace WDOD\WooConnector\Tests\Unit;

use WDOD\WooConnector\Interfaces\Api_Client_Interface;
use WDOD\WooConnector\Null_Logger;
use WDOD\WooConnector\Queue;
use WDOD\WooConnector\Result;
use WDOD\WooConnector\Settings;
use WDOD\WooConnector\Sync_Service;
use PHPUnit\Framework\TestCase;

/**
 * Class SyncDecisionTest.
 */
class SyncDecisionTest extends TestCase {

	/**
	 * Build a service with inert collaborators.
	 *
	 * @return Sync_Service
	 */
	private function service(): Sync_Service {
		$client = new class() implements Api_Client_Interface {
			/**
			 * Never called in these tests.
			 *
			 * @param array $payload Payload.
			 * @return Result
			 */
			public function send( array $payload ) {
				return Result::success( 200, 'stub' );
			}
		};

		return new Sync_Service( $client, new Null_Logger(), new Queue(), new Settings() );
	}

	/**
	 * Success: synced, no retry, attempts incremented.
	 *
	 * @return void
	 */
	public function test_success_is_synced_without_retry(): void {
		$decision = $this->service()->decide( Result::success( 200 ), 0, 5 );

		$this->assertSame( 'synced', $decision['status'] );
		$this->assertFalse( $decision['retry'] );
		$this->assertSame( 0, $decision['delay'] );
		$this->assertSame( 1, $decision['attempts'] );
	}

	/**
	 * Exponential backoff matrix.
	 *
	 * @return array
	 */
	public function backoff_provider(): array {
		return [
			'attempt 0' => [ 0, 300 ],
			'attempt 1' => [ 1, 600 ],
			'attempt 2' => [ 2, 1200 ],
			'attempt 3' => [ 3, 2400 ],
		];
	}

	/**
	 * Retryable failure below max: pending with 300 * 2^attempt delay.
	 *
	 * @dataProvider backoff_provider
	 *
	 * @param int $attempt Attempt.
	 * @param int $delay   Expected delay.
	 * @return void
	 */
	public function test_retryable_failure_below_max_is_pending_with_backoff( int $attempt, int $delay ): void {
		$decision = $this->service()->decide( Result::failure( 503, 'down' ), $attempt, 5 );

		$this->assertSame( 'pending', $decision['status'] );
		$this->assertTrue( $decision['retry'] );
		$this->assertSame( $delay, $decision['delay'] );
		$this->assertSame( $attempt + 1, $decision['attempts'] );
	}

	/**
	 * Retryable failure on the last allowed attempt: failed.
	 *
	 * @return void
	 */
	public function test_retryable_failure_at_max_is_failed(): void {
		$decision = $this->service()->decide( Result::failure( 500, 'boom' ), 4, 5 );

		$this->assertSame( 'failed', $decision['status'] );
		$this->assertFalse( $decision['retry'] );
		$this->assertSame( 5, $decision['attempts'] );
	}

	/**
	 * Non-retryable failure: failed immediately, even on the first attempt.
	 *
	 * @return void
	 */
	public function test_non_retryable_failure_is_failed_immediately(): void {
		$decision = $this->service()->decide( Result::failure( 404, 'gone' ), 0, 5 );

		$this->assertSame( 'failed', $decision['status'] );
		$this->assertFalse( $decision['retry'] );
		$this->assertSame( 1, $decision['attempts'] );
	}

	/**
	 * max_attempts = 1 disables retries entirely.
	 *
	 * @return void
	 */
	public function test_single_attempt_never_retries(): void {
		$decision = $this->service()->decide( Result::failure( 0, 'timeout' ), 0, 1 );

		$this->assertSame( 'failed', $decision['status'] );
		$this->assertFalse( $decision['retry'] );
	}
}
