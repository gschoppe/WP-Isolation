<?php if(!defined('ABSPATH')) { die(); } // Include in all php files, to prevent direct execution
/*
Plugin Name: WP Isolate Post Types
Plugin URI: https://www.gschoppe.com
Description: Isolate specific post types to dedicated db tables
Version: 0.1.0
Author: Greg Schoppe
Author URI: https://www.gschoppe.com
*/

if( !class_exists('WPIsolatePostTypes') ) {
	class WPIsolatePostTypes {
		private $int_min;
		private $int_max;
		private $isolations            = array();
		private $post_types_map        = array();
		private $taxonomies_map        = array();
		private $current_sandbox       = false;
		private $forced_sandbox        = false;

		public static function Init( $post_types = '', $taxonomies = '' ) {
			static $instance = null;
			if ($instance === null) {
				$instance = new self();
			}
			if( $post_types ) {
				$instance->register( $post_types, $taxonomies );
			}
			return $instance;
		}

		private function __construct() {

			$this->int_min = -1 * PHP_INT_MAX;
			$this->int_max = PHP_INT_MAX;

			// SECTION: POST_TYPE HOOKS
			// archive pages
			add_action( 'pre_get_posts'         , array( $this, 'pre_get_posts'          ), $this->int_max );
			add_filter( 'found_posts'           , array( $this, 'reset_sandbox_filter'   ), $this->int_min );
			// admin edit page
			add_action( 'load-edit.php'         , array( $this, 'load_edit_php'          ), $this->int_min );
			// post edit page
			add_action( 'load-post.php'         , array( $this, 'load_post_php'          ), $this->int_min );
			add_action( 'load-post-new.php'     , array( $this, 'load_post_php'          ), $this->int_min );
			add_action( 'load-post-edit.php'    , array( $this, 'load_post_php'          ), $this->int_min );
			// post update
			add_filter( 'wp_insert_post_data'   , array( $this, 'wp_insert_post_data'    ), $this->int_min, 2 ); // <- this might be unnecessary
			// front end setup for loop
			add_action( 'the_post'              , array( $this, 'the_post'               ), $this->int_min );
			// add post_type to $_GET attributes
			add_filter( 'redirect_post_location', array( $this, 'redirect_post_location' ), $this->int_max );
			add_filter( 'get_edit_post_link'    , array( $this, 'get_post_link'          ), $this->int_max, 3 );
			add_filter( 'get_delete_post_link'  , array( $this, 'get_post_link'          ), $this->int_max, 3 );
			add_filter( 'admin_url'             , array( $this, 'admin_url'              ), $this->int_max, 3 );

			// SECTION: TAXONOMY HOOKS
			add_action( 'load-edit-tags.php'    , array( $this, 'load_terms_php'         ), $this->int_min );
			add_action( 'load-term.php'         , array( $this, 'load_terms_php'         ), $this->int_min );
			add_filter( 'pre_insert_term'       , array( $this, 'pre_term'               ), $this->int_min, 2 );
			add_action( 'edited_terms'          , array( $this, 'post_term'              ), $this->int_max, 2 );
			add_action( 'created_term'          , array( $this, 'post_term'              ), $this->int_max, 2 );
			add_action( 'pre_delete_term'       , array( $this, 'pre_term'               ), $this->int_min, 2 );
			add_action( 'delete_term'           , array( $this, 'post_term'              ), $this->int_max, 2 );

			// SECTION: AJAX ACTIONS
			add_action( 'wp_ajax_add-tag'            , array( $this, 'ajax_action' ), $this->int_min );
			add_action( 'wp_ajax_delete-tag'         , array( $this, 'ajax_action' ), $this->int_min );
			add_action( 'wp_ajax_get-tagcloud'       , array( $this, 'ajax_action' ), $this->int_min );
			add_action( 'wp_ajax_get-permalink'      , array( $this, 'ajax_action' ), $this->int_min );
			add_action( 'wp_ajax_sample-permalink'   , array( $this, 'ajax_action' ), $this->int_min );
			add_action( 'wp_ajax_inline-save'        , array( $this, 'ajax_action' ), $this->int_min );
			add_action( 'wp_ajax_inline-save-tax'    , array( $this, 'ajax_action' ), $this->int_min );

			// TODO: big important question... do we isolate associated media as well?
			// add_action( 'wp_ajax_set-post-thumbnail' , array( $this, 'ajax_action'        ), $this->int_min );
			// add_action( 'get-post-thumbnail-html'    , array( $this, 'ajax_action_revert' ), $this->int_min )
			/* additional potential ajax to handle
			'delete-comment'
			'delete-meta', 'delete-post', 'trash-post', 'untrash-post', 'delete-page', 'dim-comment', 'get-comments', 'replyto-comment',
			'edit-comment' 'add-meta', 'find_posts',
			'wp-remove-post-lock', 'get-attachment', 'query-attachments', 'save-attachment', 'save-attachment-compat', 'send-link-to-editor',
			'send-attachment-to-editor', 'save-attachment-order', 'get-revision-diffs',
			'set-attachment-thumbnail', 'parse-media-shortcode'
			*/

			// SECTION: SHORTCODES
			add_shortcode( 'sandbox' , array( $this, 'sandbox_shortcode' ) );

			// SECTION: API HOOKS
			// TODO: COMPLETELY UNTOUCHED

			// SECTION: META FUNCTIONS
			// TODO: COMPLETELY UNTOUCHED

			// SECTION: HELPERS
			// TODO: build a chain of currently applied sandboxes, so that you can run
			//       a helper that will add a level or remove one from the chain. That
			//       way we can nest sandbox calls however deep we need.

		}

		public function register( $post_types, $taxonomies = null, $prefix = null ) {
			$object = $this->build_isolation_object( $post_types, $taxonomies );
			if( empty( $prefix ) ) {
				$prefix = $this->khash( $object );
			}
			if( $this->has_overlap( $object ) || isset( $this->isolations[ $prefix ] ) ) {
				return false; // cant reinitialize same post_type or taxonomy in two sandboxes
			}
			$this->isolations[ $prefix ] = $object;
			foreach( $object['post_types'] as $post_type ) {
				$this->post_types_map[$post_type] = $prefix;
			}
			foreach( $object['taxonomies'] as $taxonomy ) {
				$this->taxonomies_map[$taxonomy] = $prefix;
			}
			if( !$this->has_sandbox( $prefix ) ) {
				$this->build_isolated_sandbox( $prefix );
			}
		}


		// HOOKS
		public function pre_get_posts( $query ) {
			$post_type = 'post';
			$post_type = $query->get( 'post_type' );
			if( $post_type ) {
				if( !is_array( $post_type ) ) {
					$post_type = array_map( 'trim', explode( ',', $post_type ) );
				}
				if( count( $post_type ) == 1 ) {
					$post_type = $post_type[0];
				} else {
					return;
				}
				if( isset( $this->post_types_map[$post_type] ) ) {
					$sandbox = $this->post_types_map[$post_type];
					if( $query->is_main_query() ) {
						$this->set_sandbox( $sandbox, true );
					} else {
						$this->set_sandbox( $sandbox );
					}
				}
			}
		}

		public function load_edit_php() {
			$screen = get_current_screen();
			if( !empty( $screen->post_type ) && isset( $this->post_types_map[$screen->post_type] ) ) {
				if( 'edit' === $screen->base ) {
					$this->set_sandbox( $this->post_types_map[$screen->post_type], true );
				}
			}
		}

		public function load_terms_php() {
			$screen = get_current_screen();
			if( !empty( $screen->taxonomy ) && isset( $this->taxonomies_map[$screen->taxonomy] ) ) {
					$this->set_sandbox( $this->taxonomies_map[$screen->taxonomy], true );
			}
		}

		public function load_post_php() {
			$screen = get_current_screen();
			if( !empty( $screen->post_type ) ) {
				if( isset( $this->post_types_map[$screen->post_type] ) ) {
					$this->set_sandbox( $this->post_types_map[$screen->post_type], true );
				}
			}
		}

		public function pre_term( $term, $taxonomy ) {
			if( isset( $this->taxonomies_map[$taxonomy] ) ) {
					$this->set_sandbox( $this->taxonomies_map[$taxonomy] );
			}
			return $term;
		}

		public function post_term( $term, $taxonomy ) {
			$this->reset_sandbox();
			return $term;
		}

		public function redirect_post_location( $location ) {
			if( !empty( $_POST['action'] ) && $_POST['action'] == 'editpost' ) {
				if( !empty( $_POST['post_type'] ) ) {
					$location = add_query_arg( 'post_type', $_POST['post_type'], $location  );
				}
			}
			return $location;
		}

		public function get_post_link( $link, $post_id, $context ) {
			$post_type = get_post_type( $post_id );
			if( strpos( $link, 'post_type=' ) === false ) {
				$link = add_query_arg( 'post_type', $post_type, $link );
			}
			return $link;
		}

		public function admin_url( $url, $path, $blog_id ) {
			if( !$this->in_sandbox() ) {
				return $url;
			}
			if( strpos( $path, 'post.php' ) === 0 && strpos( $path, '&post_type=' ) === false ) {
				$url = add_query_arg( 'post_type', get_post_type(), $url );
			}
			return $url;
		}

		public function the_post( $post ) {
			if( isset( $this->post_types_map[$post->post_type] ) ) {
				$this->set_sandbox( $this->post_types_map[$screen->post_type] );
			} else {
				$this->reset_sandbox();
			}
		}

		public function wp_insert_post_data( $data, $postarr ) {
			if( !empty( $data['post_type'] ) && isset( $this->post_types_map[ $data['post_type'] ] ) ) {
				$this->set_sandbox( $this->post_types_map[ $data['post_type'] ], true );
			}
			return $data;
		}

		public function ajax_action() {
			$data = $_REQUEST;
			if( !empty( $data['post_type'] ) && isset( $this->post_types_map[ $data['post_type'] ] ) ) {
				$this->set_sandbox( $this->post_types_map[ $data['post_type'] ], true );
			}elseif( !empty( $data['taxonomy'] ) && isset( $this->taxonomies_map[ $data['taxonomy'] ] ) ) {
				$this->set_sandbox( $this->taxonomies_map[ $data['taxonomy'] ], true );
			}
		}

		public function sandbox_shortcode( $atts, $content = '' ) {
			global $wpdb;
			$atts = shortcode_atts( array(
				'name' => ''
			), $atts );
			$this->change_sandbox( $atts['name'] );
			$output = do_shortcode( $content );
			$this->change_sandbox( $this->current_sandbox );
		}

		public function in_sandbox( $sandbox='' ) {
			if( !$this->current_sandbox ) {
				return false;
			} elseif( $sandbox && $sandbox != $this->current_sandbox ){
				return false;
			}
			return true;
		}

		public function set_sandbox( $sandbox, $force = false ) {
			if( $force || !$this->forced_sandbox ) {
				$this->current_sandbox = $sandbox;
				$this->change_sandbox( $sandbox );
				if( $force ) {
					$this->forced_sandbox = true;
				}
			}
		}

		public function reset_sandbox( $force = false ) {
			global $wpdb;
			if( $this->backup_prefix && ( $force || !$this->forced_sandbox ) ) {
				$this->change_sandbox( "" );
				$this->current_sandbox = false;
			}
			if( $force ) {
				$this->forced_sandbox = false;
			}
		}

		public function reset_sandbox_filter( $v ) {
			$this->reset_sandbox();
			return $v;
		}

		// PRIVATE FUNCTIONS
		private function khash( $data ) {
			$data  = json_encode( $data );
			$crc32 = floatval( sprintf( '%u', crc32( $data ) ) );
			$hash  = $this->to_base( $crc32, 62 );
			return $hash;
		}

		private function to_base( $num, $b = 62 ) {
			$base = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
			$r    = $num % $b ;
			$res  = $base[$r];
			$q    = floor( $num / $b );
			while( $q ) {
				$r   = $q % $b;
				$q   = floor( $q / $b );
				$res = $base[$r] . $res;
			}
			return $res;
		}

		private function build_isolation_object( $post_types, $taxonomies = null ) {
			if( !is_array( $post_types ) ) {
				$post_types = array( $post_types );
			}
			if( empty( $taxonomies ) ) {
				$taxonomies = array();
			} elseif( !is_array( $taxonomies ) ) {
				$taxonomies = array( $taxonomies );
			}
			return array(
				'post_types' => $post_types,
				'taxonomies' => $taxonomies
			);
		}

		private function has_overlap( $object ) {
			$registered_post_types = array_keys( $this->post_types_map );
			$registered_taxonomies = array_keys( $this->taxonomies_map );
			if( !empty( array_intersect( $registered_post_types, $object['post_types'] ) ) ) {
				return true;
			}
			if( !empty( array_intersect( $registered_taxonomies, $object['taxonomies'] ) ) ) {
				return true;
			}
			return false;
		}

		private function has_sandbox( $prefix ) {
			// TODO: Improve this to remove the check on every page load
			//       and to use portable ORM SQL commands, rather than
			//       MySQL/MariaDB Proprietary commands.
			global $wpdb;
			$test_table = $wpdb->base_prefix . $prefix . '_posts';
			$query = 'SHOW TABLES LIKE "' . $test_table . '";';
			$result = $wpdb->get_results( $query );
			return !empty( $result );
		}

		private function build_isolated_sandbox( $prefix ) {
			global $wpdb;
			$old_prefix = $wpdb->set_prefix( $prefix, true );
			$prefix_len = strlen( "CREATE TABLE " . $wpdb->prefix . '_' );
			$blacklist = array(
				'links',
				'options'
			);
			require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
			$raw_blog_tables = explode(';', wp_get_db_schema( 'blog', $replace_me ) );
			$raw_blog_tables = array_filter( array_map( 'trim', $raw_blog_tables ) );
			$sql_commands = array();
			foreach( $raw_blog_tables as $table ) {
				foreach( $blacklist as $banned_table ) {
					$table_test = substr( $table, $prefix_len, strlen( $banned_table ) );
					if( $table_test == $banned_table ) {
						continue 2;
					}
				}
				$sql_commands[] = $table;
			}
			$wpdb->set_prefix( $old_prefix, true );
			dbDelta( $sql_commands );
		}

		private function change_sandbox( $sandbox ) {
			global $wpdb;
			$old_prefix = $wpdb->base_prefix;
			$wpdb->base_prefix = $wpdb->base_prefix . $sandbox . '_';
			$tables = $wpdb->tables( 'blog' );
			unset( $tables['options'] );
			unset( $tables['links'] );
			foreach( $tables as $table => $prefixed_table ) {
				$wpdb->$table = $prefixed_table;
			}
			$wpdb->base_prefix = $old_prefix;
		}

	}
	WPIsolatePostTypes::Init();
}
