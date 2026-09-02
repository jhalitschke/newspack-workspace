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
	 * The cache group the class uses for ordinary front-end requests.
	 */
	const CACHE_GROUP_FOR_TEST = 'newspack_blocks';

	/**
	 * Block used for the regeneration mechanics tests. A core block keeps those
	 * tests about the caching layer rather than about what Homepage Posts renders;
	 * it is made cacheable via the newspack_blocks_cacheable_blocks filter.
	 */
	const REGENERATION_TEST_BLOCK = 'core/paragraph';

	/**
	 * Action Scheduler calls recorded by the stub in this file, so the dispatch
	 * path can be asserted without Action Scheduler being installed.
	 *
	 * @var array<int, array>
	 */
	public static $scheduled_actions = [];

	/**
	 * Lock keys the Action Scheduler stub in this file should throw for, so the
	 * dispatch-failure path can be exercised.
	 *
	 * @var array<int, string>
	 */
	public static $scheduling_fails_for = [];

	/**
	 * Reset the class's static state and this file's Action Scheduler recorder
	 * before each test, so queues, locks and index counters don't leak between tests.
	 */
	public function set_up() {
		parent::set_up();
		self::$scheduled_actions    = [];
		self::$scheduling_fails_for = [];
		$this->reset_caching_static_state();
	}

	/**
	 * Also reset after each test, in case a test leaves static state behind that
	 * could affect other test classes.
	 */
	public function tear_down() {
		$this->reset_caching_static_state();
		parent::tear_down();
	}

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
	 * Use reflection to reset Newspack_Blocks_Caching's private/protected
	 * static properties to their defaults.
	 */
	private function reset_caching_static_state() {
		$reflection = new ReflectionClass( 'Newspack_Blocks_Caching' );

		foreach ( [
			'regeneration_queue'  => [],
			'is_regenerating'     => false,
			'current_block_index' => 0,
			'content_changed_at'  => null,
			'can_serve_all_blocks_from_cache' => true,
		] as $property_name => $default ) {
			$property = $reflection->getProperty( $property_name );
			$property->setAccessible( true );
			$property->setValue( null, $default );
		}
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
	 * Helper to write a private/protected static property via reflection.
	 *
	 * @param string $property_name Property name.
	 * @param mixed  $value         Value to set.
	 */
	private function set_static_property( $property_name, $value ) {
		$reflection = new ReflectionClass( 'Newspack_Blocks_Caching' );
		$property   = $reflection->getProperty( $property_name );
		$property->setAccessible( true );
		$property->setValue( null, $value );
	}

	/**
	 * Whether a regeneration lock is currently held. Mirrors the class's own
	 * storage choice, which depends on whether a persistent object cache is in use.
	 *
	 * @param string $lock_key    Lock key.
	 * @param string $cache_group Cache group.
	 * @return bool True if the lock is held.
	 */
	private function lock_is_held( $lock_key, $cache_group ) {
		if ( wp_using_ext_object_cache() ) {
			return (bool) wp_cache_get( $lock_key, $cache_group );
		}
		return (bool) get_transient( $cache_group . '_' . $lock_key );
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
	 * Make REGENERATION_TEST_BLOCK cacheable and return parsed block data for it.
	 *
	 * @param string $text Paragraph text to render.
	 * @return array Parsed block data.
	 */
	private function get_renderable_block_data( $text = 'regenerated' ) {
		add_filter(
			'newspack_blocks_cacheable_blocks',
			function ( $blocks ) {
				$blocks[] = self::REGENERATION_TEST_BLOCK;
				return $blocks;
			}
		);

		$html = '<p>' . $text . '</p>';
		return [
			'blockName'    => self::REGENERATION_TEST_BLOCK,
			'attrs'        => [],
			'innerBlocks'  => [],
			'innerHTML'    => $html,
			'innerContent' => [ $html ],
		];
	}

	/**
	 * Build a regeneration job of the shape queue_regeneration() produces.
	 *
	 * @param array  $block_data Parsed block data.
	 * @param array  $cache_keys Cache keys the job should refresh.
	 * @param string $lock_key   Lock key, which the job is expected to release.
	 * @return array Job array.
	 */
	private function make_job( $block_data, $cache_keys, $lock_key ) {
		return [
			'block_data'  => $block_data,
			'cache_group' => self::CACHE_GROUP_FOR_TEST,
			'cache_keys'  => $cache_keys,
			'lock_key'    => $lock_key,
			'post_id'     => 0,
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
	 * Seed a cache entry with a specific age, and reset the per-request block
	 * index so the next get_cache_key() call produces the same key again.
	 *
	 * @param array  $block_data  Parsed block data.
	 * @param int    $age_seconds How long ago the entry was generated.
	 * @param string $content     Cached markup.
	 * @return array{0: string, 1: string} The cache key and cache group used.
	 */
	private function seed_cache_entry( $block_data, $age_seconds, $content ) {
		$cache_key   = $this->invoke_static_method( 'get_cache_key', [ $block_data ] );
		$cache_group = $this->invoke_static_method( 'get_cache_group' );

		wp_cache_set(
			$cache_key,
			[
				'timestamp_generated' => time() - $age_seconds,
				'cached_content'      => $content,
			],
			$cache_group,
			NEWSPACK_BLOCKS_CACHE_HARD_TTL
		);

		// get_cache_key() advanced the index; rewind it so the code under test
		// derives the same key for this block instance.
		$this->set_static_property( 'current_block_index', 0 );

		return [ $cache_key, $cache_group ];
	}

	/**
	 * A cache entry older than the soft TTL but within the hard TTL should
	 * still be servable (stale), and should queue a background regeneration job.
	 */
	public function test_stale_cache_hit_is_served_and_queues_regeneration() {
		$this->go_to_singular_post();

		$block_data                 = $this->get_cacheable_block_data( [ 'stale-test' => true ] );
		list( $cache_key, $group )  = $this->seed_cache_entry( $block_data, NEWSPACK_BLOCKS_CACHE_BLOCKS_TIME + 5, '<div>stale content</div>' );

		$result = Newspack_Blocks_Caching::maybe_serve_cached_block( null, $block_data );

		$this->assertSame( '<div>stale content</div>', $result, 'Stale-but-valid cached content should still be served.' );

		$queue = $this->get_static_property( 'regeneration_queue' );
		$this->assertCount( 1, $queue, 'A regeneration job should have been queued for the stale block.' );

		$job = reset( $queue );
		$this->assertSame( $group, $job['cache_group'] );
		$this->assertContains( $cache_key, $job['cache_keys'] );
		$this->assertTrue( $this->lock_is_held( $job['lock_key'], $group ), 'Queueing a job should take the regeneration lock.' );
	}

	/**
	 * A cache entry older than the hard TTL should be discarded entirely,
	 * not served stale.
	 */
	public function test_cache_entry_past_hard_ttl_is_discarded() {
		$this->go_to_singular_post();

		$block_data = $this->get_cacheable_block_data( [ 'hard-ttl-test' => true ] );
		$this->seed_cache_entry( $block_data, NEWSPACK_BLOCKS_CACHE_HARD_TTL + 5, '<div>ancient content</div>' );

		$result = Newspack_Blocks_Caching::maybe_serve_cached_block( null, $block_data );

		$this->assertNull( $result, 'A cache entry past the hard TTL should not be served, forcing a synchronous render.' );
		$this->assertEmpty( $this->get_static_property( 'regeneration_queue' ), 'No regeneration job should be queued for a discarded (hard-expired) entry.' );
	}

	/**
	 * With stale-while-revalidate switched off, the layer must behave the way it
	 * did before: an entry past the soft TTL is a miss, and nothing is queued.
	 */
	public function test_swr_filter_restores_synchronous_expiry() {
		$this->go_to_singular_post();
		add_filter( 'newspack_blocks_cache_use_swr', '__return_false' );

		$block_data = $this->get_cacheable_block_data( [ 'swr-off-test' => true ] );
		$this->seed_cache_entry( $block_data, NEWSPACK_BLOCKS_CACHE_BLOCKS_TIME + 5, '<div>stale content</div>' );

		$result = Newspack_Blocks_Caching::maybe_serve_cached_block( null, $block_data );

		$this->assertNull( $result, 'With SWR disabled, an entry past the soft TTL must not be served.' );
		$this->assertEmpty( $this->get_static_property( 'regeneration_queue' ), 'With SWR disabled, no regeneration job should be queued.' );
		$this->assertSame(
			NEWSPACK_BLOCKS_CACHE_BLOCKS_TIME,
			$this->invoke_static_method( 'get_cache_expiry' ),
			'With SWR disabled, entries should be stored with the soft TTL again.'
		);

		remove_filter( 'newspack_blocks_cache_use_swr', '__return_false' );
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

		// Two instances of the same block config get distinct per-instance keys.
		$cache_key_1 = $this->invoke_static_method( 'get_cache_key', [ $block_data_1 ] );
		$cache_key_2 = $this->invoke_static_method( 'get_cache_key', [ $block_data_2 ] );
		$this->assertNotSame( $cache_key_1, $cache_key_2, 'Duplicate block instances should get distinct per-instance cache keys.' );

		$cached_data = [
			'timestamp_generated' => time() - NEWSPACK_BLOCKS_CACHE_BLOCKS_TIME - 5,
			'cached_content'      => '<div>stale duplicate content</div>',
		];
		wp_cache_set( $cache_key_1, $cached_data, $cache_group, NEWSPACK_BLOCKS_CACHE_HARD_TTL );
		wp_cache_set( $cache_key_2, $cached_data, $cache_group, NEWSPACK_BLOCKS_CACHE_HARD_TTL );

		$this->set_static_property( 'current_block_index', 0 );

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
	 * The regeneration lock is what stops concurrent requests from all rendering
	 * the same stale block. Once one request holds it, a later request finding the
	 * same entry stale must not queue a second job.
	 */
	public function test_held_lock_prevents_a_second_request_from_queueing() {
		$this->go_to_singular_post();

		$block_data = $this->get_cacheable_block_data( [ 'lock-test' => true ] );
		$this->seed_cache_entry( $block_data, NEWSPACK_BLOCKS_CACHE_BLOCKS_TIME + 5, '<div>stale content</div>' );

		Newspack_Blocks_Caching::maybe_serve_cached_block( null, $block_data );
		$this->assertCount( 1, $this->get_static_property( 'regeneration_queue' ), 'The first request should queue a job.' );

		// Simulate a second, concurrent request: fresh per-request state, but the
		// lock taken by the first request is still held.
		$this->set_static_property( 'regeneration_queue', [] );
		$this->set_static_property( 'current_block_index', 0 );

		Newspack_Blocks_Caching::maybe_serve_cached_block( null, $block_data );

		$this->assertEmpty(
			$this->get_static_property( 'regeneration_queue' ),
			'A second request must not queue a duplicate job while the lock is held.'
		);
	}

	/**
	 * Age alone can't tell a correct entry from a wrong one. A publish must make a
	 * still-young entry stale, so it is refreshed in the background instead of
	 * being served unchanged until the soft TTL happens to expire.
	 */
	public function test_publishing_makes_a_young_entry_stale() {
		$this->go_to_singular_post();

		$block_data = $this->get_cacheable_block_data( [ 'publish-test' => true ] );
		// Well inside the soft TTL: without the content marker this entry would not
		// be stale at all.
		$this->seed_cache_entry( $block_data, 5, '<div>content from before the publish</div>' );

		Newspack_Blocks_Caching::record_content_change( 'publish', 'draft' );

		$served = Newspack_Blocks_Caching::maybe_serve_cached_block( null, $block_data );

		$this->assertSame( '<div>content from before the publish</div>', $served, 'The entry is still served, immediately.' );
		$this->assertCount(
			1,
			$this->get_static_property( 'regeneration_queue' ),
			'A publish should queue a background regeneration for an otherwise fresh entry.'
		);
	}

	/**
	 * The grace window bounds how long a wrong entry may be served. Once it has
	 * passed without anything refreshing the entry, the request must fall back to
	 * rendering synchronously rather than serving content known to be out of date.
	 */
	public function test_outdated_entry_past_the_grace_window_is_discarded() {
		$this->go_to_singular_post();

		$block_data = $this->get_cacheable_block_data( [ 'grace-test' => true ] );

		// Rendered before the change, and the change is older than the grace window.
		list( $cache_key, $cache_group ) = $this->seed_cache_entry( $block_data, NEWSPACK_BLOCKS_CACHE_STALE_GRACE + 60, '<div>out of date</div>' );

		$this->set_static_property( 'content_changed_at', time() - ( NEWSPACK_BLOCKS_CACHE_STALE_GRACE + 30 ) );

		$this->assertFalse(
			Newspack_Blocks_Caching::get_cached_block_data( $block_data ),
			'An entry whose content changed before the grace window must not be served.'
		);
		$this->assertFalse(
			wp_cache_get( $cache_key, $cache_group ),
			'The unusable entry should be dropped from the cache.'
		);
	}

	/**
	 * Falling back to a synchronous render while a background regeneration is
	 * already claimed for the same block would duplicate exactly the work this
	 * layer exists to avoid. The grace window must not override a held lock.
	 */
	public function test_outdated_entry_is_still_served_while_a_regeneration_is_in_flight() {
		$this->go_to_singular_post();

		$block_data = $this->get_cacheable_block_data( [ 'in-flight-test' => true ] );

		list( $cache_key, $cache_group ) = $this->seed_cache_entry( $block_data, NEWSPACK_BLOCKS_CACHE_STALE_GRACE + 60, '<div>out of date</div>' );

		$this->set_static_property( 'content_changed_at', time() - ( NEWSPACK_BLOCKS_CACHE_STALE_GRACE + 30 ) );
		$this->invoke_static_method(
			'acquire_regeneration_lock',
			[ $this->invoke_static_method( 'get_regeneration_lock_key', [ $cache_key, $cache_group ] ), $cache_group ]
		);

		$cached = Newspack_Blocks_Caching::get_cached_block_data( $block_data );

		$this->assertIsArray( $cached, 'The entry must keep being served while its regeneration runs.' );
		$this->assertSame( '<div>out of date</div>', $cached['cached_content'] );
		$this->assertIsArray(
			wp_cache_get( $cache_key, $cache_group ),
			'The entry must not be dropped out from under the running regeneration.'
		);
	}

	/**
	 * The flip side: on a site where nothing has been published, an old entry is
	 * still exactly right. It must keep being served — falling back to synchronous
	 * renders on a quiet site is the behavior this whole layer exists to avoid.
	 */
	public function test_old_entry_is_still_served_when_no_content_changed() {
		$this->go_to_singular_post();

		$block_data = $this->get_cacheable_block_data( [ 'quiet-site-test' => true ] );
		$this->seed_cache_entry( $block_data, NEWSPACK_BLOCKS_CACHE_BLOCKS_TIME * 50, '<div>still correct</div>' );

		$served = Newspack_Blocks_Caching::maybe_serve_cached_block( null, $block_data );

		$this->assertSame( '<div>still correct</div>', $served, 'With no content change recorded, an old entry stays servable.' );
		$this->assertCount( 1, $this->get_static_property( 'regeneration_queue' ), 'It is still refreshed in the background.' );
	}

	/**
	 * Only transitions into or out of 'publish' change what these blocks query.
	 * Saving a draft or trimming a revision must not invalidate anything.
	 */
	public function test_unpublished_changes_do_not_move_the_marker() {
		Newspack_Blocks_Caching::record_content_change( 'draft', 'auto-draft' );
		$this->assertSame(
			0,
			$this->invoke_static_method( 'get_content_changed_at', [] ),
			'A draft transition must not record a content change.'
		);

		$revision_id = self::factory()->post->create( [ 'post_status' => 'inherit' ] );
		Newspack_Blocks_Caching::record_content_deletion( $revision_id, get_post( $revision_id ) );
		$this->assertSame(
			0,
			$this->invoke_static_method( 'get_content_changed_at', [] ),
			'Deleting unpublished content must not record a content change.'
		);

		$published_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		Newspack_Blocks_Caching::record_content_deletion( $published_id, get_post( $published_id ) );
		$this->assertGreaterThan(
			0,
			$this->invoke_static_method( 'get_content_changed_at', [] ),
			'Deleting published content must record a content change.'
		);
	}

	/**
	 * On the inline fallback path the response is detached but the PHP worker is
	 * not. A page whose blocks all go stale at once — what a publish does — must
	 * not occupy that worker for the sum of every cold render; the jobs over the
	 * limit release their locks for a later request to pick up.
	 *
	 * Exercises the inline loop directly: reaching it through regenerate_stale_blocks()
	 * would need fastcgi_finish_request(), which the CLI SAPI running these tests
	 * does not have.
	 */
	public function test_inline_regeneration_is_capped_per_request() {
		$cap = function () {
			return 1;
		};
		add_filter( 'newspack_blocks_cache_max_inline_regenerations', $cap );

		$block_data = $this->get_renderable_block_data( 'inline regenerated' );
		$queue      = [];
		$lock_keys  = [];
		foreach ( [ 'first', 'second' ] as $name ) {
			$lock_key           = 'lock_' . self::CACHE_GROUP_FOR_TEST . '_np_cached_block_' . $name;
			$lock_keys[ $name ] = $lock_key;
			$this->invoke_static_method( 'acquire_regeneration_lock', [ $lock_key, self::CACHE_GROUP_FOR_TEST ] );
			$queue[ $name ] = $this->make_job( $block_data, [ 'np_cached_block_' . $name . '_0' ], $lock_key );
		}

		$this->invoke_static_method( 'regenerate_queue_inline', [ $queue ] );

		$this->assertIsArray(
			wp_cache_get( 'np_cached_block_first_0', self::CACHE_GROUP_FOR_TEST ),
			'The job within the limit should have been regenerated.'
		);
		$this->assertFalse(
			wp_cache_get( 'np_cached_block_second_0', self::CACHE_GROUP_FOR_TEST ),
			'The job over the limit must not run.'
		);
		$this->assertFalse(
			$this->lock_is_held( $lock_keys['second'], self::CACHE_GROUP_FOR_TEST ),
			'A skipped job must release its lock so a later request can pick it up.'
		);

		remove_filter( 'newspack_blocks_cache_max_inline_regenerations', $cap );
	}

	/**
	 * With Action Scheduler available, the shutdown handler must hand the job off
	 * rather than rendering inline, and must leave the lock in place for the
	 * scheduled job to release.
	 */
	public function test_queue_is_dispatched_to_action_scheduler() {
		$block_data = $this->get_renderable_block_data();
		$lock_key   = 'lock_' . self::CACHE_GROUP_FOR_TEST . '_np_cached_block_as';
		$job        = $this->make_job( $block_data, [ 'np_cached_block_as_0' ], $lock_key );

		$this->invoke_static_method( 'acquire_regeneration_lock', [ $lock_key, self::CACHE_GROUP_FOR_TEST ] );
		$this->set_static_property( 'regeneration_queue', [ 'dedup' => $job ] );

		Newspack_Blocks_Caching::regenerate_stale_blocks();

		$this->assertCount( 1, self::$scheduled_actions, 'The job should have been handed to Action Scheduler.' );
		$this->assertSame( Newspack_Blocks_Caching::REGENERATION_AS_HOOK, self::$scheduled_actions[0]['hook'] );
		$this->assertSame( Newspack_Blocks_Caching::REGENERATION_AS_GROUP, self::$scheduled_actions[0]['group'] );
		$this->assertSame( $job, self::$scheduled_actions[0]['args'][0], 'The full job should be passed to the scheduled action.' );

		$this->assertFalse( wp_cache_get( 'np_cached_block_as_0', self::CACHE_GROUP_FOR_TEST ), 'Dispatching must not render or write the cache inline.' );
		$this->assertTrue( $this->lock_is_held( $lock_key, self::CACHE_GROUP_FOR_TEST ), 'The lock must stay held until the scheduled job runs.' );
		$this->assertEmpty( $this->get_static_property( 'regeneration_queue' ), 'The queue should be cleared once dispatched.' );
	}

	/**
	 * Without Action Scheduler and without a way to detach the response from the
	 * PHP process, regenerating inline would hold the visitor's connection open for
	 * the length of a cold render. Nothing should be regenerated in that case, and
	 * the locks must be released so a later request can try again.
	 */
	public function test_no_background_mechanism_skips_regeneration_and_releases_locks() {
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			$this->markTestSkipped( 'fastcgi_finish_request() exists in this SAPI, so the inline path is available.' );
		}

		add_filter( 'newspack_blocks_cache_use_action_scheduler', '__return_false' );

		$block_data = $this->get_renderable_block_data();
		$lock_key   = 'lock_' . self::CACHE_GROUP_FOR_TEST . '_np_cached_block_nobg';
		$job        = $this->make_job( $block_data, [ 'np_cached_block_nobg_0' ], $lock_key );

		$this->invoke_static_method( 'acquire_regeneration_lock', [ $lock_key, self::CACHE_GROUP_FOR_TEST ] );
		$this->set_static_property( 'regeneration_queue', [ 'dedup' => $job ] );

		Newspack_Blocks_Caching::regenerate_stale_blocks();

		$this->assertEmpty( self::$scheduled_actions, 'Nothing should be scheduled when Action Scheduler is disabled.' );
		$this->assertFalse( wp_cache_get( 'np_cached_block_nobg_0', self::CACHE_GROUP_FOR_TEST ), 'Nothing should be regenerated without a background mechanism.' );
		$this->assertFalse( $this->lock_is_held( $lock_key, self::CACHE_GROUP_FOR_TEST ), 'Locks must be released when the job is abandoned.' );

		remove_filter( 'newspack_blocks_cache_use_action_scheduler', '__return_false' );
	}

	/**
	 * The scheduled job renders the block afresh and writes the result to every
	 * cache key it covers, then releases its lock.
	 */
	public function test_regeneration_job_refreshes_every_cache_key() {
		$block_data = $this->get_renderable_block_data( 'fresh markup' );
		$lock_key   = 'lock_' . self::CACHE_GROUP_FOR_TEST . '_np_cached_block_multi';
		$cache_keys = [ 'np_cached_block_multi_0', 'np_cached_block_multi_1' ];

		foreach ( $cache_keys as $cache_key ) {
			wp_cache_set(
				$cache_key,
				[
					'timestamp_generated' => time() - 1000,
					'cached_content'      => '<p>stale markup</p>',
				],
				self::CACHE_GROUP_FOR_TEST,
				NEWSPACK_BLOCKS_CACHE_HARD_TTL
			);
		}
		$this->invoke_static_method( 'acquire_regeneration_lock', [ $lock_key, self::CACHE_GROUP_FOR_TEST ] );

		Newspack_Blocks_Caching::handle_regeneration_job( $this->make_job( $block_data, $cache_keys, $lock_key ) );

		foreach ( $cache_keys as $cache_key ) {
			$cached = wp_cache_get( $cache_key, self::CACHE_GROUP_FOR_TEST );
			$this->assertIsArray( $cached );
			$this->assertStringContainsString( 'fresh markup', $cached['cached_content'], 'Every cache key on the job should hold the regenerated markup.' );
			$this->assertGreaterThan( time() - 10, $cached['timestamp_generated'], 'The refreshed entry should carry a current timestamp.' );
		}

		$this->assertFalse( $this->lock_is_held( $lock_key, self::CACHE_GROUP_FOR_TEST ), 'The lock should be released once the job completes.' );
		$this->assertFalse( $this->get_static_property( 'is_regenerating' ), 'The is_regenerating flag should be reset after the job.' );
	}

	/**
	 * Enqueueing can fail — Action Scheduler rejects an over-long payload and its
	 * store can throw on a database error. A failure must be contained to the job
	 * it happened on: its lock is released so a later request can retry, and the
	 * rest of the queue is still dispatched.
	 */
	public function test_failed_dispatch_releases_its_lock_and_keeps_going() {
		$block_data   = $this->get_renderable_block_data();
		$failing_lock = 'lock_' . self::CACHE_GROUP_FOR_TEST . '_np_cached_block_toobig';
		$working_lock = 'lock_' . self::CACHE_GROUP_FOR_TEST . '_np_cached_block_ok';
		$failing_job  = $this->make_job( $block_data, [ 'np_cached_block_toobig_0' ], $failing_lock );
		$working_job  = $this->make_job( $block_data, [ 'np_cached_block_ok_0' ], $working_lock );

		self::$scheduling_fails_for = [ $failing_lock ];

		$this->invoke_static_method( 'acquire_regeneration_lock', [ $failing_lock, self::CACHE_GROUP_FOR_TEST ] );
		$this->invoke_static_method( 'acquire_regeneration_lock', [ $working_lock, self::CACHE_GROUP_FOR_TEST ] );
		$this->set_static_property(
			'regeneration_queue',
			[
				'dedup_failing' => $failing_job,
				'dedup_working' => $working_job,
			]
		);

		Newspack_Blocks_Caching::regenerate_stale_blocks();

		$this->assertCount( 1, self::$scheduled_actions, 'The job that did not throw should still have been scheduled.' );
		$this->assertSame( $working_lock, self::$scheduled_actions[0]['args'][0]['lock_key'] );

		$this->assertFalse(
			$this->lock_is_held( $failing_lock, self::CACHE_GROUP_FOR_TEST ),
			'A job that could not be scheduled must release its lock, so the next request can retry.'
		);
		$this->assertTrue(
			$this->lock_is_held( $working_lock, self::CACHE_GROUP_FOR_TEST ),
			'The lock of a successfully scheduled job stays held until that job runs.'
		);
	}

	/**
	 * The regeneration worker is registered outside the front-end gate, so it also
	 * runs in requests made by logged-in editors (WP-Cron spawned from the admin,
	 * for instance). Everything it needs — in particular the cache TTLs — has to be
	 * available there.
	 */
	public function test_background_job_runs_when_an_editor_is_logged_in() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		Newspack_Blocks_Caching::setup_block_caching();

		$this->assertGreaterThan(
			0,
			$this->invoke_static_method( 'get_cache_expiry', [] ),
			'The worker must be able to determine a cache expiry regardless of who is logged in.'
		);

		$block_data = $this->get_renderable_block_data( 'refreshed for editor' );
		$lock_key   = 'lock_' . self::CACHE_GROUP_FOR_TEST . '_np_cached_block_editor';
		$cache_key  = 'np_cached_block_editor_0';

		$this->invoke_static_method( 'acquire_regeneration_lock', [ $lock_key, self::CACHE_GROUP_FOR_TEST ] );
		Newspack_Blocks_Caching::handle_regeneration_job( $this->make_job( $block_data, [ $cache_key ], $lock_key ) );

		$cached = wp_cache_get( $cache_key, self::CACHE_GROUP_FOR_TEST );
		$this->assertIsArray( $cached );
		$this->assertStringContainsString( 'refreshed for editor', $cached['cached_content'] );
	}

	/**
	 * A scheduled job outlives the request that queued it, so it can arrive from a
	 * plugin version that built a different payload. A job missing a field the
	 * worker relies on must be dropped, not half-processed.
	 */
	public function test_malformed_scheduled_job_is_dropped() {
		$block_data = $this->get_renderable_block_data( 'should not be written' );
		$cache_key  = 'np_cached_block_malformed_0';

		wp_cache_set(
			$cache_key,
			[
				'timestamp_generated' => time() - 1000,
				'cached_content'      => '<p>untouched</p>',
			],
			self::CACHE_GROUP_FOR_TEST,
			NEWSPACK_BLOCKS_CACHE_HARD_TTL
		);

		$job = $this->make_job( $block_data, [ $cache_key ], 'lock_whatever' );

		foreach ( [ 'lock_key', 'cache_group', 'cache_keys', 'block_data' ] as $missing_field ) {
			$broken = $job;
			unset( $broken[ $missing_field ] );

			Newspack_Blocks_Caching::handle_regeneration_job( $broken );

			$cached = wp_cache_get( $cache_key, self::CACHE_GROUP_FOR_TEST );
			$this->assertSame(
				'<p>untouched</p>',
				$cached['cached_content'],
				sprintf( 'A job missing %s must be dropped without touching the cache.', $missing_field )
			);
		}

		Newspack_Blocks_Caching::handle_regeneration_job( 'not an array at all' );
		$this->assertSame( '<p>untouched</p>', wp_cache_get( $cache_key, self::CACHE_GROUP_FOR_TEST )['cached_content'] );

		// Present but the wrong shape: render_block() needs parsed block data and the
		// cache write iterates the keys, so these have to be dropped as well.
		foreach ( [ 'block_data', 'cache_keys' ] as $wrong_shape_field ) {
			$broken                       = $job;
			$broken[ $wrong_shape_field ] = 'not an array';

			Newspack_Blocks_Caching::handle_regeneration_job( $broken );

			$this->assertSame(
				'<p>untouched</p>',
				wp_cache_get( $cache_key, self::CACHE_GROUP_FOR_TEST )['cached_content'],
				sprintf( 'A job whose %s is not an array must be dropped without touching the cache.', $wrong_shape_field )
			);
		}
	}

	/**
	 * If the render throws, the failure must not propagate, the existing stale
	 * entry must survive so it stays servable, and the lock must be released
	 * immediately rather than blocking retries for its full TTL.
	 */
	public function test_regeneration_job_survives_a_failing_render() {
		$block_data = $this->get_renderable_block_data();
		$lock_key   = 'lock_' . self::CACHE_GROUP_FOR_TEST . '_np_cached_block_fail';
		$cache_key  = 'np_cached_block_fail_0';
		$existing   = [
			'timestamp_generated' => time() - 1000,
			'cached_content'      => '<p>existing stale content that should survive</p>',
		];

		wp_cache_set( $cache_key, $existing, self::CACHE_GROUP_FOR_TEST, NEWSPACK_BLOCKS_CACHE_HARD_TTL );
		$this->invoke_static_method( 'acquire_regeneration_lock', [ $lock_key, self::CACHE_GROUP_FOR_TEST ] );

		$throwing_filter = function () {
			throw new \RuntimeException( 'Simulated render failure for testing.' );
		};
		add_filter( 'pre_render_block', $throwing_filter, 20 );

		try {
			Newspack_Blocks_Caching::handle_regeneration_job( $this->make_job( $block_data, [ $cache_key ], $lock_key ) );
		} catch ( \Throwable $error ) {
			$this->fail( 'A failing render must not propagate out of the regeneration job: ' . $error->getMessage() );
		} finally {
			remove_filter( 'pre_render_block', $throwing_filter, 20 );
		}

		$this->assertSame( $existing, wp_cache_get( $cache_key, self::CACHE_GROUP_FOR_TEST ), 'The existing stale entry must be left untouched.' );
		$this->assertFalse( $this->lock_is_held( $lock_key, self::CACHE_GROUP_FOR_TEST ), 'The lock should be released immediately on failure.' );
		$this->assertFalse( $this->get_static_property( 'is_regenerating' ), 'The is_regenerating flag should be reset after a failure.' );
	}

	/**
	 * A render that produces nothing must not replace content that is still
	 * servable — an empty entry would be treated as a cache miss and put every
	 * subsequent request back on the synchronous path.
	 */
	public function test_empty_regeneration_does_not_replace_servable_content() {
		$block_data = $this->get_renderable_block_data();
		$lock_key   = 'lock_' . self::CACHE_GROUP_FOR_TEST . '_np_cached_block_empty';
		$cache_key  = 'np_cached_block_empty_0';
		$existing   = [
			'timestamp_generated' => time() - 1000,
			'cached_content'      => '<p>still servable</p>',
		];

		wp_cache_set( $cache_key, $existing, self::CACHE_GROUP_FOR_TEST, NEWSPACK_BLOCKS_CACHE_HARD_TTL );
		$this->invoke_static_method( 'acquire_regeneration_lock', [ $lock_key, self::CACHE_GROUP_FOR_TEST ] );

		$empty_filter = function () {
			return '';
		};
		add_filter( 'pre_render_block', $empty_filter, 20 );

		Newspack_Blocks_Caching::handle_regeneration_job( $this->make_job( $block_data, [ $cache_key ], $lock_key ) );

		remove_filter( 'pre_render_block', $empty_filter, 20 );

		$this->assertSame( $existing, wp_cache_get( $cache_key, self::CACHE_GROUP_FOR_TEST ), 'An empty render must not overwrite servable content.' );
		$this->assertFalse( $this->lock_is_held( $lock_key, self::CACHE_GROUP_FOR_TEST ), 'The lock should be released after an empty render.' );
	}

	/**
	 * The regeneration render must not be short-circuited by the very cache entry
	 * it is replacing, and must not be re-cached by the normal caching path.
	 */
	public function test_serve_and_cache_filters_stand_down_during_regeneration() {
		$block_data = $this->get_cacheable_block_data( [ 'reentrance-test' => true ] );
		$this->set_static_property( 'is_regenerating', true );

		$this->assertSame(
			'passthrough',
			Newspack_Blocks_Caching::maybe_serve_cached_block( 'passthrough', $block_data ),
			'maybe_serve_cached_block() must not serve cached content during a regeneration render.'
		);

		$index_before = $this->get_static_property( 'current_block_index' );
		$this->assertSame(
			'<div>fresh</div>',
			Newspack_Blocks_Caching::maybe_cache_block( '<div>fresh</div>', $block_data ),
			'maybe_cache_block() must pass the markup through untouched during a regeneration render.'
		);
		$this->assertSame(
			$index_before,
			$this->get_static_property( 'current_block_index' ),
			'The per-request block index must not advance during a regeneration render.'
		);
	}
}

if ( ! function_exists( 'as_enqueue_async_action' ) ) {
	/**
	 * Minimal Action Scheduler stub, so the dispatch path can be exercised without
	 * Action Scheduler being installed in the test environment. Records the call
	 * instead of scheduling anything.
	 *
	 * @param string $hook  Hook name.
	 * @param array  $args  Arguments for the hook.
	 * @param string $group Action group.
	 * @return int Fake action ID.
	 */
	function as_enqueue_async_action( $hook, $args = [], $group = '' ) {
		$lock_key = isset( $args[0]['lock_key'] ) ? $args[0]['lock_key'] : '';
		if ( in_array( $lock_key, CachingTest::$scheduling_fails_for, true ) ) {
			// Mirrors ActionScheduler_DBStore::validate_args(), which throws when a
			// payload is too large for the store, and the store's own database errors.
			throw new InvalidArgumentException( 'Action args are too long.' );
		}

		CachingTest::$scheduled_actions[] = [
			'hook'  => $hook,
			'args'  => $args,
			'group' => $group,
		];
		return count( CachingTest::$scheduled_actions );
	}
}
