<?php
/**
 * Declaration of Job Types Custom Fields Model
 *
 * @package WPJM/REST
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_Job_Manager_Models_Job_Types_Custom_Fields
 */
class WP_Job_Manager_Models_Job_Types_Custom_Fields extends WP_Job_Manager_REST_Model {
	/**
	 * Declare Fields
	 *
	 * @return array
	 */
	public function declare_fields() {
		$env = $this->get_environment();
		$employment_types = wpjm_job_listing_employment_type_options();
		return array(
			$env->field( 'employment_type', __( 'Employment Type', 'wp-job-manager' ) )
				->with_kind( WP_Job_Manager_REST_Field_Declaration::META )
				->with_type( $env->type( 'string' ) )
				->with_choices( array_keys( $employment_types ) ),
		);
	}
}
