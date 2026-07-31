<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Class CachingTest
 *
 * @package Newspack_Blocks
 */

/**
 * Newspack_Blocks_Caching test case.
 *
 * @group caching
 */
class CachingTest extends WP_UnitTestCase { // phpcs:ignore

	/**
	 * A self-referencing synced pattern should not cause infinite recursion
	 * in check_all_blocks_cache_status().
	 */
	public function test_self_referencing_reusable_block_does_not_recurse() {
		// Create a synced pattern (wp_block) with placeholder content.
		$pattern_id = self::factory()->post->create(
			[
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_title'   => 'Recursive Pattern',
				'post_content' => '<!-- wp:newspack-blocks/homepage-articles /-->',
			]
		);

		// Update the pattern to include a reference to itself.
		wp_update_post(
			[
				'ID'           => $pattern_id,
				'post_content' => sprintf(
					'<!-- wp:newspack-blocks/homepage-articles /--><!-- wp:block {"ref":%d} /-->',
					$pattern_id
				),
			]
		);

		// Create a post that embeds the recursive pattern.
		$post_id = self::factory()->post->create(
			[
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_title'   => 'Test Post',
				'post_content' => sprintf(
					'<!-- wp:block {"ref":%d} /-->',
					$pattern_id
				),
			]
		);

		// Simulate a singular request for this post.
		$this->go_to( get_permalink( $post_id ) );

		// If the recursion guard is working, this completes without a fatal error.
		Newspack_Blocks_Caching::check_all_blocks_cache_status();

		// Reaching this assertion means no infinite recursion occurred.
		$this->assertTrue( true, 'check_all_blocks_cache_status() completed without infinite recursion.' );
	}

	/**
	 * Two distinct synced patterns embedded in the same post should both
	 * be traversed by check_all_blocks_cache_status().
	 */
	public function test_non_recursive_reusable_blocks_are_traversed() {
		$pattern_a = self::factory()->post->create(
			[
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_title'   => 'Pattern A',
				'post_content' => '<!-- wp:newspack-blocks/homepage-articles /-->',
			]
		);
		$pattern_b = self::factory()->post->create(
			[
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_title'   => 'Pattern B',
				'post_content' => '<!-- wp:newspack-blocks/homepage-articles /-->',
			]
		);

		$post_id = self::factory()->post->create(
			[
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_title'   => 'Test Post',
				'post_content' => sprintf(
					'<!-- wp:block {"ref":%d} /--><!-- wp:block {"ref":%d} /-->',
					$pattern_a,
					$pattern_b
				),
			]
		);

		$this->go_to( get_permalink( $post_id ) );

		// Should complete without error — neither pattern references the other.
		Newspack_Blocks_Caching::check_all_blocks_cache_status();
		$this->assertTrue( true, 'Non-recursive patterns traversed without error.' );
	}

	/**
	 * A cycle between two synced patterns (A references B, B references A)
	 * should be caught by the recursion guard.
	 */
	public function test_mutual_recursion_between_patterns_is_caught() {
		// Create two patterns with placeholder content first.
		$pattern_a = self::factory()->post->create(
			[
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_title'   => 'Pattern A',
				'post_content' => '<!-- wp:paragraph --><p>placeholder</p><!-- /wp:paragraph -->',
			]
		);
		$pattern_b = self::factory()->post->create(
			[
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_title'   => 'Pattern B',
				'post_content' => '<!-- wp:paragraph --><p>placeholder</p><!-- /wp:paragraph -->',
			]
		);

		// Now wire them into a cycle: A includes B, B includes A.
		wp_update_post(
			[
				'ID'           => $pattern_a,
				'post_content' => sprintf(
					'<!-- wp:newspack-blocks/homepage-articles /--><!-- wp:block {"ref":%d} /-->',
					$pattern_b
				),
			]
		);
		wp_update_post(
			[
				'ID'           => $pattern_b,
				'post_content' => sprintf(
					'<!-- wp:newspack-blocks/homepage-articles /--><!-- wp:block {"ref":%d} /-->',
					$pattern_a
				),
			]
		);

		$post_id = self::factory()->post->create(
			[
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_title'   => 'Test Post',
				'post_content' => sprintf(
					'<!-- wp:block {"ref":%d} /-->',
					$pattern_a
				),
			]
		);

		$this->go_to( get_permalink( $post_id ) );

		Newspack_Blocks_Caching::check_all_blocks_cache_status();
		$this->assertTrue( true, 'Mutual recursion between two patterns was caught.' );
	}

