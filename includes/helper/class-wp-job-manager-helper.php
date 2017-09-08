<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Handles Job Manager's Ajax endpoints.
 *
 * @package wp-job-manager
 * @since 1.29.0
 */
class WP_Job_Manager_Helper {
	/**
	 * @var array Messages when updating licences.
	 */
	private static $licence_messages = array();

	/**
	 * Loads the class, runs on init.
	 */
	public static function init() {
		include_once( 'class-wp-job-manager-helper-options.php' );
		include_once( 'class-wp-job-manager-helper-api.php' );

		add_action( 'job_manager_helper_output', array( __CLASS__, 'licence_output' ) );

		add_filter( 'extra_plugin_headers', array( __CLASS__, 'extra_headers' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'plugins_api' ), 20, 3 );
		add_action( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'check_for_updates' ) );

		add_action( 'activated_plugin', array( __CLASS__, 'plugin_activated' ) );
		add_action( 'deactivated_plugin', array( __CLASS__, 'plugin_deactivated' ) );
		add_action( 'admin_init', array( __CLASS__, 'admin_init' ) );
	}

	/**
	 * Initializes admin-only actions.
	 */
	public static function admin_init() {
		add_action( 'plugin_action_links', array( __CLASS__, 'plugin_links' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'licence_error_notices' ) );
		self::handle_admin_request();
	}

	/**
	 * Handles special tasks on admin requests.
	 */
	private static function handle_admin_request() {
		if ( ! empty( $_GET['dismiss-wpjm-licence-notice'] ) ) {
			$product_plugins = self::get_installed_plugins();
			$product_slug = sanitize_text_field( $_GET['dismiss-wpjm-licence-notice'] );
			if ( isset( $product_plugins[ $product_slug ] ) ) {
				WP_Job_Manager_Helper_Options::update( $product_slug, 'hide_key_notice', true );
			}
		}
	}

	/**
	 * Check for licence managed WPJM addon plugin updates.
	 *
	 * @param array $check_for_updates_data
	 *
	 * @return array
	 */
	public static function check_for_updates( $check_for_updates_data ) {
		// Set version variables
		foreach ( self::get_installed_plugins() as $product_slug => $plugin_data ) {
			if ( $response = self::get_plugin_version( $plugin_data['_filename'] ) ) {
				// If there is a new version, modify the transient to reflect an update is available
				if ( $response !== false && isset( $response['new_version'] ) && version_compare( $response['new_version'], $plugin_data['Version'], '>' ) ) {
					$check_for_updates_data->response[ $plugin_data['_filename'] ] = (object) $response;
				}
			}
		}
		return $check_for_updates_data;
	}

	/**
	 * Get plugin version info from API.
	 *
	 * @param string $plugin_filename
	 *
	 * @return array|bool
	 */
	private static function get_plugin_version( $plugin_filename ) {
		$plugin_data = self::get_licence_managed_plugin( $plugin_filename );
		if ( ! $plugin_data ) {
			return false;
		}
		$product_slug = $plugin_data['_product_slug'];
		$licence = self::get_plugin_licence( $product_slug );
		if ( ! $licence || empty( $licence['licence_key'] ) ) {
			return false;
		}

		$response = WP_Job_Manager_Helper_API::plugin_update_check( array(
			'plugin_name'    => $plugin_data['Name'],
			'version'        => $plugin_data['Version'],
			'api_product_id' => $product_slug,
			'licence_key'    => $licence['licence_key'],
			'email'          => $licence['email'],
		) );

		if ( isset( $response['errors'] ) ) {
			self::handle_api_errors( $product_slug, $response['errors'] );
		}

		// Set version variables
		if ( ! empty( $response ) ) {
			return $response;
		}

		return false;
	}

	/**
	 * Cleanup old things when WPJM licence managed plugin is activated.
	 *
	 * @param string $plugin_filename
	 */
	public static function plugin_activated( $plugin_filename ) {
		$plugins = self::get_installed_plugins( false );
		foreach ( $plugins as $product_slug => $plugin_data ) {
			if ( $plugin_filename !== $plugin_data['_filename'] ) {
				continue;
			}
			WP_Job_Manager_Helper_Options::delete( $product_slug, 'hide_key_notice' );
			break;
		}
	}

	/**
	 * Deactivate licence when WPJM licence managed plugin is deactivated.
	 *
	 * @param string $plugin_filename
	 */
	public static function plugin_deactivated( $plugin_filename ) {
		$plugins = self::get_installed_plugins( false );
		foreach ( $plugins as $product_slug => $plugin_data ) {
			if ( $plugin_filename !== $plugin_data['_filename'] ) {
				continue;
			}
			self::deactivate_licence( $product_slug );
			break;
		}
	}

	/**
	 * Fetches the plugin information for WPJM plugins.
	 *
	 * @param false|object|array  $response  The result object or array. Default false.
	 * @param string              $action    The type of information being requested from the Plugin Install API.
	 * @param object              $args      Plugin API arguments.
	 *
	 * @return false|object|array
	 */
	public static function plugins_api( $response, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $response;
		}

		if ( empty( $args->slug ) ) {
			return $response;
		}

		if ( ( $plugin_info = self::get_plugin_info( $args->slug ) ) ) {
			$response = $plugin_info;
		}

		if ( isset( $response['errors'] ) ) {
			self::handle_api_errors( $args->slug, $response['errors'] );
		}

		return $response;
	}

	/**
	 * Appends links to manage plugin licence when managed.
	 *
	 * @param array  $actions
	 * @param string $plugin_filename
	 * @return array
	 */
	public static function plugin_links( $actions, $plugin_filename ) {
		$plugin = self::get_licence_managed_plugin( $plugin_filename );
		if ( ! $plugin || ! current_user_can( 'update_plugins' ) ) {
			return $actions;
		}
		$product_slug = $plugin['_product_slug'];
		$licence = self::get_plugin_licence( $product_slug );
		$css_class = '';
		if ( $licence && ! empty( $licence['licence_key'] ) ) {
			$manage_licence_label = __( 'Manage License', 'wp-job-manager' );
		} else {
			$manage_licence_label = __( 'Activate License', 'wp-job-manager' );
			$css_class = 'wpjm-activate-licence-link';
		}
		$actions[] = '<a class="' . $css_class . '" href="' . esc_url( admin_url( 'edit.php?post_type=job_listing&page=job-manager-addons&section=helper' )  ) . '">' . $manage_licence_label . '</a>';
		return $actions;
	}

	/**
	 * Returns the plugin info for a licenced WPJM plugin.
	 *
	 * @param string $product_slug
	 *
	 * @return bool|object
	 */
	private static function get_plugin_info( $product_slug ) {
		if ( ! self::is_product_installed( $product_slug ) ) {
			return false;
		}
		$args = self::get_plugin_licence( $product_slug );
		if ( empty( $args['licence_key'] ) || empty( $args['email'] ) ) {
			return false;
		}
		$args['api_product_id'] = $product_slug;

		$response = WP_Job_Manager_Helper_API::plugin_information( $args );
		if ( isset( $response['errors'] ) ) {
			self::handle_api_errors( $product_slug, $response['errors'] );
		}

		return $response;
	}

	/**
	 * Checks if a WPJM plugin is installed.
	 *
	 * @param string $product_slug
	 *
	 * @return bool
	 */
	public static function is_product_installed( $product_slug ) {
		$product_plugins = self::get_installed_plugins();
		return isset( $product_plugins[ $product_slug ] );
	}

	/**
	 * Returns true if there are licensed products being managed.
	 *
	 * @return bool
	 */
	public static function has_licenced_products() {
		$product_plugins = self::get_installed_plugins();
		return ! empty( $product_plugins );
	}

	/**
	 * Returns the plugin data for plugin with a `WPJM-Product` tag by plugin filename.
	 *
	 * @param $plugin_filename
	 * @return bool|array
	 */
	private static function get_licence_managed_plugin( $plugin_filename ) {
		foreach ( self::get_installed_plugins() as $plugin ) {
			if ( $plugin_filename === $plugin['_filename'] ) {
				return $plugin;
			}
		}
		return false;
	}

	/**
	 * Gets the licence key and email for a WPJM managed plugin.
	 *
	 * @param string $product_slug
	 * @return array|bool
	 */
	public static function get_plugin_licence( $product_slug ) {
		$licence_key = WP_Job_Manager_Helper_Options::get( $product_slug, 'licence_key' );
		$activation_email = WP_Job_Manager_Helper_Options::get( $product_slug, 'email' );
		$errors = WP_Job_Manager_Helper_Options::get( $product_slug, 'errors' );

		return array(
			'licence_key' => $licence_key,
			'email' => $activation_email,
			'errors' => $errors,
		);
	}

	/**
	 * Adds newly recognized data header in WordPress plugin files.
	 *
	 * @params array $headers
	 * @return array
	 */
	public static function extra_headers( $headers ) {
		$headers[] = 'WPJM-Product';
		return $headers;
	}

	/**
	 * Returns list of installed WPJM plugins with managed licences indexed by product ID.
	 *
	 * @param bool $active_only Only return active plugins
	 * @return array
	 */
	private static function get_installed_plugins( $active_only = true ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
		}

		$wpjm_plugins = array();
		$plugins = get_plugins();

		foreach ( $plugins as $filename => $data ) {
			if ( empty( $data['WPJM-Product'] ) || ( true === $active_only && ! is_plugin_active( $filename ) ) ) {
				continue;
			}

			$data['_filename'] = $filename;
			$data['_product_slug'] = $data['WPJM-Product'];
			$data['_type'] = 'plugin';
			$wpjm_plugins[ $data['WPJM-Product'] ] = $data;
		}

		return $wpjm_plugins;
	}

	/**
	 * Outputs the licence management.
	 */
	public static function licence_output() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		if ( ! empty( $_POST ) ) {
			self::handle_request();
		}
		$licenced_plugins = self::get_installed_plugins();
		include_once( dirname( __FILE__ ) . '/views/html-licences.php' );
	}

	/**
	 * Outputs unset licence key notices.
	 */
	public static function licence_error_notices() {
		foreach( self::get_installed_plugins() as $product_slug => $plugin_data ) {
			$licence = self::get_plugin_licence( $product_slug );
			if ( empty( $licence['licence_key'] ) && ! WP_Job_Manager_Helper_Options::get( $product_slug, 'hide_key_notice' ) ) {
				include( 'views/html-licence-key-notice.php' );
			}
		}
	}

	/**
	 * Handles a request on the manage licence key screen.
	 */
	private static function handle_request() {
		$licenced_plugins = self::get_installed_plugins();
		if ( empty( $_POST )
			 || empty( $_POST['_wpnonce'] )
			 || empty( $_POST['action'] )
			 || empty( $_POST['product_slug'] )
			 || ! isset( $licenced_plugins[ $_POST['product_slug'] ] )
			 || ! wp_verify_nonce( $_POST['_wpnonce'], 'wpjm-manage-licence' )
		) {
			return false;
		}
		$product_slug = sanitize_text_field( $_POST['product_slug'] );
		switch ( $_POST['action'] ) {
			case 'activate':
				if ( empty( $_POST['email'] ) || empty( $_POST['licence_key'] ) ) {
					self::add_error( $product_slug, __( 'Please enter a valid license key and email address in order to activate this plugin\'s license.', 'wp-job-manager' ) );
					break;
				}
				$email = sanitize_email( $_POST['email'] );
				$licence_key = sanitize_text_field( $_POST['licence_key'] );
				self::activate_licence( $product_slug, $licence_key, $email );
				break;
			case 'deactivate':
				self::deactivate_licence( $product_slug );
				break;
		}
	}

	/**
	 * Activate a licence key for a WPJM add-on plugin.
	 *
	 * @param string $product_slug
	 * @param string $licence_key
	 * @param string $email
	 */
	private static function activate_licence( $product_slug, $licence_key, $email ) {
		$response = WP_Job_Manager_Helper_API::activate( array(
			'api_product_id' => $product_slug,
			'licence_key' => $licence_key,
			'email' => $email,
		) );

		if ( false === $response ) {
			self::add_error( $product_slug, __( 'Connection failed to the License Key API server - possible server issue.', 'wp-job-manager' ) );
		} elseif ( isset( $response['error_code'] ) && isset( $response['error'] ) ) {
			self::add_error( $product_slug, $response['error'] );
		} elseif ( ! empty( $response['activated'] ) ) {
			WP_Job_Manager_Helper_Options::update( $product_slug, 'licence_key', $licence_key );
			WP_Job_Manager_Helper_Options::update( $product_slug, 'email', $email );
			WP_Job_Manager_Helper_Options::delete( $product_slug, 'errors' );
			WP_Job_Manager_Helper_Options::delete( $product_slug, 'hide_key_notice' );
			self::add_success( $product_slug, __( 'Plugin license has been activated.', 'wp-job-manager' ) );
		} else {
			self::add_error( $product_slug, __( 'An unknown error occurred while attempting to activate the license', 'wp-job-manager' ) );
		}
	}

	/**
	 * Deactivate a licence key for a WPJM add-on plugin.
	 *
	 * @param string $product_slug
	 */
	private static function deactivate_licence( $product_slug ) {
		$licence = self::get_plugin_licence( $product_slug );
		if ( empty( $licence['licence_key'] ) || empty( $licence['email'] ) ) {
			self::add_error( $product_slug, __( 'licence is not active.', 'wp-job-manager' ) );
			return;
		}
		WP_Job_Manager_Helper_API::deactivate( array(
			'api_product_id' => $product_slug,
			'licence_key' => $licence['licence_key'],
			'email' => $licence['email'],
		) );

		WP_Job_Manager_Helper_Options::delete( $product_slug, 'licence_key' );
		WP_Job_Manager_Helper_Options::delete( $product_slug, 'email' );
		WP_Job_Manager_Helper_Options::delete( $product_slug, 'errors' );
		WP_Job_Manager_Helper_Options::delete( $product_slug, 'hide_key_notice' );
		delete_site_transient( 'update_plugins' );
		self::add_success( $product_slug, __( 'Plugin license has been deactivated.', 'wp-job-manager' ) );
	}

	/**
	 * Handle errors from the API.
	 *
	 * @param  string $product_slug
	 * @param  array  $errors
	 */
	private static function handle_api_errors( $product_slug, $errors ) {
		$plugin_products = self::get_installed_plugins();
		if ( ! isset( $plugin_products[ $product_slug ] ) ) {
			return;
		}
		$plugin_data = $plugin_products[ $product_slug ];
		if ( ! empty( $errors['no_activation'] ) ) {
			self::deactivate_licence( $product_slug );
			self::add_licence_error( $product_slug, $errors['no_activation'] );
		} elseif ( ! empty( $errors['expired_key'] ) ) {
			self::deactivate_licence( $product_slug );
			self::add_licence_error( $product_slug, $errors['expired_key'] );
		}
	}

	/**
	 * Add an error message for a licence.
	 *
	 * @param string $product_slug
	 * @param string $message      Your error message
	 * @param string $type         Type of error message
	 */
	private static function add_licence_error( $product_slug, $message, $type = '' ) {
		$licence = self::get_plugin_licence( $product_slug );
		$errors = ! empty( $licence['errors'] ) ? $licence['errors'] : array();
		if ( $type ) {
			$errors[ $type ] = $message;
		} else {
			$errors[] = $message;
		}
		WP_Job_Manager_Helper_Options::update( $product_slug, 'errors', $errors );
	}

	/**
	 * Add an error message.
	 *
	 * @param string $product_slug The plugin slug.
	 * @param string $message Your error message.
	 */
	private static function add_error( $product_slug, $message ) {
		self::add_message( 'error', $product_slug, $message );
	}

	/**
	 * Add a success message.
	 *
	 * @param string $product_slug The plugin slug.
	 * @param string $message Your error message.
	 */
	private static function add_success( $product_slug, $message ) {
		self::add_message( 'success', $product_slug, $message );
	}

	/**
	 * Add a message.
	 *
	 * @param string $type Message type.
	 * @param string $product_slug The plugin slug.
	 * @param string $message Your error message.
	 */
	private static function add_message( $type, $product_slug, $message ) {
		if ( ! isset( self::$licence_messages[ $product_slug ] ) ) {
			self::$licence_messages[ $product_slug ] = array();
		}
		self::$licence_messages[ $product_slug ][] = array(
			'type' => $type,
			'message' => $message,
		);
	}

	/**
	 * Get a plugin's licence messages.
	 *
	 * @param string $product_slug The plugin slug.
	 * @return array
	 */
	public static function get_messages( $product_slug ) {
		if ( ! isset( self::$licence_messages[ $product_slug ] ) ) {
			self::$licence_messages[ $product_slug ] = array();
		}
		return self::$licence_messages[ $product_slug ];
	}
}

WP_Job_Manager_Helper::init();
