<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP_Job_Manager_Helper_API
 */
class WP_Job_Manager_Helper_API {

	private static $api_url = 'https://wpjobmanager.com/';

	/**
	 * Make a licence helper API request.
	 *
	 * @param array $args
	 * @param bool  $return_error
	 *
	 * @return array|bool|mixed|object
	 */
	private static function request( $args, $return_error = false ) {
		$defaults = array(
			'instance'       => self::get_site_url(),
			'plugin_name'    => '',
			'version'        => '',
			'api_product_id' => '',
			'licence_key'    => '',
			'email'          => '',
		);

		$args    = wp_parse_args( $args, $defaults );
		$request = wp_safe_remote_get( self::$api_url . '?' . http_build_query( $args, '', '&' ), array(
			'timeout' => 10,
			'headers' => array(
				'Accept' => 'application/json',
			),
		) );

		if ( is_wp_error( $request ) || 200 !== wp_remote_retrieve_response_code( $request ) ) {
			if ( $return_error ) {
				if ( is_wp_error( $request ) ) {
					return array(
						'error_code' => $request->get_error_code(),
						'error' => $request->get_error_message(),
					);
				}
				return array(
					'error_code' => wp_remote_retrieve_response_code( $request ),
					'error' => 'Error code: ' . wp_remote_retrieve_response_code( $request ),
				);
			}
			return false;
		}

		$response = @json_decode( wp_remote_retrieve_body( $request ), true );

		if ( is_array( $response ) ) {
			return $response;
		}

		return false;
	}

	/**
	 * Sends and receives data to and from the server API
	 *
	 * @param array|string $args
	 * @return object|bool $response
	 */
	public static function plugin_update_check( $args ) {
		$args = wp_parse_args( $args );
		$args['wc-api']  = 'wp_plugin_licencing_update_api';
		$args['request'] = 'pluginupdatecheck';
		return self::request( $args );
	}

	/**
	 * Sends and receives data to and from the server API
	 *
	 * @param array|string $args
	 * @return object $response
	 */
	public static function plugin_information( $args ) {
		$args = wp_parse_args( $args );
		$args['wc-api']  = 'wp_plugin_licencing_update_api';
		$args['request'] = 'plugininformation';
		return self::request( $args );
	}

	/**
	 * Attempt to activate a plugin licence.
	 *
	 * @param array|string $args
	 * @return boolean|string JSON response or false if failed.
	 */
	public static function activate( $args ) {
		$args = wp_parse_args( $args );
		$args['wc-api']  = 'wp_plugin_licencing_activation_api';
		$args['request'] = 'activate';
		$response = self::request( $args, true );
		if ( false === $response ) {
			return false;
		}
		return $response;
	}

	/**
	 * Attempt to deactivate a plugin licence.
	 *
	 * @param array|string $args
	 * @return boolean|string JSON response or false if failed.
	 */
	public static function deactivate( $args ) {
		$args = wp_parse_args( $args );
		$args['wc-api']  = 'wp_plugin_licencing_activation_api';
		$args['request'] = 'activate';
		$response = self::request( $args, false );
		if ( false === $response ) {
			return false;
		}
		return $response;
	}

	/**
	 * Returns the site URL that is MU safe.
	 *
	 * @return string
	 */
	private static function get_site_url() {
		if ( is_multisite() || is_network_admin() ) {
			return network_site_url();
		}
		return site_url();
	}
}
