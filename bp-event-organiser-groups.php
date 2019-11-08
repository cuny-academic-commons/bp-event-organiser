<?php /*
================================================================================
BP Group Organiser Group Extension
================================================================================
AUTHOR: Christian Wach <needle@haystack.co.uk>
--------------------------------------------------------------------------------
NOTES
=====

This class extends BP_Group_Extension to create the screens our plugin requires.
See: http://codex.buddypress.org/developer/plugin-development/group-extension-api/

--------------------------------------------------------------------------------
*/



// prevent problems during upgrade or when Groups are disabled
if ( !class_exists( 'BP_Group_Extension' ) ) { return; }



/*
================================================================================
Class Name
================================================================================
*/

class BP_Event_Organiser_Group_Extension extends BP_Group_Extension {



	/*
	============================================================================
	Properties
	============================================================================
	*/

	/*
	// 'public' will show our extension to non-group members
	// 'private' means only members of the group can view our extension
	public $visibility = 'public';

	// if our extension does not need a navigation item, set this to false
	public $enable_nav_item = true;

	// if our extension does not need an edit screen, set this to false
	public $enable_edit_item = true;

	// if our extension does not need an admin metabox, set this to false
	public $enable_admin_item = true;

	// the context of our admin metabox. See add_meta_box()
	public $admin_metabox_context = 'core';

	// the priority of our admin metabox. See add_meta_box()
	public $admin_metabox_priority = 'normal';
	*/

	// no need for a creation step
	public $enable_create_step = false;

	// if our extension does not need an edit screen, set this to false
	public $enable_edit_item = false;

	// if our extension does not need an admin metabox, set this to false
	public $enable_admin_item = false;



	/**
	 * @description: initialises this object
	 * @return nothing
	 */
	public function __construct() {

		// init vars with filters applied
		$name = apply_filters( 'bpeo_extension_title', __( 'Events', 'bp-event-organiser' ) );
		$slug = apply_filters( 'bpeo_extension_slug', bpeo_get_events_slug() );
		$pos = apply_filters( 'bpeo_extension_pos', 31 );

		// Allow direct access to private group iCals.
		add_filter( 'bp_group_user_has_access', array( $this, 'ical_allow_public_access' ) );

		// test for BP 1.8+
		// could also use 'bp_esc_sql_order' (the other core addition)
		if ( function_exists( 'bp_core_get_upload_dir' ) ) {
			// init array
			$args = array(
				'name' => $name,
				'slug' => $slug,
				'nav_item_position' => $pos,
				'enable_create_step' => false,
			);

			// Register the "Manage > Events" screen.
			if ( bp_is_group() ) {
				$args['screens'] = array(
					'edit' => array(
						'enabled' => true,
						'slug' => 'events',
						'name' => __( 'Events', 'bp-event-organiser' ),
						'screen_callback' => array( $this, 'edit_screen_callback' ),
						'screen_save_callback' => array( $this, 'edit_screen_save_callback' ),
					),
				);
			}

			// init
			parent::init( $args );

	 	} else {

			// name our tab
			$this->name = $name;
			$this->slug = $slug;

			// set position in navigation
			$this->nav_item_position = $pos;

			// disable create step
			$this->enable_create_step = false;

		}

		$this->register_subnav();

		// Import ICS
		if ( class_exists( 'Event_Organiser_Im_Export' ) ) {
			add_action( 'bp_actions', array( $this, 'manage_events_import_ics' ), 7 );
		}
	}

