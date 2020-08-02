<?php
/**
 * CoCart Server
 *
 * Responsible for loading the REST API and all REST API namespaces.
 *
 * @author   Sébastien Dumont
 * @category API
 * @package  CoCart/API
 * @since    1.0.0
 * @version  3.0.0
 * @license  GPL-2.0+
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CoCart REST API class.
 */
class CoCart_Server {

	/**
	 * Setup class.
	 *
	 * @access  public
	 * @since   1.0.0
	 * @version 3.0.0
	 */
	public function __construct() {
		// REST API was included starting WordPress 4.4.
		if ( ! class_exists( 'WP_REST_Server' ) ) {
			return;
		}

		// If WooCommerce does not exists then do nothing!
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		// Include REST API Controllers. - ONLY works if hooked to `wp_loaded` !!!
		add_action( 'wp_loaded', array( $this, 'rest_api_includes' ), 5 );

		// Hook into WordPress ready to init the REST API as needed.
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ), 10 );
	} // END __construct()

	/**
	 * Register REST API routes.
	 *
	 * @access public
	 */
	public function register_rest_routes() {
		foreach ( $this->get_rest_namespaces() as $namespace => $controllers ) {
			foreach ( $controllers as $controller_name => $controller_class ) {
				if ( class_exists( $controller_class ) ) {
					$this->controllers[ $namespace ][ $controller_name ] = new $controller_class();
					$this->controllers[ $namespace ][ $controller_name ]->register_routes();
				}
			}
		}
	}

	/**
	 * Get API namespaces - new namespaces should be registered here.
	 *
	 * @access protected
	 * @return array List of Namespaces and Main controller classes.
	 */
	protected function get_rest_namespaces() {
		return apply_filters(
			'cocart_rest_api_get_rest_namespaces',
			array(
				'wc/v2'     => $this->get_legacy_controller(),
				'cocart/v1' => $this->get_v1_controllers(),
				'cocart/v2' => $this->get_v2_controllers(),
			)
		);
	}

	/**
	 * List of controllers in the wc/v2 namespace.
	 *
	 * @access protected
	 * @return array
	 */
	protected function get_legacy_controller() {
		return array(
			'WC_REST_Cart_Controller'
		);
	}

	/**
	 * List of controllers in the cocart/v1 namespace.
	 *
	 * @access protected
	 * @return array
	 */
	protected function get_v1_controllers() {
		return array(
			'CoCart_API_Controller',
			'CoCart_Add_Item_Controller',
			'CoCart_Clear_Cart_Controller',
			'CoCart_Calculate_Controller',
			'CoCart_Count_Items_Controller',
			'CoCart_Item_Controller',
			'CoCart_Logout_Controller',
			'CoCart_Totals_Controller'
		);
	}

	/**
	 * List of controllers in the cocart/v2 namespace.
	 *
	 * @access protected
	 * @return array
	 */
	protected function get_v2_controllers() {
		return array(
			'CoCart_Store_V2_Controller',
			'CoCart_Cart_V2_Controller',
			'CoCart_Add_Item_v2_Controller',
			'CoCart_Clear_Cart_v2_Controller',
			'CoCart_Calculate_v2_Controller',
			'CoCart_Count_Items_v2_Controller',
			'CoCart_Update_Item_v2_Controller',
			'CoCart_Remove_Item_v2_Controller',
			'CoCart_Restore_Item_v2_Controller',
			'CoCart_Logout_v2_Controller',
			'CoCart_Totals_v2_Controller'
		);
	}

	/**
	 * Loads the cart, session and notices should it be required.
	 * 
	 * Note: Only needed should the site be running WooCommerce 3.6 
	 * or higher as they are not included during a REST request.
	 *
	 * @access  private
	 * @since   2.0.0
	 * @version 2.3.0
	 */
	private function maybe_load_cart() {
		if ( CoCart_Helpers::is_wc_version_gte_3_6() && CoCart_Helpers::is_rest_api_request() ) {
			require_once( WC_ABSPATH . 'includes/wc-cart-functions.php' );
			require_once( WC_ABSPATH . 'includes/wc-notice-functions.php' );

			// Disable cookie authentication REST check and ONLY, if the site is SECURE. 🔒
			if ( is_ssl() ) {
				remove_filter( 'rest_authentication_errors', 'rest_cookie_check_errors', 100 );
			}

			// Initialize session.
			$this->initialize_session();

			// Initialize cart.
			$this->initialize_cart();

			// Identify if user has switched.
			if ( $this->has_user_switched() ) {
				$this->user_switched();
			}
		}
	} // END maybe_load_cart()

	/**
	 * If the current customer ID in session does not match,
	 * then the user has switched.
	 *
	 * @access protected
	 * @since  2.1.0
	 * @return null|boolean
	 */
	protected function has_user_switched() {
		if ( ! WC()->session instanceof CoCart_Session_Handler ) {
			return;
		}

		// Get cart cookie... if any.
		$cookie = WC()->session->get_session_cookie();

		// Current user ID. If user is NOT logged in then the customer is a guest.
		$current_user_id = strval( get_current_user_id() );

		// Does a cookie exist?
		if ( $cookie ) {
			$customer_id = $cookie[0];

			// If the user is logged in and does not match ID in cookie then user has switched.
			if ( $current_user_id !== $customer_id ) {
				/* translators: %1$s is previous ID, %2$s is current ID. */
				CoCart_Logger::log( sprintf( __( 'User has changed! Was %1$s before and is now %2$s', 'cart-rest-api-for-woocommerce' ), $customer_id, $current_user_id ), 'info' );

				return true;
			}
		}

		return false;
	} // END has_user_switched()

	/**
	 * Allows something to happen if a user has switched.
	 *
	 * @access public
	 * @since  2.1.0
	 */
	public function user_switched() {
		do_action( 'cocart_user_switched' );
	} // END user_switched()

	/**
	 * Initialize session.
	 *
	 * @access public
	 * @since  2.1.0
	 * @return object WC()->session
	 */
	public function initialize_session() {
		// CoCart session handler class.
		$session_class = 'CoCart_Session_Handler';

		if ( is_null( WC()->session ) || ! WC()->session instanceof $session_class ) {
			// Prefix session class with global namespace if not already namespaced.
			if ( false === strpos( $session_class, '\\' ) ) {
				$session_class = '\\' . $session_class;
			}

			// Initialize new session.
			WC()->session = new $session_class();
			WC()->session->init();
		}
	} // END initialize_session()

	/**
	 * Initialize cart.
	 *
	 * @access public
	 * @since  2.1.0
	 */
	public function initialize_cart() {
		if ( is_null( WC()->customer ) || ! WC()->customer instanceof WC_Customer ) {
			$customer_id = strval( get_current_user_id() );

			WC()->customer = new WC_Customer( $customer_id, true );

			// Customer should be saved during shutdown.
			add_action( 'shutdown', array( WC()->customer, 'save' ), 10 );
		}

		if ( is_null( WC()->cart ) || ! WC()->cart instanceof WC_Cart ) {
			WC()->cart = new WC_Cart();
		}
	} // END initialize_cart()

	/**
	 * Include CoCart REST API controllers.
	 *
	 * @access  public
	 * @since   1.0.0
	 * @version 2.5.0
	 */
	public function rest_api_includes() {
		$this->maybe_load_cart();

		// Only include Legacy REST API if WordPress is v5.4.2 or lower.
		if ( CoCart_Helpers::is_wp_version_lt( '5.4.2' ) ) {
			// Legacy - WC Cart REST API v2 controller.
			include_once( dirname( __FILE__ ) . '/api/legacy/wc-v2/class-wc-rest-cart-controller.php' );
		}

		// CoCart REST API v1 controllers.
		include_once( dirname( __FILE__ ) . '/api/cocart/v1/class-cocart-controller.php' );
		include_once( dirname( __FILE__ ) . '/api/cocart/v1/class-cocart-add-item-controller.php' );
		include_once( dirname( __FILE__ ) . '/api/cocart/v1/class-cocart-clear-cart-controller.php' );
		include_once( dirname( __FILE__ ) . '/api/cocart/v1/class-cocart-calculate-controller.php' );
		include_once( dirname( __FILE__ ) . '/api/cocart/v1/class-cocart-count-controller.php' );
		include_once( dirname( __FILE__ ) . '/api/cocart/v1/class-cocart-item-controller.php' );
		include_once( dirname( __FILE__ ) . '/api/cocart/v1/class-cocart-logout-controller.php' );
		include_once( dirname( __FILE__ ) . '/api/cocart/v1/class-cocart-totals-controller.php' );

		// CoCart REST API v2 controllers.
		include_once( dirname( __FILE__ ) . '/api/class-cocart-store-controller.php' );
		include_once( dirname( __FILE__ ) . '/api/class-cocart-cart-controller.php' );
		include_once( dirname( __FILE__ ) . '/api/class-cocart-add-item-controller.php' );
		include_once( dirname( __FILE__ ) . '/api/class-cocart-clear-cart-controller.php' );
		include_once( dirname( __FILE__ ) . '/api/class-cocart-calculate-controller.php' );
		include_once( dirname( __FILE__ ) . '/api/class-cocart-count-controller.php' );
		include_once( dirname( __FILE__ ) . '/api/class-cocart-update-item-controller.php' );
		include_once( dirname( __FILE__ ) . '/api/class-cocart-remove-item-controller.php' );
		include_once( dirname( __FILE__ ) . '/api/class-cocart-restore-item-controller.php' );
		include_once( dirname( __FILE__ ) . '/api/class-cocart-logout-controller.php' );
		include_once( dirname( __FILE__ ) . '/api/class-cocart-totals-controller.php' );

		do_action( 'cocart_rest_api_controllers' );
	} // rest_api_includes()

	/**
	 * Register CoCart REST API routes.
	 *
	 * @access  public
	 * @since   1.0.0
	 * @version 2.5.0
	 */
	/*public function register_cart_routes() {
		$controllers = array(
			// WC Cart REST API v2 controller.
			'WC_REST_Cart_Controller',

			// CoCart REST API v1 controllers.
			'CoCart_API_Controller',
			'CoCart_Add_Item_Controller',
			'CoCart_Clear_Cart_Controller',
			'CoCart_Calculate_Controller',
			'CoCart_Count_Items_Controller',
			'CoCart_Item_Controller',
			'CoCart_Logout_Controller',
			'CoCart_Totals_Controller',

			// CoCart REST API v2 controllers.
			'CoCart_Store_V2_Controller',
			'CoCart_Cart_V2_Controller',
			'CoCart_Add_Item_v2_Controller',
			'CoCart_Clear_Cart_v2_Controller',
			'CoCart_Calculate_v2_Controller',
			'CoCart_Count_Items_v2_Controller',
			'CoCart_Update_Item_v2_Controller',
			'CoCart_Remove_Item_v2_Controller',
			'CoCart_Restore_Item_v2_Controller',
			'CoCart_Logout_v2_Controller',
			'CoCart_Totals_v2_Controller'
		);

		foreach ( $controllers as $controller ) {
			if ( class_exists( $controller ) ) {
				$this->$controller = new $controller();
				$this->$controller->register_routes();
			}
		}
	} // END register_cart_routes()*/

	/**
	 * Allow all cross origin header requests.
	 * 
	 * Disabled by default. Requires `cocart_allow_all_cors` filter set to true to enable.
	 *
	 * @access  public
	 * @since   2.2.0
	 * @version 2.3.0
	 */
	public function allow_all_cors() {
		// If not enabled via filter then return.
		if ( apply_filters( 'cocart_disable_all_cors', true ) ) {
			return;
		}

		// If the REST API request was not for CoCart then return.
		if ( ! CoCart_Helpers::is_rest_api_request() ) {
			return;
		}

		// Remove the default cors server headers.
		remove_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' );

		// Adds new cors server headers.
		add_filter( 'rest_pre_serve_request', array( $this, 'cors_headers' ), 0, 4 );
	} // END allow_all_cors()

	/**
	 * Cross Origin headers.
	 *
	 * @access public
	 * @since  2.2.0
	 * @param  bool             $served  Whether the request has already been served. Default false.
	 * @param  WP_HTTP_Response $result  Result to send to the client. Usually a WP_REST_Response.
	 * @param  WP_REST_Request  $request Request used to generate the response.
	 * @param  WP_REST_Server   $server  Server instance.
	 * @return bool
	 */
	public function cors_headers( $served, $result, $request, $server ) {
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Access-Control-Allow-Methods: POST, GET, OPTIONS, DELETE' );
		header( 'Access-Control-Allow-Credentials: true' );
		header( 'Access-Control-Allow-Headers: Authorization, Content-Type, X-Requested-With' );

		return $served;
	} // END cors_headers()

} // END class

return new CoCart_Server();
