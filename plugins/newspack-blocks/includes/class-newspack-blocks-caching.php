<?php
/**
 * Newspack block caching layer
 *
 * @package Newspack_Blocks
 */

/**
 * Cache dynamic blocks for improved performance.
 */
class Newspack_Blocks_Caching {

	const CACHE_GROUP = 'newspack_blocks';

	/**
	 * Action Scheduler hook used to regenerate a single stale block out of band.
	 */
	const REGENERATION_AS_HOOK = 'newspack_blocks_regenerate_stale_block';

	/**
	 * Action Scheduler group for regeneration jobs. Uses Newspack's 'newspack-'
	 * group prefix so the jobs show up alongside the rest of the product's
	 * scheduled work.
	 */
	const REGENERATION_AS_GROUP = 'newspack-blocks';

	/**
	 * Store the cache status for all blocks for this request.
	 *
	 * @var bool
	 */
	private static $can_serve_all_blocks_from_cache = true;

	/**
	 * Track visited reusable block IDs to spot recursion.
	 *
	 * @var array<int, bool>
	 */
	private static $visited_reusable_blocks = [];

	/**
	 * Store the current block index. This will be incremented with each cache reading,
	 * in order to add specificity to the cache key. The cache key consists of the
	 * hashed block attributes – which may be duplicated on a page – and a unique index.
	 * With index only, replacing the block would *not* invalidate cache, which is undesired.
	 * With hashed block attributes only, duplicated block configurations would result in
	 * duplication of rendered posts.
	 *
	 * @var int
	 */
	private static $current_block_index = 0;

	/**
	 * Queue of pending background regeneration jobs for this request, keyed by
	 * a dedup key derived from the block's identity (cache group + cache key
	 * with the per-request instance index stripped). Each job is an array:
	 * [ 'block_data' => array, 'cache_group' => string, 'cache_keys' => string[],
	 * 'lock_key' => string, 'post_id' => int ].
	 *
	 * @var array<string, array>
	 */
	private static $regeneration_queue = [];

	/**
	 * True only while a regeneration job is forcing a fresh render_block() call,
	 * so that pre_render_block doesn't short-circuit it with the stale content
	 * being replaced, and render_block doesn't re-cache under the normal
	 * (soft TTL) logic.
	 *
	 * @var bool
	 */
	private static $is_regenerating = false;

	/**
	 * Whether the misconfigured-TTL notice has already been logged this request.
	 *
	 * @var bool
	 */
	private static $logged_ttl_misconfiguration = false;

	/**
	 * Add hooks and filters.
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'setup_block_caching' ] );

		// Registered outside setup_block_caching(): an Action Scheduler job runs
		// through WP-Cron or WP-CLI, where the front-end caching hooks are not
		// necessarily wired up, but the queued job still has to be processed.
		add_action( self::REGENERATION_AS_HOOK, [ __CLASS__, 'handle_regeneration_job' ] );
		add_filter( 'newspack_action_scheduler_hook_labels', [ __CLASS__, 'register_hook_labels' ] );
	}

	/**
	 * Register a human-readable label for the regeneration action, for the
	 * Newspack plugin's Action Scheduler admin screens. Harmless no-op when
	 * the Newspack plugin isn't installed.
	 *
	 * @param array $labels Existing labels.
	 * @return array Labels including this plugin's regeneration hook.
	 */
	public static function register_hook_labels( $labels ) {
		$labels[ self::REGENERATION_AS_HOOK ] = __( 'Newspack Blocks cache regeneration', 'newspack-blocks' );
		return $labels;
	}