	/**
	 * Registers subnav menu for a group's 'events' nav item.
	 *
	 * This is ultra hacky.  According to BP, the 'events' nav item is a subnav.
	 * So for 'events' to have a subnav, we have to do some weird stuff.  See how
	 * a group's "Manage" subnav is registered in bp-groups-loader.php for an idea
	 * of what we're doing here.
	 */
	protected function register_subnav() {
		if ( ! bp_is_group() ) {
			return;
		}

		$subnav = array();

		// Common params to all nav items
		$default_params = array(
			'parent_url'        => bpeo_get_group_permalink(),

			// this doesn't make sense; this emulates how a group's "Manage" subnav is
			// registered as well
			'parent_slug'       => buddypress()->groups->current_group->slug . '_events',

			'screen_function'   => array( $this, '_display_hook' ),
			'show_in_admin_bar' => true,
		);

		$sub_nav[] = array_merge( array(
			'name'            => __( 'Calendar', 'bp-event-organiser' ),
			'slug'            => 'calendar',
			'user_has_access' => current_user_can( 'read_group_events', bp_get_current_group_id() ),
			'position'        => 0,
			'link'            => bpeo_get_group_permalink(),
		), $default_params );

		$sub_nav[] = array_merge( array(
			'name'            => __( 'Upcoming', 'bp-event-organiser' ),
			'slug'            => 'upcoming',
			'user_has_access' => current_user_can( 'read_group_events', bp_get_current_group_id() ),
			'position'        => 0,
			'link'            => bpeo_get_group_permalink() . 'upcoming/',
		), $default_params );

		// We only allow group admins to see the Manage tab
		$admin_ids = bp_group_admin_ids( groups_get_current_group(), 'array' );
		$sub_nav[] = array_merge( array(
			'name'            => __( 'Manage', 'bp-event-organiser' ),
			'slug'            => 'manage',
			'user_has_access' => in_array( bp_loggedin_user_id(), $admin_ids ),
			'position'        => 0,
			'link'            => trailingslashit( bp_get_group_permalink( groups_get_current_group() ) . 'admin/' . $this->params['slug'] ),
		), $default_params );

		// @todo This should probably use a custom cap instead of membership check.
		$sub_nav[] = array_merge( array(
			'name'            => __( 'New Event', 'bp-event-organiser' ),
			'slug'            => bpeo_get_events_new_slug(),
			'user_has_access' => buddypress()->groups->current_group->is_user_member,
			'position'        => 99,
		), $default_params );

		foreach( (array) $sub_nav as $nav ) {
			bp_core_new_subnav_item( $nav );
		}
	}

	/**
	 * Override parent _display_hook() method to add logic for single events.
	 */
	public function _display_hook() {
		// add event subnav
		if ( empty( $_GET['embedded'] ) ) {
			add_action( 'bp_template_content', array( $this, 'add_subnav' ) );
		}

		// new event
		if ( bpeo_is_action( 'new' ) ) {
			// check if user has access
			// @todo currently all group members have access to edit events... restrict to mods?
			if ( false === is_user_logged_in() || false === buddypress()->groups->current_group->is_user_member ) {
				bp_core_add_message( __( 'You do not have access to edit this event.', 'bp-event-organiser' ), 'error' );
				bp_core_redirect( bpeo_get_group_permalink() );
				die();
			}

			// magic admin screen code!
			require BPEO_PATH . '/includes/class.bpeo_frontend_admin_screen.php';

			$this->create_event = new BPEO_Frontend_Admin_Screen( array(
				'type'           => 'new',
				'redirect_root'  => bpeo_get_group_permalink()
			) );

			add_action( 'bp_template_content', array( $this->create_event, 'display' ) );

		// upcoming
		} elseif ( bp_is_action_variable( 'upcoming', 0 ) ) {
			add_action( 'bp_template_content', array( $this, 'call_display' ) );

		// iCal
		} elseif ( bp_is_action_variable( 'ical' ) || true === ctype_xdigit( bp_action_variable() ) ) {
			$this->ical_action();
			return;

		// single event
		} elseif ( ! empty( buddypress()->action_variables ) ) {
			$this->single_event_screen();
			add_action( 'bp_template_title',   array( $this, 'display_single_event_title' ) );
			add_action( 'bp_template_content', array( $this, 'display_single_event' ) );

		// default behavior
		} else{
			add_action( 'bp_template_content', array( $this, 'call_display' ) );
		}

		bp_core_load_template( apply_filters( 'bp_core_template_plugin', $this->template_file ) );
	}

