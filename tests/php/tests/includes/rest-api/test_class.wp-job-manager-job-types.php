<?php

class WP_Test_WP_Job_Manager_Job_Types_Test extends WPJM_REST_TestCase {

	/**
	 * @group rest
	 */
	function test_get_job_types_success() {
		$this->markTestSkipped( 'Skipping for now' );
		$this->login_as( $this->default_user_id );
		$response = $this->get( '/wp/v2/job-types' );
		$this->assertResponseStatus( $response, 200 );
	}
}