<?php
/**
 *
 * @link https://organicthemes.com
 * @since 1.0.0
 * @package Organic_Widgets
 *
 * @wordpress-plugin
 * Plugin Name: Bulk Block Converter
 * Plugin URI: https://github.com/mirsadabaz/bulk-block-convert
 * Description: Convert all classic content to blocks. An extremely useful tool when upgrading to the WordPress 5 Gutenberg editor.
 * Version: 1.0.1
 * Author: Mirsad Abaz
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: bbconv
 * Domain Path: /languages/
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

define( 'BBCONV_DOMAIN', 'bbconv' );                   // Text Domain
define( 'BBCONV_SLUG', 'bulk-block-conversion' );      // Plugin slug
define( 'BBCONV_FOLDER', plugin_dir_path( __FILE__ ) );    // Plugin folder
define( 'BBCONV_URL', plugin_dir_url( __FILE__ ) );        // Plugin URL

// post types and statuses plugin work with
define( 'BBCONV_TYPES', serialize( array( 'post', 'page', 'games' ) ) );
define( 'BBCONV_STATUSES', serialize( array( 'publish', 'future', 'draft', 'private' ) ) );

// meta key and value for posts inexing
define( 'BBCONV_META_KEY', 'bbconv_not_converted' );
define( 'BBCONV_META_VALUE', '1' );

/**
 * Add plugin actions if Block Editor is active.
 */
add_action( 'plugins_loaded', 'bbconv_init_the_plugin' );
function bbconv_init_the_plugin() {
	if ( ! bbconv_is_gutenberg_active() ) {
		return;
	}
	// dispatching POST to GET parameters
	add_action( 'init', 'bbconv_dispatch_url' );
	// adding subitem to the Tools menu item
	add_action( 'admin_menu', 'bbconv_display_menu_item' );
	// scan posts via ajax
	add_action( 'wp_ajax_bbconv_scan_posts', 'bbconv_scan_posts_ajax' );
	// bulk all posts convert
	add_action( 'wp_ajax_bbconv_bulk_convert', 'bbconv_bulk_convert_ajax' );
	// single post convert via ajax
	add_action( 'wp_ajax_bbconv_single_convert', 'bbconv_single_convert_ajax' );
	// automatically index posts on creation and updating
	add_action( 'post_updated', 'bbconv_index_after_save', 10, 2 );
}

/**
 * Check if Block Editor is active.
 * Must only be used after plugins_loaded action is fired.
 *
 * @return bool
 */
function bbconv_is_gutenberg_active() {
	// Gutenberg plugin is installed and activated.
	// $gutenberg = ! ( false === has_filter( 'replace_editor', 'gutenberg_init' ) );

	// Block editor since 5.0.
	$block_editor = version_compare( $GLOBALS['wp_version'], '5.0-beta', '>' );

	// if ( ! $gutenberg && ! $block_editor ) {
	if ( ! $block_editor ) {
		return false;
	}

	$gutenberg_plugin = function_exists( 'gutenberg_register_packages_scripts' );

	// Remove Gutenberg plugin scripts reassigning.
	if ( $gutenberg_plugin ) {
		add_action( 'wp_default_scripts', 'bbconv_remove_gutenberg_overrides', 5 );
	}

	return true;

	// if ( bbconv_is_classic_editor_plugin_active() ) {
	// $editor_option       = get_option( 'classic-editor-replace' );
	// $block_editor_active = array( 'no-replace', 'block' );
	//
	// return in_array( $editor_option, $block_editor_active, true );
	// }
	// return true;
}

/**
 * Check if Classic Editor plugin is active.
 *
 * @return bool
 */