	/**
	 * Output the events subnav menu on group event pages.
	 *
	 * See how a group's "Manage" subnav works for an idea of what we're doing.
	 */
	public function add_subnav() {
		$_action_variables = buddypress()->action_variables;

		// highlight the 'calendar' slug when we're on the slug
		if ( false === bp_action_variable() ) {
			buddypress()->action_variables[] = 'calendar';
		}

		// Use our template stack.
		add_filter( 'eventorganiser_template_stack', 'bpeo_register_template_stack' );

		// Load our template part.
		eo_get_template_part( 'buddypress/groups/single/subnav-events' );

		// Remove our template stack.
		remove_filter( 'eventorganiser_template_stack', 'bpeo_register_template_stack' );

		buddypress()->action_variables = $_action_variables;
	}

	/**
	 * @description display our content when the nav item is selected
	 */
	public function display( $group_id = null ) {
		// show header
		echo '<h3>'.apply_filters( 'bpeo_extension_title', __( 'Group Events', 'bp-event-organiser' ) ).'</h3>';

		// show secondary title if filter is in use
		$filter_title = bpeo_get_the_filter_title();
		if ( ! empty( $filter_title ) ) {
			echo "<h4>{$filter_title}</h4>";
		}

		// delete the calendar transient cache depending on user cap
		// @todo EO's calendar transient cache needs overhauling
		if( current_user_can( 'read_private_events' ) ){
			delete_transient( 'eo_full_calendar_public_priv' );
		} else {
			delete_transient( 'eo_full_calendar_public' );
		}

		// use our template stack
		add_filter( 'eventorganiser_template_stack', 'bpeo_register_template_stack' );

		$action = bp_action_variable( 0 );
		if ( ! $action ) {
			$action = 'calendar';
		}

		// load our template part
		eo_get_template_part( $action );

		// remove our template stack
		remove_filter( 'eventorganiser_template_stack', 'bpeo_register_template_stack' );

	}

	/**
	 * Single event screen handler.
	 */
	protected function single_event_screen() {
		if ( false === bp_is_current_action( $this->slug ) ) {
			return;
		}

		if ( empty( buddypress()->action_variables ) ) {
			return;
		}

		// Set up query args
		$query_args = array();
		$query_args['suppress_filters'] = true;
		$query_args['orderby'] = 'none';
		$query_args['post_status'] = array( 'publish', 'pending', 'private', 'draft', 'future', 'trash' );

		// this is a draft with no slug
		if ( false !== strpos( bp_action_variable(), 'draft-' ) ) {
			$query_args['post__in'] = (array) str_replace( 'draft-', '', bp_action_variable() );

		// use post slug
		} else {
			$query_args['name'] = bp_action_variable();
		}

		// query for the event
		$event = eo_get_events( $query_args );

		// check if event exists
		if ( empty( $event ) ) {
			bp_core_add_message( __( 'Event does not exist.', 'bp-event-organiser' ), 'error' );
			bp_core_redirect( bpeo_get_group_permalink() );
			die();
		}

		// check if event belongs to group
		// this needs to be edited once boone finishes new schema
		if ( false == in_array( bp_get_current_group_id(), $GLOBALS['buddypress_event_organiser']->eo->get_calendar_groups( $event[0]->ID ) ) ) {
			bp_core_add_message( __( 'Event does not belong to this group.', 'bp-event-organiser' ), 'error' );
			bp_core_redirect( bpeo_get_group_permalink() );
			die();
		}

		// save queried event as property
		$this->queried_event = $event[0];

		// edit single event logic
		if ( bpeo_is_action( 'edit' ) ) {
			// check if user has access
			if ( false === current_user_can( 'edit_event', $this->queried_event->ID ) ) {
				bp_core_add_message( __( 'You do not have access to edit this event.', 'bp-event-organiser' ), 'error' );
				bp_core_redirect( bpeo_get_group_permalink() . "{$this->queried_event->post_name}/" );
				die();
			}


			// magic admin screen code!
			require BPEO_PATH . '/includes/class.bpeo_frontend_admin_screen.php';

			$this->edit_event = new BPEO_Frontend_Admin_Screen( array(
				'queried_post'   => $this->queried_event,
				'redirect_root'  => bpeo_get_group_permalink()
			) );

		// delete single event logic
		} elseif ( bpeo_is_action( 'delete' ) ) {
			// check if user has access
			if ( false === current_user_can( 'delete_event', $this->queried_event->ID ) ) {
				bp_core_add_message( __( 'You do not have permission to delete this event.', 'bp-event-organiser' ), 'error' );
				bp_core_redirect( bpeo_get_group_permalink() . "{$this->queried_event->post_name}/" );
				die();
			}

			// verify nonce
			if ( false === bp_action_variable( 2 ) || ! wp_verify_nonce( bp_action_variable( 2 ), "bpeo_delete_event_{$this->queried_event->ID}" ) ) {
				bp_core_add_message( __( 'You do not have permission to delete this event.', 'bp-event-organiser' ), 'error' );
				bp_core_redirect( bpeo_get_group_permalink() . "{$this->queried_event->post_name}/" );
				die();
			}

			// delete event
			$delete = wp_delete_post( $this->queried_event->ID, true );
			if ( false === $delete ) {
				bp_core_add_message( __( 'There was a problem deleting the event.', 'bp-event-organiser' ), 'error' );
			} else {
				bp_core_add_message( __( 'Event deleted.', 'bp-event-organiser' ) );
			}

			bp_core_redirect( bpeo_get_group_permalink() );
			die();
		}
	}

