<?php
/**
 * Plugin Name: Housing Manual Page Order
 * Description: Reorders the published pages in the Housing Manual navigation.
 * Version: 1.0.2
 * Requires at least: 6.7
 * Requires PHP: 8.3
 * Text Domain: housing_manual_page_order
 * License: GPL-2.0-or-later
 *
 * @package HousingManualPageOrder
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Housing_Manual_Page_Order_Plugin {
	private const VERSION       = '1.0.2';
	private const PAGE_SLUG     = 'housing_manual_page_order';
	private const SAVE_ACTION   = 'hm_page_order_save';
	private const NONCE_ACTION  = 'hm_page_order_save';
	private const TEXT_DOMAIN   = 'housing_manual_page_order';
	private const MAX_TREE_DEPTH = 12;

	private string $page_hook = '';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'save_order' ) );
	}

	public function register_page(): void {
		if ( ! $this->can_manage() ) {
			return;
		}

		$this->page_hook = (string) add_submenu_page(
			'edit.php?post_type=page',
			__( 'Page Order', self::TEXT_DOMAIN ),
			__( 'Page Order', self::TEXT_DOMAIN ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	public function enqueue_assets( string $hook_suffix ): void {
		if ( '' === $this->page_hook || $this->page_hook !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'hm_page_order_admin',
			plugin_dir_url( __FILE__ ) . 'assets/page_order.css',
			array(),
			self::VERSION
		);

		wp_enqueue_script(
			'hm_page_order_admin',
			plugin_dir_url( __FILE__ ) . 'assets/page_order.js',
			array(),
			self::VERSION,
			true
		);
	}

	public function render_page(): void {
		if ( ! $this->can_manage() ) {
			wp_die(
				esc_html__( 'You do not have permission to manage page order.', self::TEXT_DOMAIN ),
				esc_html__( 'Access denied', self::TEXT_DOMAIN ),
				array( 'response' => 403 )
			);
		}

		$context = $this->get_context();

		echo '<div class="wrap hmpo_wrap">';
		echo '<h1>' . esc_html__( 'Page Order', self::TEXT_DOMAIN ) . '</h1>';

		$this->render_result_notice();

		if ( ! $context['ready'] ) {
			$this->render_integration_notice();
			echo '</div>';
			return;
		}

		echo '<p>' . esc_html__( 'Drag pages to reorder them among pages with the same parent. You can also use the move buttons. Changes stay here until you select Save order.', self::TEXT_DOMAIN ) . '</p>';
		echo '<p class="hmpo_note">' . esc_html__( 'This updates the order used by the section rail, the header menu, and the Previous and Next links.', self::TEXT_DOMAIN ) . '</p>';

		if ( empty( $context['groups'] ) ) {
			echo '<div class="notice notice-info"><p>' . esc_html__( 'There are no published pages in the current navigation.', self::TEXT_DOMAIN ) . '</p></div>';
			echo '</div>';
			return;
		}

		echo '<form class="hmpo_form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::SAVE_ACTION ) . '">';
		echo '<input type="hidden" name="hmpo_snapshot" value="' . esc_attr( $context['snapshot_hash'] ) . '">';
		wp_nonce_field( self::NONCE_ACTION, 'hmpo_nonce' );

		echo '<h2>' . esc_html__( 'Top level pages', self::TEXT_DOMAIN ) . '</h2>';
		echo '<div class="hmpo_tree">';
		$this->render_nodes( $context['tree'], 0, $context['pages'] );
		echo '</div>';

		echo '<div class="hmpo_save_row">';
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Save order', self::TEXT_DOMAIN ) . '</button>';
		echo '<span class="hmpo_status" role="status" aria-live="polite">' . esc_html__( 'No unsaved changes.', self::TEXT_DOMAIN ) . '</span>';
		echo '</div>';
		echo '</form>';
		echo '</div>';
	}

	public function save_order(): void {
		if ( ! $this->can_manage() ) {
			wp_die(
				esc_html__( 'You do not have permission to save page order.', self::TEXT_DOMAIN ),
				esc_html__( 'Access denied', self::TEXT_DOMAIN ),
				array( 'response' => 403 )
			);
		}

		$nonce = isset( $_POST['hmpo_nonce'] ) && is_string( $_POST['hmpo_nonce'] )
			? sanitize_text_field( wp_unslash( $_POST['hmpo_nonce'] ) )
			: '';

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_die(
				esc_html__( 'The security check failed. Return to Page Order and try again.', self::TEXT_DOMAIN ),
				esc_html__( 'Security check failed', self::TEXT_DOMAIN ),
				array( 'response' => 403 )
			);
		}

		$context = $this->get_context();

		if ( ! $context['ready'] ) {
			$this->redirect_with_result( 'integration' );
		}

		$submitted_snapshot = isset( $_POST['hmpo_snapshot'] ) && is_string( $_POST['hmpo_snapshot'] )
			? wp_unslash( $_POST['hmpo_snapshot'] )
			: '';

		if ( ! preg_match( '/^[a-f0-9]{64}$/D', $submitted_snapshot ) || ! hash_equals( $context['snapshot_hash'], $submitted_snapshot ) ) {
			$this->redirect_with_result( 'stale' );
		}

		$raw_order = isset( $_POST['hmpo_order'] ) && is_array( $_POST['hmpo_order'] )
			? wp_unslash( $_POST['hmpo_order'] )
			: null;
		$ordered_groups = $this->validate_submitted_order( $raw_order, $context['groups'] );

		if ( null === $ordered_groups ) {
			$this->redirect_with_result( 'invalid' );
		}

		$updates        = array();
		$changed_groups = array();

		foreach ( $ordered_groups as $parent_id => $page_ids ) {
			if ( $page_ids === $context['groups'][ $parent_id ] ) {
				continue;
			}

			$changed_groups[ $parent_id ] = $page_ids;

			foreach ( $page_ids as $position => $page_id ) {
				if ( ! current_user_can( 'edit_post', $page_id ) ) {
					$this->redirect_with_result( 'permission' );
				}

				if ( (int) $context['pages'][ $page_id ]['menu_order'] !== $position ) {
					$updates[] = array(
						'id'         => $page_id,
						'menu_order' => $position,
					);
				}
			}
		}

		if ( empty( $updates ) ) {
			$this->redirect_with_result( 'unchanged' );
		}

		if ( ! $this->posts_table_supports_transactions() ) {
			$this->redirect_with_result( 'database' );
		}

		global $wpdb;

		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			$this->redirect_with_result( 'save_failed' );
		}

		$locked = $this->lock_changed_groups( $changed_groups, $context['pages'] );

		if ( true !== $locked ) {
			$this->rollback_updates( array() );
			$this->redirect_with_result( false === $locked ? 'stale' : 'save_failed' );
		}

		foreach ( $updates as $update ) {
			$result = wp_update_post(
				array(
					'ID'         => $update['id'],
					'menu_order' => $update['menu_order'],
				),
				true
			);

			if ( is_wp_error( $result ) || 0 === $result || false === $result ) {
				$this->rollback_updates( $updates );
				$this->redirect_with_result( 'save_failed' );
			}

			$stored_order = get_post_field( 'menu_order', $update['id'], 'raw' );

			if ( ! is_numeric( $stored_order ) || (int) $stored_order !== $update['menu_order'] ) {
				$this->rollback_updates( $updates );
				$this->redirect_with_result( 'save_failed' );
			}
		}

		if ( false === $wpdb->query( 'COMMIT' ) ) {
			$this->rollback_updates( $updates );
			$this->redirect_with_result( 'save_failed' );
		}

		$this->redirect_with_result( 'saved' );
	}

	private function can_manage(): bool {
		return current_user_can( 'manage_options' ) && current_user_can( 'edit_others_pages' ) && current_user_can( 'hm_view_all_audiences' );
	}

	private function integration_available(): bool {
		$required_functions = array(
			'\\HousingManual\\Navigation\\get_tree',
			'\\HousingManual\\Navigation\\is_in_manual',
			'\\HousingManual\\Access\\has_provider',
			'\\HousingManual\\Access\\can_view',
			'\\HousingManual\\Access\\filter_post_ids',
		);

		foreach ( $required_functions as $function_name ) {
			if ( ! function_exists( $function_name ) ) {
				return false;
			}
		}

		return \HousingManual\Access\has_provider();
	}

	private function get_context(): array {
		if ( ! $this->integration_available() ) {
			return array( 'ready' => false );
		}

		$tree = \HousingManual\Navigation\get_tree();

		if ( ! is_array( $tree ) ) {
			return array( 'ready' => false );
		}

		$groups = array();
		$pages  = array();

		if ( ! $this->collect_tree( $tree, 0, $groups, $pages, 1 ) ) {
			return array( 'ready' => false );
		}

		ksort( $groups, SORT_NUMERIC );
		ksort( $pages, SORT_NUMERIC );

		$snapshot = array(
			'groups' => array(),
			'pages'  => array(),
		);

		foreach ( $groups as $parent_id => $page_ids ) {
			$snapshot['groups'][] = array( (int) $parent_id, array_values( $page_ids ) );
		}

		foreach ( $pages as $page_id => $page ) {
			$snapshot['pages'][] = array(
				(int) $page_id,
				(int) $page['parent'],
				(int) $page['menu_order'],
				(string) $page['status'],
				(string) $page['title'],
				(string) $page['template'],
				(bool) $page['hidden'],
			);
		}

		$encoded_snapshot = wp_json_encode( $snapshot );

		if ( ! is_string( $encoded_snapshot ) ) {
			return array( 'ready' => false );
		}

		return array(
			'ready'         => true,
			'tree'          => $tree,
			'groups'        => $groups,
			'pages'         => $pages,
			'snapshot_hash' => hash_hmac( 'sha256', $encoded_snapshot, wp_salt( 'auth' ) ),
		);
	}

	private function collect_tree( array $nodes, int $expected_parent, array &$groups, array &$pages, int $depth ): bool {
		if ( $depth > self::MAX_TREE_DEPTH ) {
			return false;
		}

		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) || ! isset( $node['id'], $node['parent'], $node['children'], $node['title'] ) || ! is_array( $node['children'] ) ) {
				return false;
			}

			$page_id   = (int) $node['id'];
			$parent_id = (int) $node['parent'];

			if ( $page_id <= 0 || $parent_id !== $expected_parent || isset( $pages[ $page_id ] ) ) {
				return false;
			}

			$post = get_post( $page_id );

			if ( ! $post instanceof WP_Post || 'page' !== $post->post_type || 'publish' !== $post->post_status || $parent_id !== (int) $post->post_parent ) {
				return false;
			}

			if ( ! \HousingManual\Navigation\is_in_manual( $page_id ) || ! \HousingManual\Access\can_view( $post ) ) {
				return false;
			}

			$groups[ $parent_id ][] = $page_id;
			$pages[ $page_id ]     = array(
				'parent'     => $parent_id,
				'menu_order' => (int) $post->menu_order,
				'status'     => (string) $post->post_status,
				'title'      => (string) $post->post_title,
				'template'   => (string) get_page_template_slug( $page_id ),
				'hidden'     => (bool) get_post_meta( $page_id, 'hide_from_nav', true ),
			);

			if ( ! $this->collect_tree( $node['children'], $page_id, $groups, $pages, $depth + 1 ) ) {
				return false;
			}
		}

		return true;
	}

	private function validate_submitted_order( $raw_order, array $expected_groups ): ?array {
		if ( ! is_array( $raw_order ) || count( $raw_order ) !== count( $expected_groups ) ) {
			return null;
		}

		$ordered_groups = array();
		$seen_pages     = array();

		foreach ( $raw_order as $raw_parent_id => $raw_page_ids ) {
			$parent_text = (string) $raw_parent_id;

			if ( ! preg_match( '/^(0|[1-9][0-9]*)$/D', $parent_text ) || ! is_array( $raw_page_ids ) ) {
				return null;
			}

			$parent_id = (int) $parent_text;

			if ( ! array_key_exists( $parent_id, $expected_groups ) || count( $raw_page_ids ) !== count( $expected_groups[ $parent_id ] ) ) {
				return null;
			}

			$raw_keys = array_keys( $raw_page_ids );

			if ( ! empty( $raw_keys ) && $raw_keys !== range( 0, count( $raw_keys ) - 1 ) ) {
				return null;
			}

			$normalized_page_ids = array();

			foreach ( $raw_page_ids as $raw_page_id ) {
				if ( ! is_string( $raw_page_id ) || ! preg_match( '/^[1-9][0-9]*$/D', $raw_page_id ) ) {
					return null;
				}

				$page_id = (int) $raw_page_id;

				if ( isset( $seen_pages[ $page_id ] ) ) {
					return null;
				}

				$seen_pages[ $page_id ] = true;
				$normalized_page_ids[] = $page_id;
			}

			$ordered_groups[ $parent_id ] = $normalized_page_ids;
		}

		ksort( $ordered_groups, SORT_NUMERIC );
		ksort( $expected_groups, SORT_NUMERIC );

		if ( array_keys( $ordered_groups ) !== array_keys( $expected_groups ) ) {
			return null;
		}

		foreach ( $expected_groups as $parent_id => $expected_page_ids ) {
			$received_page_ids = $ordered_groups[ $parent_id ];

			$allowed = array_fill_keys( $expected_page_ids, true );

			foreach ( $received_page_ids as $page_id ) {
				if ( ! isset( $allowed[ $page_id ] ) ) {
					return null;
				}

				unset( $allowed[ $page_id ] );
			}

			if ( ! empty( $allowed ) ) {
				return null;
			}
		}

		return $ordered_groups;
	}

	private function lock_changed_groups( array $changed_groups, array $pages ): ?bool {
		global $wpdb;

		$page_ids = array();

		foreach ( $changed_groups as $group_page_ids ) {
			foreach ( $group_page_ids as $page_id ) {
				if ( ! isset( $pages[ $page_id ] ) ) {
					return false;
				}

				$page_ids[] = (int) $page_id;
			}
		}

		$page_ids = array_values( array_unique( $page_ids ) );

		if ( empty( $page_ids ) || ! isset( $wpdb->posts ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_results' ) ) {
			return null;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $page_ids ), '%d' ) );
		$query        = "SELECT ID, post_type, post_status, post_parent, menu_order, post_title FROM {$wpdb->posts} WHERE ID IN ({$placeholders}) ORDER BY ID FOR UPDATE";
		$prepared     = $wpdb->prepare( $query, ...$page_ids );

		if ( ! is_string( $prepared ) ) {
			return null;
		}

		$locked_posts = $wpdb->get_results( $prepared, ARRAY_A );

		if ( ! is_array( $locked_posts ) ) {
			return null;
		}

		if ( count( $locked_posts ) !== count( $page_ids ) ) {
			return false;
		}

		$seen_pages = array();

		foreach ( $locked_posts as $locked_post ) {
			if ( ! is_array( $locked_post ) || ! isset( $locked_post['ID'], $locked_post['post_type'], $locked_post['post_status'], $locked_post['post_parent'], $locked_post['menu_order'], $locked_post['post_title'] ) ) {
				return null;
			}

			$page_id = (int) $locked_post['ID'];

			if ( isset( $seen_pages[ $page_id ] ) || ! isset( $pages[ $page_id ] ) ) {
				return false;
			}

			$seen_pages[ $page_id ] = true;
			$expected_page          = $pages[ $page_id ];

			if (
				'page' !== $locked_post['post_type'] ||
				(string) $expected_page['status'] !== (string) $locked_post['post_status'] ||
				(int) $expected_page['parent'] !== (int) $locked_post['post_parent'] ||
				(int) $expected_page['menu_order'] !== (int) $locked_post['menu_order'] ||
				(string) $expected_page['title'] !== (string) $locked_post['post_title']
			) {
				return false;
			}
		}

		return count( $seen_pages ) === count( $page_ids );
	}

	private function posts_table_supports_transactions(): bool {
		global $wpdb;

		if ( ! isset( $wpdb->posts ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return false;
		}

		$engine = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
				$wpdb->posts
			)
		);

		return is_string( $engine ) && 'innodb' === strtolower( $engine );
	}

	private function rollback_updates( array $updates ): void {
		global $wpdb;

		$wpdb->query( 'ROLLBACK' );

		foreach ( $updates as $update ) {
			clean_post_cache( (int) $update['id'] );
		}
	}

	private function render_nodes( array $nodes, int $parent_id, array $pages ): void {
		echo '<ul class="hmpo_list" data-parent-id="' . esc_attr( (string) $parent_id ) . '">';

		foreach ( $nodes as $index => $node ) {
			$page_id     = (int) $node['id'];
			$title       = (string) $node['title'];
			$child_count = count( $node['children'] );
			$page        = $pages[ $page_id ];

			echo '<li class="hmpo_item" data-page-id="' . esc_attr( (string) $page_id ) . '" data-menu-order="' . esc_attr( (string) $page['menu_order'] ) . '">';
			echo '<div class="hmpo_row">';
			echo '<span class="hmpo_handle" aria-hidden="true" draggable="true">&#8942;</span>';
			echo '<span class="hmpo_title">' . esc_html( $title ) . '</span>';
			echo '<span class="hmpo_position">' . esc_html__( 'Order', self::TEXT_DOMAIN ) . ': ' . esc_html( (string) $page['menu_order'] ) . '</span>';
			echo '<input type="hidden" name="hmpo_order[' . esc_attr( (string) $parent_id ) . '][]" value="' . esc_attr( (string) $page_id ) . '">';
			echo '<span class="hmpo_controls">';
			echo '<button type="button" class="button hmpo_move_up" aria-label="' . esc_attr( sprintf( __( 'Move %s up', self::TEXT_DOMAIN ), $title ) ) . '"' . ( 0 === $index ? ' disabled' : '' ) . '>&uarr;</button>';
			echo '<button type="button" class="button hmpo_move_down" aria-label="' . esc_attr( sprintf( __( 'Move %s down', self::TEXT_DOMAIN ), $title ) ) . '"' . ( $index === count( $nodes ) - 1 ? ' disabled' : '' ) . '>&darr;</button>';
			echo '</span>';
			echo '</div>';

			if ( $child_count > 0 ) {
				$children_label = sprintf(
					_n( '%s child page', '%s child pages', $child_count, self::TEXT_DOMAIN ),
					number_format_i18n( $child_count )
				);

				echo '<details class="hmpo_children" open>';
				echo '<summary>' . esc_html( $children_label ) . '</summary>';
				$this->render_nodes( $node['children'], $page_id, $pages );
				echo '</details>';
			}

			echo '</li>';
		}

		echo '</ul>';
	}

	private function render_result_notice(): void {
		if ( ! isset( $_GET['hmpo_result'] ) || ! is_string( $_GET['hmpo_result'] ) ) {
			return;
		}

		$result = sanitize_key( wp_unslash( $_GET['hmpo_result'] ) );
		$messages = array(
			'saved'       => array( 'success', __( 'Page order saved.', self::TEXT_DOMAIN ) ),
			'unchanged'   => array( 'info', __( 'No order changes needed saving.', self::TEXT_DOMAIN ) ),
			'stale'       => array( 'warning', __( 'The page structure or order changed while this screen was open. Review the latest order and try again.', self::TEXT_DOMAIN ) ),
			'invalid'     => array( 'error', __( 'The submitted page list was not valid. No order changes were saved.', self::TEXT_DOMAIN ) ),
			'permission'  => array( 'error', __( 'You do not have permission to reorder one or more pages in that group. No order changes were saved.', self::TEXT_DOMAIN ) ),
			'save_failed' => array( 'error', __( 'WordPress could not save the full order. The order changes were rolled back.', self::TEXT_DOMAIN ) ),
			'database'    => array( 'error', __( 'Saving is disabled because the Pages table does not support a single all or nothing order save.', self::TEXT_DOMAIN ) ),
			'integration' => array( 'error', __( 'Saving is disabled because the Housing Manual navigation or its access provider is unavailable.', self::TEXT_DOMAIN ) ),
		);

		if ( ! isset( $messages[ $result ] ) ) {
			return;
		}

		$class   = 'notice notice-' . $messages[ $result ][0 ];
		$message = $messages[ $result ][1 ];

		echo '<div class="' . esc_attr( $class ) . '"><p>' . esc_html( $message ) . '</p></div>';
	}

	private function render_integration_notice(): void {
		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'Page ordering is unavailable because the Housing Manual navigation or its access provider could not be confirmed. No changes have been made.', self::TEXT_DOMAIN );
		echo '</p></div>';
	}

	private function redirect_with_result( string $result ): void {
		$url = add_query_arg(
			array(
				'post_type'   => 'page',
				'page'        => self::PAGE_SLUG,
				'hmpo_result' => $result,
			),
			admin_url( 'edit.php' )
		);

		wp_safe_redirect( $url );
		exit;
	}
}

new Housing_Manual_Page_Order_Plugin();