	/**
	 * Initialize block caching if needed.
	 */
	public static function setup_block_caching() {
		if ( ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) ) {
			add_action( 'template_redirect', [ __CLASS__, 'check_all_blocks_cache_status' ] );
			add_filter( 'pre_render_block', [ __CLASS__, 'maybe_serve_cached_block' ], 10, 2 );
			add_filter( 'render_block', [ __CLASS__, 'maybe_cache_block' ], 9999, 2 );
			add_action( 'shutdown', [ __CLASS__, 'regenerate_stale_blocks' ] );

			/**
			 * Cache duration in seconds for Newspack blocks (Homepage Posts, etc.).
			 * Blocks are cached for non-logged-in users to improve performance.
			 * Set to 0 to disable caching.
			 *
			 * @constant NEWSPACK_BLOCKS_CACHE_BLOCKS_TIME
			 * @type     int
			 * @default  120 (two minutes)
			 * @status   draft
			 *
			 * @example define( 'NEWSPACK_BLOCKS_CACHE_BLOCKS_TIME', 300 );
			 */
			if ( ! defined( 'NEWSPACK_BLOCKS_CACHE_BLOCKS_TIME' ) ) {
				define( 'NEWSPACK_BLOCKS_CACHE_BLOCKS_TIME', 120 );
			}

			/**
			 * Hard TTL in seconds for cached Newspack blocks. Once a cached entry is
			 * older than this, it is discarded entirely and rendered synchronously,
			 * instead of being served stale while a background regeneration runs.
			 *
			 * @constant NEWSPACK_BLOCKS_CACHE_HARD_TTL
			 * @type     int
			 * @default  DAY_IN_SECONDS (24 hours)
			 * @status   draft
			 *
			 * @example define( 'NEWSPACK_BLOCKS_CACHE_HARD_TTL', 12 * HOUR_IN_SECONDS );
			 */
			if ( ! defined( 'NEWSPACK_BLOCKS_CACHE_HARD_TTL' ) ) {
				define( 'NEWSPACK_BLOCKS_CACHE_HARD_TTL', DAY_IN_SECONDS );
			}

			/**
			 * TTL in seconds of the short-lived lock that keeps concurrent requests
			 * from duplicating background regeneration work for the same stale block.
			 * The lock is taken when the work is queued and released when it finishes.
			 *
			 * 150 seconds is sized against render time: cold renders of these blocks
			 * have been measured at 22-130 seconds in production-like conditions. That
			 * covers the inline path, where rendering starts as soon as the response is
			 * detached. It does not necessarily cover the Action Scheduler path, where
			 * the lock is held from the moment the job is queued and the job runs on a
			 * later WP-Cron pass — if the queue is backed up, or cron is disabled or
			 * driven by a slow system crontab, the lock can expire before the job ever
			 * runs, and the next request finding the block stale will queue a second
			 * job for it. The cost is duplicated work, not incorrect output: both jobs
			 * write the same freshly rendered markup. Raise this constant on sites
			 * where Action Scheduler regularly lags further behind than this.
			 *
			 * Every exit path from a regeneration job releases the lock explicitly —
			 * including a render that throws, and the case where no background
			 * mechanism is available at all — so, queue lag aside, a lock only
			 * survives to its TTL after an unrecoverable process death (e.g. an OOM
			 * kill).
			 *
			 * @constant NEWSPACK_BLOCKS_CACHE_REGEN_LOCK_TTL
			 * @type     int
			 * @default  150 (two and a half minutes)
			 * @status   draft
			 *
			 * @example define( 'NEWSPACK_BLOCKS_CACHE_REGEN_LOCK_TTL', 200 );
			 */
			if ( ! defined( 'NEWSPACK_BLOCKS_CACHE_REGEN_LOCK_TTL' ) ) {
				define( 'NEWSPACK_BLOCKS_CACHE_REGEN_LOCK_TTL', 150 );
			}
		}
	}

	/**
	 * Whether to serve stale cached blocks while regenerating them in the
	 * background, instead of dropping an expired entry and re-rendering
	 * synchronously in the visitor's request.
	 *
	 * @return bool True if stale-while-revalidate is active.
	 */
	protected static function is_swr_enabled() {
		if ( ! defined( 'NEWSPACK_BLOCKS_CACHE_HARD_TTL' ) || ! defined( 'NEWSPACK_BLOCKS_CACHE_BLOCKS_TIME' ) ) {
			return false;
		}

		// "Set to 0 to disable caching" is the documented contract of the soft TTL, and
		// it works because an entry is then always already older than its own TTL and is
		// dropped on read. Serving stale would quietly invert that: the entry would be
		// kept for the whole hard TTL and served — permanently stale — on every request.
		// Not a misconfiguration to log about, just a setting this layer must respect.
		if ( NEWSPACK_BLOCKS_CACHE_BLOCKS_TIME <= 0 ) {
			return false;
		}

		// A hard TTL below the soft TTL is a misconfiguration: it would mean entries
		// are discarded before they can ever be served stale. Fall back to the plain
		// single-TTL behavior rather than acting on contradictory settings.
		if ( NEWSPACK_BLOCKS_CACHE_HARD_TTL < NEWSPACK_BLOCKS_CACHE_BLOCKS_TIME ) {
			if ( ! self::$logged_ttl_misconfiguration && class_exists( 'Newspack\Logger' ) ) {
				self::$logged_ttl_misconfiguration = true;
				Newspack\Logger::log(
					sprintf(
						'NEWSPACK_BLOCKS_CACHE_HARD_TTL (%d) is lower than NEWSPACK_BLOCKS_CACHE_BLOCKS_TIME (%d); serving stale blocks is disabled.',
						NEWSPACK_BLOCKS_CACHE_HARD_TTL,
						NEWSPACK_BLOCKS_CACHE_BLOCKS_TIME
					)
				);
			}
			return false;
		}

		/**
		 * Filters whether cached blocks may be served stale while a fresh copy is
		 * regenerated in the background. Return false to restore the previous
		 * behavior, where an entry older than NEWSPACK_BLOCKS_CACHE_BLOCKS_TIME is
		 * discarded and re-rendered during the request that found it expired.
		 *
		 * @param bool $enabled Whether stale-while-revalidate is enabled. Default true.
		 */
		return (bool) apply_filters( 'newspack_blocks_cache_use_swr', true );
	}

	/**
	 * How long a cache entry should be kept in the object cache.
	 *
	 * With stale-while-revalidate active this is the hard TTL, because an entry
	 * past the soft TTL still has to be present in order to be served stale;
	 * staleness is judged on read in get_cached_block_data() instead.
	 *
	 * @return int Expiry in seconds.
	 */
	protected static function get_cache_expiry() {
		return self::is_swr_enabled() ? NEWSPACK_BLOCKS_CACHE_HARD_TTL : NEWSPACK_BLOCKS_CACHE_BLOCKS_TIME;
	}

	/**
	 * Whether background regeneration should be handed to Action Scheduler.
	 *
	 * Preferred over regenerating during 'shutdown': Action Scheduler persists the
	 * job, runs it in a separate request, and doesn't depend on the web server
	 * being able to detach the response from the PHP process.
	 *
	 * @return bool True if Action Scheduler should be used.
	 */
	protected static function use_action_scheduler() {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return false;
		}

		/**
		 * Filters whether stale block regeneration is dispatched via Action Scheduler.
		 * Only consulted when Action Scheduler is actually available, so the filter can
		 * opt out of it but never into a function that doesn't exist.
		 *
		 * @param bool $use Whether to use Action Scheduler. Default true.
		 */
		return (bool) apply_filters( 'newspack_blocks_cache_use_action_scheduler', true );
	}

	/**
	 * Traverse all blocks on a page to check their cache status.
	 * This has to happen before any blocks are rendered.
	 */
	public static function check_all_blocks_cache_status() {
		if ( is_singular() ) {
			$post = get_post();
			if ( $post && property_exists( $post, 'post_content' ) ) {
				self::$visited_reusable_blocks = [];
				self::check_block_cache_status( parse_blocks( $post->post_content ) );
				// Reset the index after initial checks.
				self::$current_block_index = 0;
			}
		}
	}

	/**
	 * Check if a block can be cached, recursively.
	 *
	 * @param array $blocks Array of block data.
	 */
	private static function check_block_cache_status( $blocks ) {
		$cacheable_block_names = self::get_cacheable_blocks_names();
		foreach ( $blocks as $block_data ) {
			// Special treatment for reusable blocks, which are blocks stored in the posts table.
			if ( $block_data['blockName'] === 'core/block' && ! empty( $block_data['attrs']['ref'] ) ) {
				$ref = $block_data['attrs']['ref'];
				// Skip blocks we've seen before to avoid infinite recursion.
				// Based on core's render_block_core_block().
				if ( isset( self::$visited_reusable_blocks[ $ref ] ) ) {
					continue;
				}
				self::$visited_reusable_blocks[ $ref ] = true;
				$reusable_block_post = get_post( $ref );
				if ( $reusable_block_post && property_exists( $reusable_block_post, 'post_content' ) ) {
					self::check_block_cache_status( parse_blocks( $reusable_block_post->post_content ) );
				}
				unset( self::$visited_reusable_blocks[ $ref ] );
			}
			if ( in_array( $block_data['blockName'], $cacheable_block_names, true ) ) {
				if ( ! self::get_cached_block_data( $block_data ) ) {
					self::$can_serve_all_blocks_from_cache = false;
				}
			}
			if ( ! empty( $block_data['innerBlocks'] ) ) {
				self::check_block_cache_status( $block_data['innerBlocks'] );
			}
		}
	}

	/**
	 * Get cacheable blocks' names.
	 */
	public static function get_cacheable_blocks_names() {
		$cacheable_blocks = [
			'newspack-blocks/homepage-articles',
			'newspack-blocks/carousel',
		];
		return apply_filters( 'newspack_blocks_cacheable_blocks', $cacheable_blocks );
	}

	/**
	 * Determine whether a block should be cached.
	 *
	 * @param array $block_data Parsed block data.
	 * @return bool True if block should be cached. False otherwise.
	 */
	protected static function should_cache_block( $block_data ) {
		return in_array( $block_data['blockName'], self::get_cacheable_blocks_names(), true );
	}

	/**
	 * Get the cache key for a block's cache.
	 *
	 * @param array $block_data Parsed block data.
	 * @return string Cache key.
	 */
	protected static function get_cache_key( $block_data ) {
		$block_attributes = $block_data['attrs'];
		$cache_key        = 'np_cached_block_' . md5( wp_json_encode( $block_attributes ) ) . '_' . self::$current_block_index;
		self::$current_block_index++;
		return $cache_key;
	}

	/**
	 * Get the cache group for cached data.
	 *
	 * We're using a heuristic here to increase the rate of cache hits with very limited downside.
	 * Pages should each have their own cache group, because they are likely a landing page with various article blocks.
	 * Posts and other publicly_queryable post types should all share a cache group, because 99% of the time article blocks
	 * are in the sidebar, below-content, or (if within content) fetching specific posts. We want an article block in
	 * the e.g. sidebar to be served from cache across all posts.
	 *
	 * The tradeoff is that occasionally the current post may show up in an article block on a post.
	 * Archives should all use a global cache group, because there is nothing that would need de-duplication.
	 * Feeds have their own cache group because some blocks have (e.g. content loop) have different markup in feeds vs. site frontend.
	 *
	 * @return string Cache group.
	 */
	protected static function get_cache_group() {
		if ( is_singular() || is_front_page() ) {
			$post_type        = get_post_type();
			$post_type_object = get_post_type_object( $post_type );
			if ( ! $post_type_object->publicly_queryable ) {
				return sprintf( self::CACHE_GROUP . '-post-%d', get_the_ID() );
			}
			return self::CACHE_GROUP;
		} elseif ( is_feed() ) {
			return self::CACHE_GROUP . '-feed';
		} else {
			return self::CACHE_GROUP;
		}
	}

	/**
	 * Debug logging for observing cache behavior.
	 *
	 * @param string $message Message to log.
	 */
	protected static function debug_log( $message ) {
		if ( defined( 'NEWSPACK_LOG_LEVEL' ) && (int) NEWSPACK_LOG_LEVEL >= 4 && class_exists( 'Newspack\Logger' ) ) {
			Newspack\Logger::log( $message );
		}
	}

	/**
	 * Is the block available in the cache?
	 *
	 * @param array $block_data Parsed block data.
	 */
	public static function get_cached_block_data( $block_data ) {
		if ( ! self::should_cache_block( $block_data ) ) {
			return false;
		}

		$cache_key   = self::get_cache_key( $block_data );
		$cache_group = self::get_cache_group();
		self::debug_log( sprintf( 'Checking cache for item %s in group %s', $cache_key, $cache_group ) );

		$cached_data = wp_cache_get( $cache_key, $cache_group );
		if ( ! is_array( $cached_data ) || ! isset( $cached_data['timestamp_generated'], $cached_data['cached_content'] ) || empty( $cached_data['cached_content'] ) ) {
			self::debug_log( sprintf( 'Cached data not found for item %s in group %s', $cache_key, $cache_group ) );
			return false;
		}

		// Double-check to make sure cached data is still valid. With stale-while-revalidate
		// active this is the hard TTL, past which an entry is too old to serve at all;
		// without it, the soft TTL, matching the previous single-TTL behavior.
		if ( $cached_data['timestamp_generated'] + self::get_cache_expiry() < time() ) {
			if ( class_exists( 'Newspack\Logger' ) ) {
				Newspack\Logger::log( sprintf( 'Flushing cache for item %s in group %s because it expired', $cache_key, $cache_group ) );
			}
			wp_cache_delete( $cache_key, $cache_group );
			return false;
		}

		// Past the soft TTL, the entry is stale: still servable, but a background regeneration should be queued.
		$cached_data['is_stale']    = self::is_swr_enabled()
			&& ( $cached_data['timestamp_generated'] + NEWSPACK_BLOCKS_CACHE_BLOCKS_TIME < time() );
		$cached_data['cache_key']   = $cache_key;
		$cached_data['cache_group'] = $cache_group;

		self::debug_log( sprintf( 'Found cached block: item %s in group %s', $cache_key, $cache_group ) );
		return $cached_data;
	}

	/**
	 * Serve a cached block if a valid one exists.
	 *
	 * @param string|null $block_html Block HTML. If you return something non-null here it will short-circuit block rendering.
	 * @param array       $block_data Parsed block data.
	 * @return string|null Block markup if served from cache. Default (usually null), otherwise.
	 */
	public static function maybe_serve_cached_block( $block_html, $block_data ) {
		// While forcing a fresh render for a queued regeneration job, never short-circuit with stale content.
		if ( self::$is_regenerating ) {
			return $block_html;
		}

		if ( ! self::should_cache_block( $block_data ) ) {
			return $block_html;
		}

		// Equeue the styles needed to render the blocks.
		newspack_blocks_enqueue_block_homepage_articles_styles();

		if ( ! self::$can_serve_all_blocks_from_cache ) {
			return $block_html;
		}
		$cached_data = self::get_cached_block_data( $block_data );
		if ( ! $cached_data ) {
			return $block_html;
		}

		if ( ! empty( $cached_data['is_stale'] ) ) {
			self::queue_regeneration( $block_data, $cached_data['cache_key'], $cached_data['cache_group'] );
		}

		if ( 'newspack-blocks/homepage-articles' === $block_data['blockName'] ) {
			Newspack_Blocks::enqueue_view_assets( 'homepage-articles', 'defer' );
		} elseif ( 'newspack-blocks/carousel' === $block_data['blockName'] ) {
			Newspack_Blocks::enqueue_view_assets( 'carousel', 'defer' );
		}

		return $cached_data['cached_content'];
	}

	/**
	 * Queue a background regeneration job for a stale cached block, deduping on
	 * the block's identity (not the raw, per-instance cache key) so that multiple
	 * occurrences of an identical block configuration on the same page only
	 * trigger one regeneration render, while still refreshing every instance's
	 * own cache entry once that render completes.
	 *
	 * @param array  $block_data  Parsed block data.
	 * @param string $cache_key   The specific cache key for this block instance.
	 * @param string $cache_group The cache group for this block instance.
	 */
	protected static function queue_regeneration( $block_data, $cache_key, $cache_group ) {
		// Blocks render to nothing in feeds, so a regeneration job would only ever
		// produce an empty entry. Nothing to warm here.
		if ( is_feed() ) {
			return;
		}

		// The raw cache key has an ever-incrementing per-request instance index appended,
		// so strip it to dedupe on the block's actual identity (attributes + cache group).
		$dedup_key = $cache_group . '_' . preg_replace( '/_\d+$/', '', $cache_key );

		if ( isset( self::$regeneration_queue[ $dedup_key ] ) ) {
			self::$regeneration_queue[ $dedup_key ]['cache_keys'][] = $cache_key;
			return;
		}

		$lock_key = 'lock_' . $dedup_key;
		if ( ! self::acquire_regeneration_lock( $lock_key, $cache_group ) ) {
			// Another request already holds the lock and is regenerating this block.
			return;
		}

		self::$regeneration_queue[ $dedup_key ] = [
			'block_data'  => $block_data,
			'cache_group' => $cache_group,
			'cache_keys'  => [ $cache_key ],
			'lock_key'    => $lock_key,
			// Recorded so an out-of-band job can re-establish the post context this
			// block was rendered in; see setup_regeneration_context().
			'post_id'     => is_singular() ? (int) get_the_ID() : 0,
		];
	}

	/**
	 * Claim the right to regenerate a given block, so that concurrent requests
	 * finding the same stale entry don't all render it.
	 *
	 * Behind a persistent object cache this is a real lock: wp_cache_add() is
	 * atomic across requests. Without one, wp_cache_add() is request-local and
	 * would let every concurrent request think it won, so this falls back to a
	 * transient. That fallback is best-effort, not atomic — two requests can read
	 * "unlocked" before either writes. It is left deliberately simple, because a
	 * site with no persistent object cache has no cross-request block cache
	 * either: wp_cache_set() doesn't outlive the request, so no entry is ever
	 * found stale by a *later* request, and the in-request queue already dedupes
	 * within a single render.
	 *
	 * @param string $lock_key    Lock cache key.
	 * @param string $cache_group Cache group the lock lives in.
	 * @return bool True if the lock was acquired by this request.
	 */
	protected static function acquire_regeneration_lock( $lock_key, $cache_group ) {
		if ( wp_using_ext_object_cache() ) {
			return (bool) wp_cache_add( $lock_key, 1, $cache_group, NEWSPACK_BLOCKS_CACHE_REGEN_LOCK_TTL ); // phpcs:ignore WordPressVIPMinimum.Performance.LowExpiryCacheTime.CacheTimeUndetermined
		}

		$transient_key = $cache_group . '_' . $lock_key;
		if ( get_transient( $transient_key ) ) {
			return false;
		}
		set_transient( $transient_key, 1, NEWSPACK_BLOCKS_CACHE_REGEN_LOCK_TTL );
		return true;
	}

	/**
	 * Release a regeneration lock, so the next request finding this block stale
	 * can queue it again without waiting out the lock's TTL.
	 *
	 * @param string $lock_key    Lock cache key.
	 * @param string $cache_group Cache group the lock lives in.
	 */
	protected static function release_regeneration_lock( $lock_key, $cache_group ) {
		if ( wp_using_ext_object_cache() ) {
			wp_cache_delete( $lock_key, $cache_group );
			return;
		}
		delete_transient( $cache_group . '_' . $lock_key );
	}

	/**
	 * Dispatch the regeneration jobs queued during this request. Runs on 'shutdown',
	 * so nothing here is on the critical path of the response.
	 *
	 * Action Scheduler is preferred: the job is persisted and runs in its own
	 * request, which keeps the rendering work off this PHP worker entirely. Without
	 * it, the work can only be done inline, and that is acceptable only when the
	 * response can be detached from the process first — otherwise a visitor's
	 * connection would be held open for the duration of a cold render (measured at
	 * 22-130 seconds). When neither is possible we regenerate nothing and release
	 * the locks; the stale entries stay servable and the entry is rendered
	 * synchronously once it passes the hard TTL, exactly as before this layer existed.
	 */
	public static function regenerate_stale_blocks() {
		if ( empty( self::$regeneration_queue ) ) {
			return;
		}

		$queue                    = self::$regeneration_queue;
		self::$regeneration_queue = [];

		if ( self::use_action_scheduler() ) {
			foreach ( $queue as $job ) {
				as_enqueue_async_action( self::REGENERATION_AS_HOOK, [ $job ], self::REGENERATION_AS_GROUP );
			}
			self::debug_log( sprintf( 'Scheduled %d stale block regeneration job(s).', count( $queue ) ) );
			return;
		}

		if ( ! function_exists( 'fastcgi_finish_request' ) ) {
			foreach ( $queue as $job ) {
				self::release_regeneration_lock( $job['lock_key'], $job['cache_group'] );
			}
			self::debug_log( 'No background mechanism available; skipped regenerating stale blocks.' );
			return;
		}

		fastcgi_finish_request();

		foreach ( $queue as $job ) {
			self::regenerate_job( $job );
		}
	}

	/**
	 * Action Scheduler callback: regenerate a single stale block.
	 *
	 * @param array $job Queued job, as built by queue_regeneration().
	 */
	public static function handle_regeneration_job( $job ) {
		// A scheduled job outlives the request that queued it, so it can arrive from a
		// plugin version that built a different payload. Anything missing a field the
		// worker relies on is dropped rather than half-processed — in particular a job
		// without a lock key could never release the lock it was queued under.
		foreach ( [ 'block_data', 'cache_keys', 'cache_group', 'lock_key' ] as $required ) {
			if ( ! is_array( $job ) || empty( $job[ $required ] ) ) {
				return;
			}
		}
		self::regenerate_job( $job );
	}

	/**
	 * Render a queued block afresh and write it to every cache key the job covers.
	 *
	 * The lock is released on every exit path, and the existing (stale) cache
	 * entries are left untouched unless a usable render was produced — a failed or
	 * empty regeneration must never replace content that is still servable.
	 *
	 * @param array $job Queued job, as built by queue_regeneration().
	 */
	protected static function regenerate_job( $job ) {
		$cache_group = $job['cache_group'];
		$lock_key    = $job['lock_key'];
		$restore     = self::setup_regeneration_context( $job );

		self::$is_regenerating = true;
		try {
			$fresh_html = render_block( $job['block_data'] );
		} catch ( \Throwable $error ) {
			if ( class_exists( 'Newspack\Logger' ) ) {
				Newspack\Logger::log(
					sprintf(
						'Failed to regenerate stale block %s in group %s: %s',
						$lock_key,
						$cache_group,
						$error->getMessage()
					)
				);
			}
			$fresh_html = '';
		} finally {
			self::$is_regenerating = false;
			$restore();
		}

		if ( '' === trim( (string) $fresh_html ) ) {
			self::release_regeneration_lock( $lock_key, $cache_group );
			return;
		}

		$cache_data = [
			'timestamp_generated' => time(),
			'cached_content'      => $fresh_html,
		];
		foreach ( $job['cache_keys'] as $cache_key ) {
			wp_cache_set( $cache_key, $cache_data, $cache_group, self::get_cache_expiry() ); // phpcs:ignore WordPressVIPMinimum.Performance.LowExpiryCacheTime.CacheTimeUndetermined
		}
		self::release_regeneration_lock( $lock_key, $cache_group );

		self::debug_log( sprintf( 'Regenerated %d cache entr(ies) in group %s', count( $job['cache_keys'] ), $cache_group ) );
	}

	/**
	 * Prepare the global state a block render depends on, and return a closure that
	 * puts back whatever was there before.
	 *
	 * Two concerns, both of which would otherwise make the regenerated markup depend
	 * on where the job runs:
	 *
	 * - Post context. Newspack_Blocks::build_articles_query() reads get_the_ID() and
	 *   is_singular() to exclude the post being viewed, and get_the_content() to find
	 *   sibling blocks' specific posts. An Action Scheduler job has no query at all,
	 *   so the recorded post is queried back into place.
	 * - Per-page accumulators. $newspack_blocks_post_id collects the posts already
	 *   shown by earlier blocks on the page; leaving it populated would make a warm
	 *   render exclude posts for reasons that don't apply to the other pages this
	 *   shared cache entry gets served on. $newspack_blocks_hpb_all_blocks is forced
	 *   to an array so the inline-CSS helper short-circuits: its output is a
	 *   stylesheet, not part of the cached markup, and at this point in the request
	 *   it could never be printed anyway.
	 *
	 * @param array $job Queued job, as built by queue_regeneration().
	 * @return callable Restores the previous global state when invoked.
	 */
	protected static function setup_regeneration_context( $job ) {
		global $newspack_blocks_hpb_all_blocks, $newspack_blocks_post_id, $newspack_blocks_all_specific_posts_ids, $wp_query, $post;

		$previous = [
			'hpb_all_blocks'     => $newspack_blocks_hpb_all_blocks,
			'post_id'            => $newspack_blocks_post_id,
			'specific_posts_ids' => $newspack_blocks_all_specific_posts_ids,
			'wp_query'           => $wp_query,
			'post'               => $post,
		];

		$job_post_id      = isset( $job['post_id'] ) ? (int) $job['post_id'] : 0;
		$needs_post_setup = $job_post_id && ! is_singular();

		if ( $needs_post_setup ) {
			// phpcs:ignore WordPress.WP.DiscouragedFunctions.query_posts_query_posts, WordPress.WP.GlobalVariablesOverride.Prohibited
			$wp_query = new WP_Query(
				[
					'p'                   => $job_post_id,
					'post_type'           => 'any',
					'posts_per_page'      => 1,
					'ignore_sticky_posts' => true,
				]
			);
			if ( $wp_query->have_posts() ) {
				$wp_query->the_post();
			}
		}

		$newspack_blocks_post_id        = [];
		$newspack_blocks_hpb_all_blocks = is_array( $newspack_blocks_hpb_all_blocks ) ? $newspack_blocks_hpb_all_blocks : [];
		if ( ! $job_post_id ) {
			// No post to read sibling blocks from; skip that exclusion rather than
			// calling get_the_content() without a post in scope.
			$newspack_blocks_all_specific_posts_ids = is_array( $newspack_blocks_all_specific_posts_ids ) ? $newspack_blocks_all_specific_posts_ids : [];
		}

		return function () use ( $previous, $needs_post_setup ) {
			global $newspack_blocks_hpb_all_blocks, $newspack_blocks_post_id, $newspack_blocks_all_specific_posts_ids, $wp_query, $post;

			$newspack_blocks_hpb_all_blocks         = $previous['hpb_all_blocks'];
			$newspack_blocks_post_id                = $previous['post_id'];
			$newspack_blocks_all_specific_posts_ids = $previous['specific_posts_ids'];

			if ( $needs_post_setup ) {
				$wp_query = $previous['wp_query']; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
				$post     = $previous['post']; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
				wp_reset_postdata();
			}
		};
	}

	/**
	 * Save the block markup to cache. If we've reached this function, that means that the
	 * rendering wasn't short-circuited by a cached version, so we always cache here.
	 *
	 * @param string $block_html Block markup ready for rendering.
	 * @param array  $block_data Parsed block data.
	 * @return string Unmodified $block_html.
	 */
	public static function maybe_cache_block( $block_html, $block_data ) {
		// The regeneration path writes the cache itself under the frozen set of cache
		// keys captured when the job was queued; don't let this normal caching path
		// overwrite it (and potentially miss some of those keys).
		if ( self::$is_regenerating ) {
			return $block_html;
		}

		if ( ! self::should_cache_block( $block_data ) ) {
			return $block_html;
		}

		$cache_key   = self::get_cache_key( $block_data );
		$cache_group = self::get_cache_group();

		$cache_data = [
			'timestamp_generated' => time(),
			'cached_content'      => $block_html,
		];
		wp_cache_set( $cache_key, $cache_data, $cache_group, self::get_cache_expiry() ); // phpcs:ignore WordPressVIPMinimum.Performance.LowExpiryCacheTime.CacheTimeUndetermined

		self::debug_log( sprintf( 'Caching block: item %s in group %s', $cache_key, $cache_group ) );

		return $block_html;
	}
}
Newspack_Blocks_Caching::init();