function bbconv_is_classic_editor_plugin_active() {
	if ( ! function_exists( 'is_plugin_active' ) ) {
		include_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	if ( is_plugin_active( 'classic-editor/classic-editor.php' ) ) {
		return true;
	}

	return false;
}

/**
 * Remove Gutenberg plugin scripts reassigning.
 */
function bbconv_remove_gutenberg_overrides() {
	$pagematch = strpos( $_SERVER['REQUEST_URI'], '/wp-admin/tools.php?page=' . BBCONV_SLUG );
	if ( $pagematch !== false ) {
		remove_action( 'wp_default_scripts', 'gutenberg_register_vendor_scripts' );
		remove_action( 'wp_default_scripts', 'gutenberg_register_packages_scripts' );
	}
}

/**
 * Adding subitem to the Tools menu item.
 */
function bbconv_display_menu_item() {
	$plugin_page = add_management_page( __( 'Bulk Block Conversion', BBCONV_DOMAIN ), __( 'Block Conversion', BBCONV_DOMAIN ), 'manage_options', BBCONV_SLUG, 'bbconv_show_admin_page' );

	// Load the JS conditionally
	add_action( 'load-' . $plugin_page, 'bbconv_load_admin_css_js' );
}

/**
 * This function is only called when our plugin's page loads!
 */
function bbconv_load_admin_css_js() {
	// Unfortunately we can't just enqueue our scripts here - it's too early. So register against the proper action hook to do it
	add_action( 'admin_enqueue_scripts', 'bbconv_enqueue_admin_css_js' );
}

/**
 * Enqueue admin styles and scripts.
 */
function bbconv_enqueue_admin_css_js() {
	// wp_enqueue_style( BBCONV_DOMAIN . '-style', BBCONV_URL . 'css/styles.css' );
	wp_register_script( BBCONV_DOMAIN . '-script', BBCONV_URL . 'js/scripts.js', array( 'jquery', 'wp-blocks', 'wp-edit-post' ), false, true );
	$jsObj = array(
		'ajaxUrl'                      => admin_url( 'admin-ajax.php' ),
		'serverErrorMessage'           => '<div class="error"><p>' . __( 'Server error occured!', BBCONV_DOMAIN ) . '</p></div>',
		'scanningMessage'              => '<p>' . sprintf( __( 'Scanning... %s%%', BBCONV_DOMAIN ), 0 ) . '</p>',
		'bulkConvertingMessage'        => '<p>' . sprintf( __( 'Converting... %s%%', BBCONV_DOMAIN ), 0 ) . '</p>',
		'bulkConvertingSuccessMessage' => '<div class="updated"><p>' . __( 'All posts successfully converted!', BBCONV_DOMAIN ) . '</p></div>',
		'confirmConvertAllMessage'     => __( 'You are about to convert all classic posts to blocks. These changes are irreversible. Convert all classic posts to blocks?', BBCONV_DOMAIN ),
		'convertingSingleMessage'      => __( 'Converting...', BBCONV_DOMAIN ),
		'convertedSingleMessage'       => __( 'Converted', BBCONV_DOMAIN ),
		'failedMessage'                => __( 'Failed', BBCONV_DOMAIN ),
	);
	wp_localize_script( BBCONV_DOMAIN . '-script', 'bbconvObj', $jsObj );
	wp_enqueue_script( BBCONV_DOMAIN . '-script' );
}

/**
 * Rendering admin page of the plugin.
 */
function bbconv_show_admin_page() {
	$indexed_arr   = bbconv_count_indexed();
	$indexed_exist = bbconv_exist_indexed( $indexed_arr );
	?>
<div id="bbconv-wrapper" class="wrap">
	<h1><?php echo get_admin_page_title(); ?></h1>
	<?php
	global $bbconv_success, $bbconv_error;
	if ( isset( $_GET['result'] ) && $_GET['result'] == '1' ) {
		if ( ! empty( $bbconv_error ) ) {
			echo '<div class="error"><p>' . $bbconv_error . '</p></div>';
		}
		if ( ! empty( $bbconv_success ) ) {
			echo '<div class="updated"><p>' . $bbconv_success . '</p></div>';
		}
	}
	?>
	<p><?php _e( 'This process will scan your post types for any content that may be converted to blocks. Then, you can choose to convert posts to blocks individually, or bulk convert all content to blocks.', BBCONV_DOMAIN ); ?></p>
	<p><span style="color:red;"><?php _e( 'Please note:', BBCONV_DOMAIN ); ?></span> <?php _e( 'Converting content to blocks is irreversible. We highly recommend creating a backup before conversion.', BBCONV_DOMAIN ); ?></p>
	<p>
		<button id="bbconv-scan-btn" class="button button-hero" data-nonce="<?php echo wp_create_nonce( 'bbconv_scan_content' ); ?>"><?php _e( 'Scan Content', BBCONV_DOMAIN ); ?></button>
	<?php if ( $indexed_exist ) : ?>
		&nbsp;&nbsp;
		<button id="bbconv-convert-all-btn" class="button button-primary button-hero" data-nonce="<?php echo wp_create_nonce( 'bbconv_bulk_convert' ); ?>"><?php _e( 'Bulk Convert All', BBCONV_DOMAIN ); ?></button>
	<?php endif; ?>
	</p>
	<div id="bbconv-output">
	<?php if ( $indexed_exist ) : ?>
		<div id="bbconv-results"><?php bbconv_render_results( $indexed_arr ); ?></div>
		<div id="bbconv-table"><?php bbconv_render_table(); ?></div>
	<?php else : ?>
		<?php if ( ! empty( $_GET['bbconv_scan_finished'] ) ) : ?>
			<p><?php _e( 'No posts found.', BBCONV_DOMAIN ); ?></p>
		<?php endif; ?>
	<?php endif; ?>
	</div>
</div>
	<?php
}

/**
 * Scan posts via ajax.
 */
function bbconv_scan_posts_ajax() {
	$offset         = intval( $_REQUEST['offset'] );
	$total_expected = intval( $_REQUEST['total'] );

	$total_actual  = 0;
	$post_types    = unserialize( BBCONV_TYPES );
	$post_statuses = unserialize( BBCONV_STATUSES );
	foreach ( $post_types as $type ) {
		$type_total = wp_count_posts( $type );
		foreach ( $post_statuses as $status ) {
			$total_actual += (int) $type_total->$status;
		}
	}

	header( 'Content-Type: application/json; charset=UTF-8' );
	$json = array(
		'error'   => false,
		'offset'  => $total_actual,
		'total'   => $total_actual,
		'message' => '',
	);

	$nonce = esc_attr( $_REQUEST['_wpnonce'] );
	if ( ! wp_verify_nonce( $nonce, 'bbconv_scan_content' ) ) {
		$json['error']   = true;
		$json['message'] = '<div class="error"><p>' . __( 'Forbidden!', BBCONV_DOMAIN ) . '</p></div>';
		die( json_encode( $json ) );
	}

	if ( $total_expected != -1 && $total_expected != $total_actual ) {
		$json['error']   = true;
		$json['message'] = '<div class="error"><p>' . __( 'An error occurred while scanning! Someone added or deleted one or more posts during the scanning process. Try again.', BBCONV_DOMAIN ) . '</p></div>';
		die( json_encode( $json ) );
	}

	$args        = array(
		'post_type'      => $post_types,
		'post_status'    => $post_statuses,
		'posts_per_page' => 10,
		'offset'         => $offset,
	);
	$posts_array = get_posts( $args );

	foreach ( $posts_array as $post ) {
		if ( bbconv_find_classic( $post->post_content ) ) {
			update_post_meta( $post->ID, BBCONV_META_KEY, BBCONV_META_VALUE );
		}
		$offset++;
	}
	$json['offset']   = $offset;
	$percentage       = (int) ( $offset / $total_actual * 100 );
	$json['message'] .= '<p>' . sprintf( __( 'Scanning... %s%%', BBCONV_DOMAIN ), $percentage ) . '</p>';

	die( json_encode( $json ) );
}

/**
 * Bulk converting of all indexed posts via ajax.
 */
function bbconv_bulk_convert_ajax() {
	header( 'Content-Type: application/json; charset=UTF-8' );

	$json  = array();
	$nonce = esc_attr( $_REQUEST['_wpnonce'] );
	if ( ! wp_verify_nonce( $nonce, 'bbconv_bulk_convert' ) ) {
		$json['error']   = true;
		$json['message'] = '<div class="error"><p>' . __( 'Forbidden!', BBCONV_DOMAIN ) . '</p></div>';
		die( json_encode( $json ) );
	}

	if ( ! empty( $_GET['total'] ) ) {
		$offset         = intval( $_GET['offset'] );
		$total_expected = intval( $_GET['total'] );

		$post_types    = unserialize( BBCONV_TYPES );
		$post_statuses = unserialize( BBCONV_STATUSES );

		$total_actual = bbconv_get_count( $post_types );
		if ( $total_expected == -1 ) {
			$total_expected = $total_actual;
		}

		$json = array(
			'error'     => false,
			'offset'    => $total_expected,
			'total'     => $total_expected,
			'message'   => '',
			'postsData' => array(),
		);

		if ( $total_expected != ( $total_actual + $offset ) ) {
			$json['error']   = true;
			$json['message'] = '<div class="error"><p>' . __( 'An error occurred while bulk converting! Someone added or deleted one or more posts during the converting process. Try again.', BBCONV_DOMAIN ) . '</p></div>';
			die( json_encode( $json ) );
		}

		$args        = array(
			'post_type'      => $post_types,
			'post_status'    => $post_statuses,
			'posts_per_page' => 10,
			'meta_key'       => BBCONV_META_KEY,
			'meta_value'     => BBCONV_META_VALUE,
		);
		$posts_array = get_posts( $args );

		$posts_data = array();
		foreach ( $posts_array as $post ) {
			$posts_data[] = array(
				'id'      => $post->ID,
				'content' => wpautop( $post->post_content ),
			);
			$offset++;
		}
		$json['postsData'] = $posts_data;

		$json['offset']  = $offset;
		$percentage      = (int) ( $offset / $total_expected * 100 );
		$json['message'] = '<p>' . sprintf( __( 'Converting... %s%%', BBCONV_DOMAIN ), $percentage ) . '</p>';

		die( json_encode( $json ) );
	}

	if ( ! empty( $_POST['total'] ) ) {
		$json = array(
			'error'  => false,
			'offset' => intval( $_POST['offset'] ),
			'total'  => intval( $_POST['total'] ),
		);
		foreach ( $_POST['postsData'] as $post ) {
			$post_data = array(
				'ID'           => $post['id'],
				'post_content' => $post['content'],
			);
			if ( ! wp_update_post( $post_data ) ) {
				$json['error'] = true;
				die( json_encode( $json ) );
			}
		}
		die( json_encode( $json ) );
	}
}

/**
 * Find content created in Classic editor
 *
 * @param string $content the content of a post
 *
 * @return bool
 */
function bbconv_find_classic( $content ) {
	if ( ! empty( $content )
		&& strpos( $content, '<!-- wp:' ) === false ) {
		return true;
	}
	return false;
}

/**
 * Sort the number of posts by type and create labeled array.
 *
 * @return array
 */
function bbconv_count_indexed() {
	$post_types = unserialize( BBCONV_TYPES );

	$indexed = array();
	foreach ( $post_types as $type ) {
		$post_type_obj     = get_post_type_object( $type );
		$label             = $post_type_obj->labels->name;
		$indexed[ $label ] = bbconv_get_count( $type );
	}

	return $indexed;
}

/**
 * Check whether indexed posts exist.
 *
 * @param array $indexed an array of indexed posts
 *
 * @return bool
 */
function bbconv_exist_indexed( $indexed ) {
	foreach ( $indexed as $index ) {
		if ( $index > 0 ) {
			return true;
		}
	}
	return false;
}

/**
 * Count indexed posts by type.
 *
 * @param string/array $type post type/types
 *
 * @return int
 */
function bbconv_get_count( $type ) {
	$args = array(
		'posts_per_page' => -1,
		'post_type'      => $type,
		'meta_key'       => BBCONV_META_KEY,
		'meta_value'     => BBCONV_META_VALUE,
	);

	$posts_query = new WP_Query( $args );
	return $posts_query->post_count;
}

/**
 * Display results list.
 */
function bbconv_render_results( $indexed ) {
	$output  = '<h2>' . __( 'Scan results', BBCONV_DOMAIN ) . '</h2>';
	$output .= '<p>' . __( 'The following post types are ready for conversion:', BBCONV_DOMAIN ) . '</p>';
	$output .= '<ul style="list-style-type:disc;padding-left:15px;">';
	foreach ( $indexed as $type => $number ) {
		$output .= '<li><strong>' . $number . '</strong> ' . $type . '</li>';
	}
	$output .= '</ul>';
	echo $output;
}

/**
 * Display table with indexed posts.
 */
function bbconv_render_table() {
	?>
	<div class="meta-box-sortables ui-sortable">
	<?php
	$table = new Bbconv_List_Table();
	$table->views();
	?>
		<form method="post">
		<?php
			$table->prepare_items();
			$table->search_box( __( 'Search', BBCONV_DOMAIN ), 'bbconv-search' );
			$table->display();
		?>
		</form>
	</div>
	<?php
}

/**
 * Get translated status label by slug.
 *
 * @param string $status status slug
 *
 * @return string
 */
function bbconv_status_label( $status ) {
	$status_labels = array(
		'any'     => __( 'All', BBCONV_DOMAIN ),
		'publish' => __( 'Published', BBCONV_DOMAIN ),
		'future'  => __( 'Future', BBCONV_DOMAIN ),
		'draft'   => __( 'Drafts', BBCONV_DOMAIN ),
		'private' => __( 'Private', BBCONV_DOMAIN ),
	);

	if ( array_key_exists( $status, $status_labels ) ) {
		return $status_labels[ $status ];
	}
	return $status;
}

/**
 * Single post converting via ajax.
 */
function bbconv_single_convert_ajax() {
	header( 'Content-Type: application/json; charset=UTF-8' );

	$json = array(
		'error'   => false,
		'message' => '',
	);

	$nonce = esc_attr( $_REQUEST['_wpnonce'] );
	if ( ! wp_verify_nonce( $nonce, 'bbconv_convert_post_' . $_REQUEST['post'] ) ) {
		$json['error']   = true;
		$json['message'] = '<div class="error"><p>' . __( 'Forbidden!', BBCONV_DOMAIN ) . '</p></div>';
		die( json_encode( $json ) );
	}

	if ( ! empty( $_GET['post'] ) ) {
		$post_id = intval( $_GET['post'] );
		$post    = get_post( $post_id );
		if ( ! $post ) {
			$json['error'] = true;
			die( json_encode( $json ) );
		} else {
			$json['message'] = wpautop( $post->post_content );
			die( json_encode( $json ) );
		}
	}

	if ( ! empty( $_POST['post'] ) ) {
		$post_id   = intval( $_POST['post'] );
		$post_data = array(
			'ID'           => $post_id,
			'post_content' => $_POST['content'],
		);
		if ( ! wp_update_post( $post_data ) ) {
			$json['error'] = true;
			die( json_encode( $json ) );
		} else {
			$json['message'] = $post_id;
			die( json_encode( $json ) );
		}
	}
}

/**
 * Cleaning up on plugin deactivation.
 */
function bbconv_deactivate() {
	global $wpdb;
	$query = "DELETE FROM {$wpdb->postmeta} WHERE meta_key='" . BBCONV_META_KEY . "'";
	$wpdb->query( $query );
}
register_deactivation_hook( __FILE__, 'bbconv_deactivate' );

/**
 * Automatically index posts on creation or updating.
 */
function bbconv_index_after_save( $post_ID, $post_after ) {
	if ( bbconv_find_classic( $post_after->post_content ) ) {
		update_post_meta( $post_ID, BBCONV_META_KEY, BBCONV_META_VALUE );
	} else {
		delete_post_meta( $post_ID, BBCONV_META_KEY );
	}
}

/**
 * Dispatching POST to GET parameters.
 */
function bbconv_dispatch_url() {
	$params = array( 'bbconv_post_type', 's', 'paged' );

	foreach ( $params as $param ) {
		bbconv_post_to_get( $param );
	}
}

/**
 * Copy parameter from POST to GET or remove if does not exist or mismatch.
 *
 * @param string $parameter
 */
function bbconv_post_to_get( $parameter ) {
	if ( isset( $_POST[ $parameter ] ) ) {
		if ( ! empty( $_POST[ $parameter ] ) ) {
			if ( empty( $_GET[ $parameter ] ) ||
				$_GET[ $parameter ] != $_POST[ $parameter ] ) {
				$_SERVER['REQUEST_URI'] = add_query_arg( array( $parameter => $_POST[ $parameter ] ) );
			}
		} else {
			if ( ! empty( $_GET[ $parameter ] ) ) {
				$_SERVER['REQUEST_URI'] = remove_query_arg( $parameter );
			}
		}
	}
}

 /**
  * Include table class file.
  */
if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

 /**
  * Custom table class.
  */
class Bbconv_List_Table extends WP_List_Table {

	/** Class constructor */
	public function __construct() {

		parent::__construct(
			[
				'singular' => __( 'Post', BBCONV_DOMAIN ), // singular name of the listed records
				'plural'   => __( 'Posts', BBCONV_DOMAIN ), // plural name of the listed records
				'ajax'     => false, // should this table support ajax?
			]
		);

	}

	/**
	 * Set common arguments for table rendering query.
	 *
	 * @return array
	 */
	public static function set_args_for_query() {
		$post_types    = unserialize( BBCONV_TYPES );
		$post_statuses = unserialize( BBCONV_STATUSES );

		$args = array(
			'post_type'   => $post_types,
			'post_status' => $post_statuses,
			'meta_key'    => BBCONV_META_KEY,
			'meta_value'  => BBCONV_META_VALUE,
		);

		if ( ! empty( $_REQUEST['bbconv_post_type'] ) ) {
			$args['post_type'] = $_REQUEST['bbconv_post_type'];
		}

		if ( ! empty( $_REQUEST['bbconv_post_status'] ) ) {
			$args['post_status'] = $_REQUEST['bbconv_post_status'];
		}

		if ( ! empty( $_REQUEST['s'] ) ) {
			$args['s'] = $_REQUEST['s'];
		}

		if ( ! empty( $_REQUEST['orderby'] ) && $_REQUEST['orderby'] == 'post_title' ) {
			$args['orderby'] = 'title';
			if ( ! empty( $_REQUEST['order'] ) && $_REQUEST['order'] == 'asc' ) {
				$args['order'] = 'ASC';
			}
			if ( ! empty( $_REQUEST['order'] ) && $_REQUEST['order'] == 'desc' ) {
				$args['order'] = 'DESC';
			}
		}

		return $args;
	}

	/**
	 * Get posts with 'bblock_not_converted' meta field
	 *
	 * @param int $per_page
	 * @param int $page_number
	 *
	 * @return mixed
	 */
	public static function get_posts( $per_page = 20, $page_number = 1 ) {

		$args = self::set_args_for_query();

		$args['posts_per_page'] = $per_page;

		$offset         = $per_page * $page_number - $per_page;
		$args['offset'] = $offset;

		$posts_array = get_posts( $args );

		$results = array();
		foreach ( $posts_array as $post ) {
			$results[] = array(
				'ID'         => $post->ID,
				'post_title' => $post->post_title,
				'post_type'  => $post->post_type,
				'action'     => '',
			);
		}

		return $results;
	}

	/**
	 * Return the count of posts that need to be converted.
	 *
	 * @return int
	 */
	public static function count_items() {

		$args = self::set_args_for_query();

		$args['posts_per_page'] = -1;

		$posts_query = new WP_Query( $args );

		return $posts_query->post_count;
	}

	/**
	 * Returns the count of posts with a specific status.
	 *
	 * @return int
	 */
	public static function count_with_status( $post_status ) {

		$post_type = unserialize( BBCONV_TYPES );
		if ( $post_status == 'any' ) {
			$post_status = unserialize( BBCONV_STATUSES );
		}

		$args = array(
			'post_type'      => $post_type,
			'post_status'    => $post_status,
			'posts_per_page' => -1,
			'meta_key'       => BBCONV_META_KEY,
			'meta_value'     => BBCONV_META_VALUE,
		);

		$posts_query = new WP_Query( $args );

		return $posts_query->post_count;
	}

	/** Text displayed when no data is available */
	public function no_items() {
		_e( 'No items available.', BBCONV_DOMAIN );
	}

	/**
	 * Associative array of columns
	 *
	 * @return array
	 */
	public function get_columns() {
		$columns = [
			'cb'         => '<input type="checkbox" />',
			'post_title' => __( 'Title', BBCONV_DOMAIN ),
			'post_type'  => __( 'Post Type', BBCONV_DOMAIN ),
			'action'     => __( 'Action', BBCONV_DOMAIN ),
		];

		return $columns;
	}

	/**
	 * Render the bulk edit checkbox
	 *
	 * @param array $item
	 *
	 * @return string
	 */
	public function column_cb( $item ) {
		$post_id = absint( $item['ID'] );
		return sprintf(
			'<input type="checkbox" id="bbconv-convert-checkbox-%s" name="bulk-convert[]" value="%s" />',
			$post_id,
			$post_id
		);
	}

	/**
	 * Method for post title column
	 *
	 * @param array $item an array of data
	 *
	 * @return string
	 */
	public function column_post_title( $item ) {

		$title = '<strong><a href="' . get_permalink( $item['ID'] ) . '" target="_blank">' . $item['post_title'] . '</a></strong>';

		return $title;
	}

	/**
	 * Method for post type column
	 *
	 * @param array $item an array of data
	 *
	 * @return string
	 */
	public function column_post_type( $item ) {

		$url = esc_url( add_query_arg( array( 'bbconv_post_type' => $item['post_type'] ) ) );

		$post_type_obj = get_post_type_object( $item['post_type'] );
		$label         = $post_type_obj->labels->singular_name;

		$type = '<a href="' . $url . '">' . $label . '</a>';

		return $type;
	}

	/**
	 * Method for action column
	 *
	 * @param array $item an array of data
	 *
	 * @return string
	 */
	public function column_action( $item ) {

		$convert_nonce = wp_create_nonce( 'bbconv_convert_post_' . $item['ID'] );

		$json = '{"action":"bbconv_single_convert", "post":"' . absint( $item['ID'] ) . '", "_wpnonce":"' . $convert_nonce . '"}';

		$action = '<a href="#" id="bbconv-single-convert-' . absint( $item['ID'] ) . '" class="bbconv-single-convert" data-json=\'' . $json . '\'>' . __( 'Convert', BBCONV_DOMAIN ) . '</a>';

		return $action;
	}

	/**
	 * Columns to make sortable.
	 *
	 * @return array
	 */
	public function get_sortable_columns() {
		$sortable_columns = array(
			'post_title' => array( 'post_title', false ),
		);

		return $sortable_columns;
	}

	/**
	 * Returns an associative array containing the bulk action
	 *
	 * @return array
	 */
	public function get_bulk_actions() {
		$actions = array(
			'bulk-convert' => __( 'Convert', BBCONV_DOMAIN ),
		);

		return $actions;
	}

	/**
	 * Get an associative array ( id => link ) with the list
	 * of views available on this table.
	 *
	 * @return array
	 */
	protected function get_views() {
		$status_links  = array();
		$post_statuses = unserialize( BBCONV_STATUSES );
		array_unshift( $post_statuses, 'any' );
		foreach ( $post_statuses as $status ) {
			$status_count = self::count_with_status( $status );
			if ( $status_count > 0 ) {
				$label = bbconv_status_label( $status );
				if ( ( empty( $_REQUEST['bbconv_post_status'] ) && $status == 'any' )
					|| ( ! empty( $_REQUEST['bbconv_post_status'] ) && $_REQUEST['bbconv_post_status'] == $status ) ) {
					$status_links[ $status ] = '<strong>' . $label . '</strong> (' . $status_count . ')';
				} else {
					if ( $status == 'any' ) {
						$url = '?page=' . $_REQUEST['page'];
					} else {
						$url = '?page=' . $_REQUEST['page'] . '&bbconv_post_status=' . $status;
					}
					$status_links[ $status ] = '<a href="' . $url . '">' . $label . '</a> (' . $status_count . ')';
				}
			}
		}

		return $status_links;
	}

	/**
	 * Extra controls to be displayed between bulk actions and pagination
	 *
	 * @param string $which
	 */
	function extra_tablenav( $which ) {
		$post_types = unserialize( BBCONV_TYPES );
		if ( $which == 'top' ) {
			if ( $this->has_items() ) {
				?>
			<div class="alignleft actions bulkactions">
				<select name="bbconv_post_type">
					<option value="">All Post Types</option>
					<?php
					foreach ( $post_types as $post_type ) {
						$selected = '';
						if ( ! empty( $_REQUEST['bbconv_post_type'] ) && $_REQUEST['bbconv_post_type'] == $post_type ) {
							$selected = ' selected = "selected"';
						}
						$post_type_obj = get_post_type_object( $post_type );
						$label         = $post_type_obj->labels->name;
						?>
					<option value="<?php echo $post_type; ?>" <?php echo $selected; ?>><?php echo $label; ?></option>
						<?php
					}
					?>
				</select>
				<?php submit_button( __( 'Filter', BBCONV_DOMAIN ), 'action', 'bbconv_filter_btn', false ); ?>
			</div>
				<?php
			}
		}
		if ( $which == 'bottom' ) {
			// The code that goes after the table is there

		}
	}

	/**
	 * Handles data query and filter, sorting, and pagination.
	 */
	public function prepare_items() {

		$columns               = $this->get_columns();
		$hidden                = array();
		$sortable              = $this->get_sortable_columns();
		$this->_column_headers = array( $columns, $hidden, $sortable );

		/** Process bulk action */
		$this->process_bulk_action();

		$per_page    = $this->get_items_per_page( 'posts_per_page', 20 );
		$total_items = self::count_items();

		$this->set_pagination_args(
			[
				'total_items' => $total_items, // WE have to calculate the total number of items.
				'per_page'    => $per_page, // WE have to determine how many items to show on a page.
			]
		);

		$current_page = $this->get_pagenum();

		$this->items = self::get_posts( $per_page, $current_page );
	}
}
