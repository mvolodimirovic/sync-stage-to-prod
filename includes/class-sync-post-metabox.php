<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds a "Sync Stage → Prod" metabox to supported post types and provides an AJAX action
 * for syncing a single post to the production site.
 *
 * @package Sync_Stage_To_Prod
 */
class Sync_Post_Metabox {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * AJAX action name for single post sync.
	 *
	 * @var string
	 */
	private string $ajax_action = 'sync_stp_sync_single_post';

	/**
	 * Nonce action for single post sync.
	 *
	 * @var string
	 */
	private string $nonce_action = 'sync_stp_sync_single_post';

	/**
	 * Script handle for the metabox JS.
	 *
	 * @var string
	 */
	private string $script_handle = 'sync-stp-post-metabox';

	/**
	 * Required capability to access sync actions.
	 *
	 * @var string
	 */
	private string $capability = SYNC_STP_CAP;

	/**
	 * Boot the metabox functionality in wp-admin.
	 *
	 * @return void
	 */
	public static function init(): void {
		if ( ! is_admin() ) {
			return;
		}

		if ( empty( self::$instance ) ) {
			self::$instance = new self();
		}

		self::$instance->hooks();
	}

	/**
	 * Register all required hooks.
	 *
	 * @return void
	 */
	private function hooks(): void {
		add_action( 'add_meta_boxes', [ $this, 'register_metaboxes' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_' . $this->ajax_action, [ $this, 'ajax_sync' ] );
	}

	/**
	 * Register metabox for allowed post types.
	 *
	 * @return void
	 */
	public function register_metaboxes(): void {
		$post_types = $this->get_post_types();

		if ( empty( $post_types ) ) {
			return;
		}

		foreach ( $post_types as $post_type ) {
			add_meta_box(
				'sync_stp_post_sync',
				esc_html__( 'Sync Stage → Prod', 'sync-stage-to-prod' ),
				[ $this, 'render_metabox' ],
				$post_type,
				'side',
				'high'
			);
		}
	}

	/**
	 * Render metabox UI.
	 *
	 * @param WP_Post $post Current post object.
	 * @return void
	 */
	public function render_metabox( WP_Post $post ): void {
		$post_id = ! empty( $post->ID ) ? (int) $post->ID : 0;

		if ( $post_id <= 0 ) {
			echo '<p>' . esc_html__( 'Invalid post.', 'sync-stage-to-prod' ) . '</p>';
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			echo '<p>' . esc_html__( 'Insufficient permissions.', 'sync-stage-to-prod' ) . '</p>';
			return;
		}

		if ( ! current_user_can( $this->capability ) ) {
			echo '<p>' . esc_html__( 'Insufficient permissions.', 'sync-stage-to-prod' ) . '</p>';
			return;
		}

		$nonce = wp_create_nonce( $this->nonce_action );

		echo '<div class="sync-stp-post-sync">';
		echo '<p>' . esc_html__( 'Sync only this post to production.', 'sync-stage-to-prod' ) . '</p>';

		echo '<div class="sync-stp-post-sync__controls">';
		echo '<button type="button" class="button button-primary" data-sync-stp-btn="1" data-post-id="' . esc_attr( (string) $post_id ) . '" data-nonce="' . esc_attr( $nonce ) . '">';
		echo esc_html__( 'Sync this post', 'sync-stage-to-prod' );
		echo '</button>';
		echo '<span class="spinner" data-sync-stp-spinner="1" style="float:none;margin:0 0 0 8px;"></span>';
		echo '</div>';

		echo '<div class="sync-stp-post-sync__status" style="margin-top:10px;"></div>';
		echo '</div>';
	}

	/**
	 * Enqueue metabox script only on post editor screens and only for allowed post types.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {
		if ( $hook !== 'post.php' && $hook !== 'post-new.php' ) {
			return;
		}

		if ( ! current_user_can( $this->capability ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$post_type = ! empty( $screen->post_type ) ? (string) $screen->post_type : '';

		if ( empty( $post_type ) ) {
			return;
		}

		$post_types = $this->get_post_types();

		if ( empty( $post_types ) ) {
			return;
		}

		if ( ! in_array( $post_type, $post_types, true ) ) {
			return;
		}

		$src = '';
		if ( defined( 'SYNC_STP_URL' ) ) {
			$src = SYNC_STP_URL . 'assets/js/sync-post-metabox.js';
		}

		if ( empty( $src ) ) {
			return;
		}

		wp_enqueue_script(
			$this->script_handle,
			$src,
			[],
			SYNC_STP_VERSION,
			true
		);

		wp_localize_script(
			$this->script_handle,
			'SyncSTPPostMetabox',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => $this->ajax_action,
				'i18n'    => [
					'syncing' => __( 'Syncing…', 'sync-stage-to-prod' ),
					'done'    => __( 'Done.', 'sync-stage-to-prod' ),
					'error'   => __( 'Error.', 'sync-stage-to-prod' ),
				],
			]
		);
	}

	/**
	 * AJAX handler for syncing a single post.
	 *
	 * @return void
	 */
	public function ajax_sync(): void {
		$nonce   = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

		if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, $this->nonce_action ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid nonce.', 'sync-stage-to-prod' ) ], 403 );
		}

		if ( $post_id <= 0 ) {
			wp_send_json_error( [ 'message' => __( 'Invalid post ID.', 'sync-stage-to-prod' ) ], 400 );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'sync-stage-to-prod' ) ], 403 );
		}

		if ( ! current_user_can( $this->capability ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'sync-stage-to-prod' ) ], 403 );
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Revisions/autosaves cannot be synced.', 'sync-stage-to-prod' ) ], 400 );
		}

		$post_type = get_post_type( $post_id );
		if ( empty( $post_type ) || ! post_type_exists( $post_type ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid post type.', 'sync-stage-to-prod' ) ], 400 );
		}

		$allowed_types = $this->get_post_types();
		if ( empty( $allowed_types ) || ! in_array( (string) $post_type, $allowed_types, true ) ) {
			wp_send_json_error( [ 'message' => __( 'This post type is not allowed for sync.', 'sync-stage-to-prod' ) ], 403 );
		}

		$result = $this->run_single_post_sync( $post_id );

		if ( is_wp_error( $result ) ) {
			$code   = (string) $result->get_error_code();
			$status = 500;

			if ( $code === 'sync_stp_no_handler' ) {
				$status = 200;
			}

			wp_send_json_error(
				[
					'message' => $result->get_error_message(),
					'code'    => $code,
					'data'    => $result->get_error_data(),
				],
				$status
			);
		}

		$message = __( 'OK', 'sync-stage-to-prod' );

		if ( is_array( $result ) && ! empty( $result['message'] ) ) {
			$message = (string) $result['message'];
		}

		if ( is_string( $result ) && $result !== '' ) {
			$message = $result;
		}

		wp_send_json_success(
			[
				'message' => $message,
				'result'  => $result,
			],
			200
		);
	}

	/**
	 * Execute sync for a single post by delegating to Sync_Service (preferred),
	 * or by allowing integrations via a filter.
	 *
	 * @param int $post_id Post ID to sync.
	 * @return mixed|WP_Error
	 */
	private function run_single_post_sync( int $post_id ) {
		$result = null;

		if ( class_exists( 'Sync_Service' ) ) {
			if ( method_exists( 'Sync_Service', 'sync_single_post' ) ) {
				$result = Sync_Service::sync_single_post( $post_id );
			} elseif ( method_exists( 'Sync_Service', 'run_single_post_sync' ) ) {
				$result = Sync_Service::run_single_post_sync( $post_id );
			} elseif ( method_exists( 'Sync_Service', 'run_sync_single_post' ) ) {
				$result = Sync_Service::run_sync_single_post( $post_id );
			} elseif ( method_exists( 'Sync_Service', 'sync_post_by_id' ) ) {
				$result = Sync_Service::sync_post_by_id( $post_id );
			} elseif ( method_exists( 'Sync_Service', 'sync_by_post_id' ) ) {
				$result = Sync_Service::sync_by_post_id( $post_id );
			} elseif ( method_exists( 'Sync_Service', 'sync_post' ) ) {
				$result = Sync_Service::sync_post( $post_id );
			} elseif ( method_exists( 'Sync_Service', 'sync_posts_by_ids' ) ) {
				$result = Sync_Service::sync_posts_by_ids( [ $post_id ] );
			} elseif ( method_exists( 'Sync_Service', 'sync_posts' ) ) {
				$result = Sync_Service::sync_posts( [ $post_id ] );
			}
		}

		if ( empty( $result ) ) {
			$result = apply_filters( 'sync_stp_sync_single_post', null, $post_id );
		}

		if ( empty( $result ) ) {
			return new WP_Error(
				'sync_stp_no_handler',
				__( 'Single post sync is not wired to Sync_Service yet.', 'sync-stage-to-prod' )
			);
		}

		return $result;
	}

	/**
	 * Resolve the list of post types for which the metabox should be available.
	 *
	 * Rules:
	 * - If plugin is configured in "prod" mode, metabox is disabled.
	 * - Prefer plugin settings post_types if present.
	 * - Fallback to all show_ui post types (excluding blocked system types).
	 * - Filterable via sync_stp_post_sync_post_types.
	 *
	 * @return array
	 */
	private function get_post_types(): array {
		$options = get_option( SYNC_STP_OPT_KEY, [] );
		$mode    = ( ! empty( $options['mode'] ) && is_string( $options['mode'] ) && $options['mode'] === 'prod' ) ? 'prod' : 'stage';

		if ( $mode === 'prod' ) {
			return [];
		}

		$post_types = [];

		if ( ! empty( $options['post_types'] ) && is_array( $options['post_types'] ) ) {
			$post_types = array_values( array_unique( array_map( 'sanitize_key', $options['post_types'] ) ) );
		} else {
			$post_types = get_post_types(
				[
					'show_ui' => true,
				],
				'names'
			);

			$post_types = is_array( $post_types ) ? array_values( $post_types ) : [];
			$post_types = array_values( array_filter( array_map( 'sanitize_key', $post_types ) ) );
		}

		$blocked = [ 'attachment', 'revision', 'nav_menu_item' ];
		$post_types = array_values( array_diff( $post_types, $blocked ) );

		$post_types = array_values( array_filter( $post_types, static function ( $t ) {
			return $t !== '' && post_type_exists( $t );
		} ) );

		$post_types = apply_filters( 'sync_stp_post_sync_post_types', $post_types );

		$post_types = is_array( $post_types ) ? array_values( $post_types ) : [];
		$post_types = array_values( array_filter( array_map( 'sanitize_key', $post_types ) ) );

		$post_types = array_values( array_filter( $post_types, static function ( $t ) {
			return $t !== '' && post_type_exists( $t );
		} ) );

		return $post_types;
	}
}