	/**
	 * Display the single event title within a group.
	 *
	 * This is for themes using the 'bp_template_title' hook.
	 */
	public function display_single_event_title() {
		if ( bpeo_is_action( 'edit' ) ) {
			return;
		}

		if ( empty( $this->queried_event ) ) {
			return;
		}

		// save $post global temporarily
		global $post;
		$_post = false;
		if ( ! empty( $post ) ) {
			$_post = $post;
		}

		// override the $post global so EO can use its functions
		$post = $this->queried_event;

		add_filter( 'protected_title_format', array( $this, 'no_post_status_title' ), 10, 2 );
		add_filter( 'private_title_format',   array( $this, 'no_post_status_title' ), 10, 2 );
		the_title();

		// revert $post global
		if ( ! empty( $_post ) ) {
			$post = $_post;
		}
	}

	/**
	 * Display a single event within a group.
	 *
	 * @todo Move part of this functionality into a template part so theme devs can customize.
	 */
	public function display_single_event() {
		if ( empty( $this->queried_event ) ) {
			return;
		}

		// save $post global temporarily
		global $post, $pages;
		$_post = false;
		if ( ! empty( $post ) ) {
			$_post = $post;
		}

		// override the $post global so EO can use its functions
		$post = $this->queried_event;

		// edit screen has its own display method
		if ( bpeo_is_action( 'edit' ) ) {
			$this->edit_event->display();

			// revert $post global
			if ( ! empty( $_post ) ) {
				$post = $_post;
			}
			return;
		}

		// output title if theme is not using the 'bp_template_title' hook
		if ( ! did_action( 'bp_template_title' ) ) {
			add_filter( 'protected_title_format', array( $this, 'no_post_status_title' ), 10, 2 );
			add_filter( 'private_title_format',   array( $this, 'no_post_status_title' ), 10, 2 );
			the_title( '<h2>', '</h2>' );
		}

		// do something after the title
		// this is the same hook used in the admin area
		do_action( 'edit_form_after_title', $post );

		// BP removes all filters for 'the_content' during theme compat.
		// bring it back and remove BP's content filter
		bp_restore_all_filters( 'the_content' );
		remove_filter( 'the_content', 'bp_replace_the_content' );

		// hey there, mr. hack!
		//
		// we're going to use the_content() in our BPEO template part.  so we want to
		// get the rendered content for the event without BP theme compat running its
		// filter.
		//
		// get_the_content() is weird and checks the $pages global for the content
		if ( bp_use_theme_compat_with_current_theme() ) {
			$key = 0;

		// bp-default requires the key set to -1
		} else {
			$key = -1;
		}
		$pages[$key] = apply_filters( 'the_content', $post->post_content );

		// remove all filters like before
		bp_remove_all_filters( 'the_content' );

		// output single event content
		eo_get_template_part( 'content-eo', 'event' );

		// revert $post global
		if ( ! empty( $_post ) ) {
			$post = $_post;
		}
	}