	/**
	 * Reset Newspack_Blocks_Caching's static state between tests so that
	 * regeneration queues, locks, and index counters from one test don't
	 * leak into the next.
	 */
	public function set_up() {
		parent::set_up();
		$this->reset_caching_static_state();
	}

	/**
	 * Also reset state after each test, in case a test leaves cache entries
	 * or static state behind that could affect other test classes.
	 */
	public function tear_down() {
		$this->reset_caching_static_state();
		parent::tear_down();
	}

	/**
	 * Use reflection to reset Newspack_Blocks_Caching's private/protected
	 * static properties to their defaults.
	 */
	private function reset_caching_static_state() {
		$reflection = new ReflectionClass( 'Newspack_Blocks_Caching' );

		$queue_prop = $reflection->getProperty( 'regeneration_queue' );
		$queue_prop->setAccessible( true );
		$queue_prop->setValue( null, [] );

		$is_regenerating_prop = $reflection->getProperty( 'is_regenerating' );
		$is_regenerating_prop->setAccessible( true );
		$is_regenerating_prop->setValue( null, false );

		$index_prop = $reflection->getProperty( 'current_block_index' );
		$index_prop->setAccessible( true );
		$index_prop->setValue( null, 0 );

		$can_serve_prop = $reflection->getProperty( 'can_serve_all_blocks_from_cache' );
		$can_serve_prop->setAccessible( true );
		$can_serve_prop->setValue( null, true );
	}

	/**
	 * Helper to invoke a protected/private static method via reflection.
	 *
	 * @param string $method_name Method name.
	 * @param array  $args        Arguments to pass.
	 * @return mixed Method's return value.
	 */
	private function invoke_static_method( $method_name, $args = [] ) {
		$reflection = new ReflectionClass( 'Newspack_Blocks_Caching' );
		$method     = $reflection->getMethod( $method_name );
		$method->setAccessible( true );
		return $method->invokeArgs( null, $args );
	}

	/**
	 * Helper to read a private/protected static property via reflection.
	 *
	 * @param string $property_name Property name.
	 * @return mixed Property's current value.
	 */
	private function get_static_property( $property_name ) {
		$reflection = new ReflectionClass( 'Newspack_Blocks_Caching' );
		$property   = $reflection->getProperty( $property_name );
		$property->setAccessible( true );
		return $property->getValue();
	}

	/**
	 * Build minimal parsed block data for a cacheable homepage-articles block.
	 *
	 * @param array $attrs Block attributes.
	 * @return array Parsed block data, in the same shape as parse_blocks() output.
	 */
	private function get_cacheable_block_data( $attrs = [] ) {
		return [
			'blockName'    => 'newspack-blocks/homepage-articles',
			'attrs'        => $attrs,
			'innerBlocks'  => [],
			'innerHTML'    => '',
			'innerContent' => [],
		];
	}

	/**
	 * Navigate to a real singular post, so get_cache_group() (which relies on
	 * is_singular()/get_post_type()) behaves as it would on a real front-end
	 * request. Using the bare site home URL leaves get_post_type() returning
	 * false (front page without a singular query), which isn't representative
	 * of a real cacheable request.
	 *
	 * @return int The created post's ID.
	 */
	private function go_to_singular_post() {
		$post_id = self::factory()->post->create(
			[
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_title'  => 'Caching Test Post',
			]
		);
		$this->go_to( get_permalink( $post_id ) );
		return $post_id;
	}

	/**
	 * A cache entry older than the soft TTL but within the hard TTL should
	 * still be servable (stale), and should queue a background regeneration job.
	 */
	public function test_stale_cache_hit_is_served_and_queues_regeneration() {
		$this->go_to_singular_post();

		$block_data  = $this->get_cacheable_block_data( [ 'stale-test' => true ] );
		$cache_key   = $this->invoke_static_method( 'get_cache_key', [ $block_data ] );
		$cache_group = $this->invoke_static_method( 'get_cache_group' );

		// Seed the cache with an entry that is stale (past soft TTL) but not past the hard TTL.
		$stale_timestamp = time() - NEWSPACK_BLOCKS_CACHE_BLOCKS_TIME - 5;
		wp_cache_set(
			$cache_key,
			[
				'timestamp_generated' => $stale_timestamp,
				'cached_content'      => '<div>stale content</div>',
			],
			$cache_group,
			NEWSPACK_BLOCKS_CACHE_HARD_TTL
		);

		// Reset the index so get_cache_key() inside maybe_serve_cached_block() regenerates the same key.
		$this->reset_caching_static_state();
		wp_cache_set(
			$cache_key,
			[
				'timestamp_generated' => $stale_timestamp,
				'cached_content'      => '<div>stale content</div>',
			],
			$cache_group,
			NEWSPACK_BLOCKS_CACHE_HARD_TTL
		);

		$result = Newspack_Blocks_Caching::maybe_serve_cached_block( null, $block_data );

		$this->assertSame( '<div>stale content</div>', $result, 'Stale-but-valid cached content should still be served.' );

		$queue = $this->get_static_property( 'regeneration_queue' );
		$this->assertCount( 1, $queue, 'A regeneration job should have been queued for the stale block.' );

		$job = reset( $queue );
		$this->assertSame( $cache_group, $job['cache_group'] );
		$this->assertContains( $cache_key, $job['cache_keys'] );
	}

