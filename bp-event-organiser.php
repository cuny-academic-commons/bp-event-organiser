<?php
/*
--------------------------------------------------------------------------------
Plugin Name: BuddyPress Event Organiser
Description: A WordPress plugin for assigning Event Organiser plugin Events to BuddyPress Groups and Group Hierarchies
Version: 0.2
Author: Christian Wach
Author URI: http://haystack.co.uk
Plugin URI: http://haystack.co.uk
--------------------------------------------------------------------------------
*/



// set our debug flag here
define( 'BUDDYPRESS_EVENT_ORGANISER_DEBUG', false );

// set our version here
define( 'BUDDYPRESS_EVENT_ORGANISER_VERSION', '0.2' );

// store reference to this file
if ( !defined( 'BUDDYPRESS_EVENT_ORGANISER_FILE' ) ) {
	define( 'BUDDYPRESS_EVENT_ORGANISER_FILE', __FILE__ );
}

// store URL to this plugin's directory
if ( !defined( 'BUDDYPRESS_EVENT_ORGANISER_URL' ) ) {
	define( 'BUDDYPRESS_EVENT_ORGANISER_URL', plugin_dir_url( BUDDYPRESS_EVENT_ORGANISER_FILE ) );
}
// store PATH to this plugin's directory
if ( !defined( 'BPEO_PATH' ) ) {
	define( 'BPEO_PATH', plugin_dir_path( BUDDYPRESS_EVENT_ORGANISER_FILE ) );
}
// backward compatibility
define( 'BUDDYPRESS_EVENT_ORGANISER_PATH', constant( 'BPEO_PATH' ) );

// group events slug
if ( ! defined( 'BPEO_EVENTS_SLUG' ) ) {
	define( 'BPEO_EVENTS_SLUG', 'events' );
}

// new events slug
if ( ! defined( 'BPEO_EVENTS_NEW_SLUG' ) ) {
	define( 'BPEO_EVENTS_NEW_SLUG', 'new-event' );
}

/**
 * Include BuddyPress-specific functionality.
 */
function bpeo_include() {
	require( BPEO_PATH . 'includes/functions.php' );
	require( BPEO_PATH . 'includes/component.php' );
	require( BPEO_PATH . 'includes/user.php' );

	if ( bp_is_active( 'groups' ) ) {
		require( BPEO_PATH . 'includes/group.php' );
	}
}
add_action( 'bp_include', 'bpeo_include' );



/*
--------------------------------------------------------------------------------
BuddyPress_Event_Organiser Class
--------------------------------------------------------------------------------
*/

class BuddyPress_Event_Organiser {

	/**
	 * properties
	 */

	// Admin/DB class
	public $db;

	// Event Organiser utilities class
	public $eo;



	/**
	 * @description: initialises this object
	 * @return object
	 */
	function __construct() {

		// initialise
		$this->initialise();

		// use translation files
		$this->enable_translation();

		// add action for CBOX theme compatibility
		add_action( 'wp_head', array( $this, 'cbox_theme_compatibility' ) );

		// --<
		return $this;

	}



	/**
	 * @description: do stuff on plugin init
	 * @return nothing
	 */
	public function initialise() {

		// load our Walker class
		require( BUDDYPRESS_EVENT_ORGANISER_PATH . 'bp-event-organiser-walker.php' );

		// load our BuddyPress Group class
		require( BUDDYPRESS_EVENT_ORGANISER_PATH . 'bp-event-organiser-groups.php' );

		// load our Event Organiser class
		require( BUDDYPRESS_EVENT_ORGANISER_PATH . 'bp-event-organiser-eo.php' );

		// initialise
		$this->eo = new BuddyPress_Event_Organiser_EO;

		// store references
		$this->eo->set_references( $this );

	}



	/**
	 * @description: do stuff on plugin activation
	 * @return nothing
	 */
	public function activate() {

	}



	/**
	 * @description: do stuff on plugin deactivation
	 * @return nothing
	 */
	public function deactivate() {

	}



	//##########################################################################



	/**
	 * @description: load translation files
	 * A good reference on how to implement translation in WordPress:
	 * http://ottopress.com/2012/internationalization-youre-probably-doing-it-wrong/
	 * @return nothing
	 */
	public function enable_translation() {

		// not used, as there are no translations as yet
		load_plugin_textdomain(

			// unique name
			'bp-event-organiser',

			// deprecated argument
			false,

			// relative path to directory containing translation files
			dirname( plugin_basename( __FILE__ ) ) . '/languages/'

		);

	}



	/**
	 * @description: adds icon to menu in CBOX theme
	 */
	function cbox_theme_compatibility() {

		// is CBOX theme active?
		if ( function_exists( 'cbox_theme_register_widgets' ) ) {

			// output style in head
			?>

			<style type="text/css">
			/* <![CDATA[ */
			#nav-<?php echo apply_filters( 'bpeo_extension_slug', 'events' ) ?>:before
			{
				content: "R";
			}
			/* ]]> */
			</style>

			<?php

		}

	}

} // class ends



/**
 * @description: init plugin
 * @return nothing
 */
function buddypress_event_organiser_init() {

	// declare as global
	global $buddypress_event_organiser;

	// init plugin
	$buddypress_event_organiser = new BuddyPress_Event_Organiser;

}

// init
add_action( 'bp_include', 'buddypress_event_organiser_init' );