	/**
	 * Validate iCalendar download.
	 */
	protected function ical_action() {
		$args = array(
			'filename' => bp_get_group_slug( groups_get_current_group() ),
			'bp_group' => bp_get_current_group_id(),
			'url'      => bpeo_get_group_permalink()
		);

		// public iCal
		if ( bp_is_action_variable( 'ical' ) && 'public' === bp_get_group_status( groups_get_current_group() ) ) {
			$args['name'] = bp_get_group_name( groups_get_current_group() );

		// private iCal
		} else {
			if ( false === bp_is_action_variable( bpeo_get_the_group_private_ical_hash() ) ) {
				return;
			}

			if ( false === bp_is_action_variable( 'ical', 1 ) ) {
				return;
			}

			$args['name'] = sprintf( __( '%s (Private)', 'bp-event-organiser' ), bp_get_group_name( groups_get_current_group() ) );
		}

		// Sanity check
		if ( empty( $args['name' ]) ) {
			return;
		}

		// iCal time!
		bpeo_do_ical_download( $args );
	}

	/**
	 * Allows direct access to private group iCals.
	 */
	public function ical_allow_public_access( $retval ) {
		if ( true === $retval || is_user_logged_in() ) {
			return $retval;
		}

		if ( bp_is_current_action( bpeo_get_events_slug() ) && bp_is_action_variable( 'ical' ) && true === ctype_xdigit( bp_action_variable( 0 ) ) ) {
			$this->ical_action();

			/** This filter is documented in /wp-includes/template-loader.php */
			$template = apply_filters( 'template_include', get_404_template() );
			include( $template );
			die();
		}

		return $retval;
	}

	/**
	 * Returns the post title without the post status prefixed to it.
	 *
	 * @param  string  $retval sprintf format for the title with the prefixed post status
	 * @param  WP_Post $post   The queried post object.
	 * @return string
	 */
	public function no_post_status_title( $retval, $post ) {
		return $post->post_title;
	}

	/**
	 * Custom hook on "Manage Events" screen to catch "Reset private URL" action.
	 */
	public function call_edit_screen_template_loader( $group_id = null ) {
		// 'Reset Private URL' action
		if ( ! empty( $_GET['bpeo-reset'] ) ) {
			check_admin_referer( 'bpeo_group_reset_private_ical', 'bpeo-reset' );

			// reset hash
			bpeo_get_the_group_private_ical_hash( bp_get_current_group_id(), true );

			bp_core_add_message( __( 'Private iCalendar URL has been reset. Please copy the new link below to use in your calendar application.', 'bp-event-organiser' ) );
			bp_core_redirect( trailingslashit( bp_get_group_permalink( groups_get_current_group() ) . 'admin/' . $this->slug ) );
			die();
		}

		// Do what the parent extension does.
		parent::call_edit_screen_template_loader( $group_id );
	}

	/**
	 * Renders the content of the Manage subscreen.
	 *
	 * @param int $group_id ID of the group.
	 */
	public function edit_screen_callback( $group_id = null ) {
		// Non-private groups get a "Manage iCalendar settings" section.
		if ( 'public' !== bp_get_group_status( groups_get_current_group() ) ) {
			// use our template stack
			add_filter( 'eventorganiser_template_stack', 'bpeo_register_template_stack' );

			// load our template part
			eo_get_template_part( 'buddypress/events/manage-group-ical' );

			// remove our template stack
			remove_filter( 'eventorganiser_template_stack', 'bpeo_register_template_stack' );

		/*
		 * Public group, so add dummy submit button.
		 *
		 * The BP Group Extension API adds a submit button by default if one doesn't
		 * exist. We don't need a submit button because the Import ICS section uses
		 * a custom form. So to bypass this, we add a hidden submit button.
		 */
		} else {
			echo '<input type="submit" style="display:none" />';
		}
	}