	/**
	 * A cache entry older than the hard TTL should be discarded entirely,
	 * not served stale.
	 */
	public function test_cache_entry_past_hard_ttl_is_discarded() {
		$this->go_to_singular_post();

		$block_data  = $this->get_cacheable_block_data( [ 'hard-ttl-test' => true ] );
		$cache_key   = $this->invoke_static_method( 'get_cache_key', [ $block_data ] );
		$cache_group = $this->invoke_static_method( 'get_cache_group' );

		$expired_timestamp = time() - NEWSPACK_BLOCKS_CACHE_HARD_TTL - 5;
		wp_cache_set(
			$cache_key,
			[
				'timestamp_generated' => $expired_timestamp,
				'cached_content'      => '<div>ancient content</div>',
			],
			$cache_group,
			NEWSPACK_BLOCKS_CACHE_HARD_TTL
		);

		$this->reset_caching_static_state();

		$result = Newspack_Blocks_Caching::maybe_serve_cached_block( null, $block_data );

		$this->assertNull( $result, 'A cache entry past the hard TTL should not be served, forcing a synchronous render.' );
		$this->assertEmpty( $this->get_static_property( 'regeneration_queue' ), 'No regeneration job should be queued for a discarded (hard-expired) entry.' );
	}

	/**
	 * Two identical block configurations (duplicated on the same page) that
	 * are both stale should only produce a single regeneration job, with both
	 * per-instance cache keys recorded against it.
	 */
	public function test_duplicate_stale_blocks_produce_single_regeneration_job() {
		$this->go_to_singular_post();

		$attrs        = [ 'duplicate-test' => true ];
		$block_data_1 = $this->get_cacheable_block_data( $attrs );
		$block_data_2 = $this->get_cacheable_block_data( $attrs );

		$cache_group = $this->invoke_static_method( 'get_cache_group' );

		// First instance: index 0.
		$cache_key_1 = $this->invoke_static_method( 'get_cache_key', [ $block_data_1 ] );
		// Second instance: index 1 (same attrs, different per-instance suffix).
		$cache_key_2 = $this->invoke_static_method( 'get_cache_key', [ $block_data_2 ] );

		$this->assertNotSame( $cache_key_1, $cache_key_2, 'Duplicate block instances should get distinct per-instance cache keys.' );

		$stale_timestamp = time() - NEWSPACK_BLOCKS_CACHE_BLOCKS_TIME - 5;
		$cached_data     = [
			'timestamp_generated' => $stale_timestamp,
			'cached_content'      => '<div>stale duplicate content</div>',
		];
		wp_cache_set( $cache_key_1, $cached_data, $cache_group, NEWSPACK_BLOCKS_CACHE_HARD_TTL );
		wp_cache_set( $cache_key_2, $cached_data, $cache_group, NEWSPACK_BLOCKS_CACHE_HARD_TTL );

		// Reset only the index counter so get_cache_key() regenerates cache_key_1 then cache_key_2 again,
		// while keeping the (empty) regeneration queue as-is.
		$index_prop = ( new ReflectionClass( 'Newspack_Blocks_Caching' ) )->getProperty( 'current_block_index' );
		$index_prop->setAccessible( true );
		$index_prop->setValue( null, 0 );

		Newspack_Blocks_Caching::maybe_serve_cached_block( null, $block_data_1 );
		Newspack_Blocks_Caching::maybe_serve_cached_block( null, $block_data_2 );

		$queue = $this->get_static_property( 'regeneration_queue' );
		$this->assertCount( 1, $queue, 'Duplicate block configurations should only produce a single regeneration job.' );

		$job = reset( $queue );
		$this->assertSame( $cache_group, $job['cache_group'] );
		$this->assertContains( $cache_key_1, $job['cache_keys'], 'The first instance\'s cache key should be recorded on the shared job.' );
		$this->assertContains( $cache_key_2, $job['cache_keys'], 'The second instance\'s cache key should be recorded on the shared job.' );
		$this->assertCount( 2, $job['cache_keys'], 'Both duplicate instances\' cache keys should be tracked so both get refreshed.' );
	}