	/**
	 * Processes the saved Manage subscreen.
	 *
	 * @param int $group_id ID of the group.
	 */
	public function edit_screen_save_callback( $group_id = null ) {
	}

	/**
	 * Import iCalendar ICS section on "Manage > Events" screen.
	 *
	 * We're running this on 'bp_actions' at priority 7 so we can do our own
	 * validation routine ahead of the BP Group Extension API. The reason we need
	 * to do this is we run a custom <form> and the Group Extension API already
	 * includes a <form> for us, so we can't do our thing with the native API.
	 *
	 * @since 1.1.0
	 */
	public function manage_events_import_ics() {
		// If not on our "Manage > Events" page, bail.
		if ( ! is_user_logged_in() || true !== ( bp_is_groups_component() && bp_is_current_action( 'admin' ) && bp_is_action_variable( $this->params['slug'], 0 ) ) ) {
			return;
		}

		/*
		 * Upload validation routine.
		 *
		 * This part is mostly a dupe of Event_Organiser_Im_Export::__construct() with
		 * some mods to handle BP groups and notice rendering.
		 */
		if ( ! empty( $_POST['eventorganiser_import_events'] ) && wp_verify_nonce( $_POST['bpeo_group_import_nonce'], 'eventorganiser_import_events' ) ) {
			global $EO_Errors;
			$EO_Errors = new WP_Error();

			// Perform checks on file.
			if ( in_array( $_FILES["ics"]["type"], array( "text/calendar", "application/octet-stream" ) ) && ( $_FILES["ics"]["size"] < 2097152 ) ) {
				if ( $_FILES["ics"]["error"] > 0 ) {
					$EO_Errors = new WP_Error( 'eo_error', sprintf( __( "File Error encountered: %d", 'bp-event-organiser' ), $_FILES["ics"]["error"] ) );

				// Import file.
				} else {
					// Add some meta to imported event.
					add_action( 'eventorganiser_created_event', array( $this, 'add_meta_to_imported_event' ) );

					// Bypass permission checks for event creation.
					add_filter( 'map_meta_cap', array( $this, 'bypass_permission_checks' ), 10, 4 );

					// Import the iCalendar file.
					$import = Event_Organiser_Im_Export::get_object();
					$import->import_file( $_FILES['ics']['tmp_name'] );

					remove_filter( 'map_meta_cap', array( $this, 'bypass_permission_checks' ), 10 );
				}

			} elseif ( ! isset( $_FILES ) || empty( $_FILES['ics']['name'] ) ) {
				$EO_Errors = new WP_Error( 'eo_error', __( "No file detected.", 'bp-event-organiser' ) );

			} else {
				$EO_Errors = new WP_Error( 'eo_error', __( "Invalid file uploaded. The file must be a ics calendar file of type 'text/calendar', no larger than 2MB.", 'bp-event-organiser' ) );
				$size = size_format( $_FILES["ics"]["size"], 2 );
				$details = sprintf( __( 'File size: %s. File type: %s', 'bp-event-organiser' ), $size, $_FILES["ics"]["type"] );
				$EO_Errors->add( 'eo_error', $details );
			}

			$errors  = $EO_Errors->get_error_messages( 'eo_error' );
			$notices = $EO_Errors->get_error_messages( 'eo_notice' );

			if ( ! empty( $errors ) ) {
				bp_core_add_message( sprintf( '<p>%s</p>', implode( '</p><p>', $errors ) ), 'error' );
			} elseif ( ! empty( $notices ) ) {
				bp_core_add_message( sprintf( '<p>%s</p>', implode( '</p><p>', $notices ) ) );
			}

			bp_core_redirect( bp_get_group_permalink( groups_get_current_group() ) . 'admin/' . $this->params['slug'] . '/' );
		}

		// Check for validation message and remove some filters.
		if ( isset( buddypress()->template_message ) ) {
			remove_filter( 'bp_core_render_message_content', 'wp_kses_data', 5 );
		}

		// Load display hook.
		add_action( 'bp_after_group_body', array( $this, 'manage_events_display_import_ics' ), 20 );
	}