	/**
	 * If render_block() throws while regenerating one queued job,
	 * regenerate_stale_blocks() must not propagate the exception, must still
	 * process any other queued jobs, must delete the failed job's lock, and
	 * must leave that job's existing cache entries untouched.
	 */
	public function test_regenerate_stale_blocks_survives_one_job_failing() {
		$reflection = new ReflectionClass( 'Newspack_Blocks_Caching' );
		$queue_prop = $reflection->getProperty( 'regeneration_queue' );
		$queue_prop->setAccessible( true );

		$cache_group = self::CACHE_GROUP_FOR_TEST;

		// Force render_block() to throw for this specific marker block, via a
		// test-only pre_render_block filter (registered after the class's own
		// hooks, so it runs after maybe_serve_cached_block() has already
		// bailed out early due to self::$is_regenerating being true).
		$throwing_filter = function ( $pre_render, $parsed_block ) {
			if ( isset( $parsed_block['attrs']['force_render_error'] ) ) {
				throw new \RuntimeException( 'Simulated render failure for testing.' );
			}
			return $pre_render;
		};
		add_filter( 'pre_render_block', $throwing_filter, 20, 2 );

		// A job whose render will throw an exception.
		$failing_block_data = $this->get_cacheable_block_data( [ 'force_render_error' => true ] );
		$failing_cache_key  = 'np_cached_block_failing_0';
		$failing_lock_key   = 'lock_' . $cache_group . '_np_cached_block_failing';
		$failing_content    = [
			'timestamp_generated' => time() - 1000,
			'cached_content'      => '<div>existing stale content that should survive</div>',
		];
		wp_cache_set( $failing_cache_key, $failing_content, $cache_group, NEWSPACK_BLOCKS_CACHE_HARD_TTL );
		wp_cache_add( $failing_lock_key, 1, $cache_group, NEWSPACK_BLOCKS_CACHE_REGEN_LOCK_TTL );

		// A normal, valid job that should succeed.
		$success_block_data = $this->get_cacheable_block_data( [ 'succeeds' => true ] );
		$success_cache_key   = 'np_cached_block_success_0';
		$success_lock_key    = 'lock_' . $cache_group . '_np_cached_block_success';
		wp_cache_add( $success_lock_key, 1, $cache_group, NEWSPACK_BLOCKS_CACHE_REGEN_LOCK_TTL );

		$queue_prop->setValue(
			null,
			[
				$cache_group . '_np_cached_block_failing' => [
					'block_data'  => $failing_block_data,
					'cache_group' => $cache_group,
					'cache_keys'  => [ $failing_cache_key ],
					'lock_key'    => $failing_lock_key,
				],
				$cache_group . '_np_cached_block_success' => [
					'block_data'  => $success_block_data,
					'cache_group' => $cache_group,
					'cache_keys'  => [ $success_cache_key ],
					'lock_key'    => $success_lock_key,
				],
			]
		);

		try {
			Newspack_Blocks_Caching::regenerate_stale_blocks();
		} catch ( \Throwable $error ) {
			$this->fail( 'regenerate_stale_blocks() must not let an individual job\'s render failure propagate: ' . $error->getMessage() );
		} finally {
			remove_filter( 'pre_render_block', $throwing_filter, 20 );
		}

		// The failed job's lock should have been released immediately.
		$this->assertFalse( wp_cache_get( $failing_lock_key, $cache_group ), 'Lock for the failed job should be deleted immediately on failure.' );

		// The failed job's existing stale cache entry should be untouched.
		$still_cached = wp_cache_get( $failing_cache_key, $cache_group );
		$this->assertSame( $failing_content, $still_cached, 'Existing cache entry for the failed job should be left untouched.' );

		// The successful job's lock should also have been released, and the queue reset.
		$this->assertFalse( wp_cache_get( $success_lock_key, $cache_group ), 'Lock for the successful job should be deleted after regeneration.' );
		$this->assertEmpty( $this->get_static_property( 'regeneration_queue' ), 'Regeneration queue should be reset after processing.' );
		$this->assertFalse( $this->get_static_property( 'is_regenerating' ), 'is_regenerating flag should be reset to false after processing.' );
	}

	const CACHE_GROUP_FOR_TEST = 'newspack_blocks';
}