	/**
	 * Display hook for import iCalendar ICS section on "Manage > Events" screen.
	 *
	 * Mostly duplicated from Event_Organiser_Im_Export::get_im_export_markup().
	 *
	 * @since 1.1.0
	 */
	public function manage_events_display_import_ics() {
	?>

		<div class="manage-icalendar manage-icalendar-ics">
			<h3><?php esc_html_e( 'Import Events from iCalendar File', 'bp-event-organiser' ); ?></h3>

			<form method="post" action="" enctype="multipart/form-data">
				<p><?php esc_html_e( "Select an iCalendar file that you would like to import into the group's calendar. The file should be an .ics file.", 'bp-event-organiser' ); ?></p>

				<?php if ( taxonomy_exists( 'event-venue' ) && bpeo_is_import_venues_enabled() ) : ?>
					<label><input type="checkbox" name="eo_import_venue" value="1" /> <?php _e( 'Import and create venues if they do not exist', 'bp-event-organiser' ); ?></label>

				<?php endif; ?>

				<?php if ( bpeo_is_import_categories_enabled() ) : ?>
					<label><input type="checkbox" name="eo_import_cat" value="1" /> <?php _e( 'Import and create event categories if they do not exist', 'bp-event-organiser' ); ?></label>

				<?php endif; ?>

				<p><input type="file" name="ics" accept=".ics" /></p>

				<?php wp_nonce_field( 'eventorganiser_import_events', 'bpeo_group_import_nonce' ); ?>

				<?php bp_button( array(
					'id' => 'bpeo-group-import-events',
					'component' => 'groups',
					'block_self' => false,
					'button_element' => 'input',
					'button_attr' => array(
						'type'  => 'submit',
						'name'  => 'eventorganiser_import_events',
						'id'    => 'eventorganiser_import_events',
						'class' => 'button',
						'value' => esc_attr( 'Import', 'bp-event-organiser' )
					),
				) ); ?>
			</form>

		</div>

	<?php
	}

	/**
	 * Callback to connect an imported event via ICS to a group.
	 *
	 * @since 1.1.0
	 *
	 * @param int $post_id Event post ID.
	 */
	public function add_meta_to_imported_event( $post_id ) {
		// Connect the event to the group.
		bpeo_connect_event_to_group( $post_id, bp_get_current_group_id() );

		// Save the filename into event meta, just in case.
		update_post_meta( $post_id, '_eventorganiser_import_filename', $_FILES['ics']['name'] );

		// Ensure events imported in a private group are private.
		if ( 'public' !== bp_get_group_status( groups_get_current_group() ) ) {
			wp_update_post( array(
				'ID'          => $post_id,
				'post_status' => 'private'
			) );
		}
	}

	/**
	 * Bypass various capability checks during ICS event importing.
	 *
	 * Group administrators might not have the proper capabilities to create new
	 * events during ICS imports, so let's bypass them.
	 *
	 * Hooked to 'map_meta_cap'.
	 *
	 * @since 1.1.0
	 *
	 * @param array  $caps    Capability array.
	 * @param string $cap     Capability to check.
	 * @param int    $user_id ID of the user being checked.
	 * @param array  $args    Miscellaneous args.
	 * @return array Caps whitelist.
	 */
	public function bypass_permission_checks( $caps, $cap, $user_id, $args ) {
		if ( ! is_user_logged_in() ) {
			return $caps;
		}

		switch ( $cap ) {
			case 'manage_options':
			case 'edit_events' :
			case 'manage_venues' :
				return array( 'exist' );
				break;
		}

		return $caps;
	}

} // class ends



// register our class
bp_register_group_extension( 'BP_Event_Organiser_Group_Extension' );

// Load our group iCal sync module if necessary.
add_action( 'bp_init', function() {
	if ( ! class_exists( 'EO_Sync_Ical' ) || ! bp_is_root_blog() ) {
		return;
	}

	require_once BPEO_PATH . 'includes/class.bpeo_group_ical_sync.php';

	$GLOBALS['buddypress_event_organiser']->group_ical_sync = new BPEO_Group_Ical_Sync;
}, 0 );
