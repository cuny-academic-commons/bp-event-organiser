<?php

/**
 * Ical sync module for BuddyPress Groups.
 *
 * @since 1.1.0
 */
class BPEO_Group_Ical_Sync {
	/**
	 * Constructor.
	 *
	 * @since 1.1.0
	 */
	public function __construct() {
		// "Manage > Events" page.
		add_action( 'bp_actions', array( $this, 'manage_events_ical_feeds' ), 7 );

		// EO hooks to run on AJAX.
		add_action( 'wp_ajax_add-eo-feed',    array( $this, 'ajax_mods' ), 0 );
		add_action( 'wp_ajax_delete-eo-feed', array( $this, 'ajax_mods' ), 0 );
		add_action( 'wp_ajax_fetch-eo-feed',  array( $this, 'ajax_mods' ), 0 );

		// EO hooks to save our custom BP meta.
		add_action( 'added_post_meta',                         array( $this, 'save_group_id_to_feed' ), 10, 3 );
		add_filter( 'eventorganiser_ical_sync_meta_key_map',   array( $this, 'disable_term_and_activity_saving' ) );
		add_action( 'eventorganiser_ical_sync_event_updated',  array( $this, 'reenable_term_saving' ) );
		add_action( 'eventorganiser_ical_sync_event_inserted', array( $this, 'add_group_to_synced_event' ), 10, 3 );

		// EO hooks to display our iCal data.
		add_filter( 'eventorganiser_fullcalendar_query',    array( $this, 'filter_fullcalendar_query' ) );
		add_filter( 'eventorganiser_event_color',           array( $this, 'fullcalendar_feed_background_color' ), 10, 2 );
		add_filter( 'bpeo_get_the_filter_title',            array( $this, 'filter_filter_title' ) );
		add_action( 'eventorganiser_additional_event_meta', array( $this, 'show_external_feed_data_in_event' ), 40 );

		// Tomfoolery! Save querystring from current URI for AJAX purposes!
		add_filter( 'bp_uri', function( $retval ) {
			if ( ! defined( 'DOING_AJAX' ) ) {
				return $retval;
			}

			// Stash the URI querystring for later.
			if ( $pos = strpos( $retval, '?' ) ) {
				buddypress()->bpeo_querystring = substr( $retval, $pos + 1 );
			}

			return $retval;
		} );

		// Remove EO's default cronjob. We're going to roll our own per group.
		remove_action( 'eventorganiser_ical_feed_sync', 'eo_fetch_feeds' );
		add_action( 'bpeo_group_ical_sync',   array( $this, 'sync' ) );
		add_action( 'bp_groups_delete_group', array( $this, 'delete_cron_on_group_delete' ) );
	}

	/* MANAGE > EVENTS PAGE *************************************************/

	/**
	 * Screen loader for our "Manage > Events" iCalendar section.
	 *
	 * Run on 'bp_actions' at priority 7 so our hook runs before BP's group
	 * extension validation routine for saving purposes.
	 *
	 * @todo Purge all group scheduled events on plugin uninstall.
	 */
	public function manage_events_ical_feeds() {
		// If not on our "Manage > Events" page, bail.
		if ( true !== ( bp_is_groups_component() && bp_is_current_action( 'admin' ) && bp_is_action_variable( $this->get_events_slug(), 0 ) ) ) {
			return;
		}

		// Feed schedule validation routine.
		if ( ! empty( $_POST['action'] ) && 'eventorganier-update-feed-settings' === $_POST['action'] ) {
			check_admin_referer( 'eventorganier-update-feed-settings' );

			$schedule = $_POST['eventorganiser_feed_schedule'];
			$current_schedule = groups_get_groupmeta( bp_get_current_group_id(), 'eventorganiser_feed_schedule' );

			if ( $schedule != $current_schedule ) {
				$schedule_args = array( bp_get_current_group_id() );

				// Unschedule current cronjob for the group.
				$next = wp_next_scheduled( 'bpeo_group_ical_sync', $schedule_args );
				if ( false !== $next ) {
					wp_unschedule_event( $next, 'bpeo_group_ical_sync', $schedule_args );
				}

				// Update custom schedule marker.
				groups_update_groupmeta( bp_get_current_group_id(), 'eventorganiser_feed_schedule', $schedule );

				// Add new cronjob for group.
				if ( ! empty( $schedule ) ) {
					$schedules = wp_get_schedules();
					$timestamp = time() + $schedules[ $schedule ]['interval'];
					wp_schedule_event( $timestamp, $schedule, 'bpeo_group_ical_sync', $schedule_args );
				}
			}

			bp_core_add_message( __( 'Feed sync settings updated', 'bp-event-organiser' ) );
			bp_core_redirect( bp_get_group_permalink( groups_get_current_group() ) . 'admin/' . $this->get_events_slug() . '/' );
		}

		// Display hooks.
		add_action( 'wp_enqueue_scripts',  array( $this, 'enqueue_assets' ) );
		add_action( 'bp_after_group_body', array( $this, 'manage_events_display_ical_feeds' ) );
	}

	/**
	 * Display iCal sync fields.
	 *
	 * We're running this on 'bp_after_group_body' because EO has its own form and
	 * you cannot nest forms within a form.
	 */
	public function manage_events_display_ical_feeds() {
		/*
		 * EO's iCal sync display method is meant for the admin area.
		 *
		 * And requires submit_button(), which is an admin-only function. Since we
		 * already have some form of admin abstraction for editing single events,
		 * let's use that.
		 */
		require_once BPEO_PATH . 'includes/wp-frontend-admin-screen/abstraction-metabox.php';

		// Only show group admins for Assign Events dropdown and not all users.
		// @todo Maybe add group mods as well? Or all group members?
		add_filter( 'wp_dropdown_users_args', function( $retval ) {
			$retval['include'] = bp_group_admin_ids( groups_get_current_group(), 'array' );
			return $retval;
		} );

		// Ensure we fetch the group's custom feed schedule.
		add_filter( 'pre_option_eventorganiser_feed_schedule', function( $retval ) {
			$schedule = groups_get_groupmeta( bp_get_current_group_id(), 'eventorganiser_feed_schedule' );
			if ( ! empty( $schedule ) ) {
				return $schedule;
			} else {
				return 0;
			}
		} );

		// Only fetch feeds for the current group.
		add_action( 'pre_get_posts', array( $this, 'filter_feeds_by_group' ) );

		// Change some strings.
		add_filter( 'gettext', array( $this, 'gettext_overrides' ), 10, 3 );

		// Use EO's iCal sync display method.
		$eo = EO_Sync_Ical::init();
		ob_start();
		$eo->display_feeds();
		$contents = ob_get_clean();

		// Some markup adjustments.
		$contents = str_replace( '&lt;br/&gt;', '<br />', $contents );

		/**
		 * Filters the markup from EO's iCal feeds form.
		 *
		 * @since 1.1.0
		 *
		 * @param string $contents HTML markup.
		 */
		$contents = apply_filters( 'bpeo_display_ical_feeds_contents', $contents );

		// Container classes.
		$classes = array( 'manage-icalendar', 'manage-icalendar-feeds' );
		if ( true !== bpeo_is_import_categories_enabled() ) {
			$classes[] = 'disable-eo-categories';
		}
		if ( true !== bpeo_is_import_venues_enabled() ) {
			$classes[] = 'disable-eo-venues';
		}

		// Output our table.
		printf( '<div class="%1$s">%2$s</div>', implode( ' ', $classes ), $contents );

		// Inline JS. Hide "Assign events" field if only one person.
		$js = "<script>
			jQuery(function(){
				var assign = jQuery('#col-left #feed-organiser');
				if ( 1 === assign.find('option').length ) {
					assign.closest('.form-field').hide();

					// Hide the quick edit field as well.
					jQuery('#col-right #feed-organiser').closest('label').hide();
				}
			});
			</script>

		";
		echo $js;

		// Cleanup!
		remove_filter( 'gettext', array( $this, 'gettext_overrides' ), 10, 3 );
		remove_action( 'pre_get_posts', array( $this, 'filter_feeds_by_group' ) );
	}

	public function enqueue_assets() {
		wp_enqueue_style(
			'bpeo-manage-group-ical',
			BUDDYPRESS_EVENT_ORGANISER_URL . 'assets/css/group-manage-ical.css',
			array( 'list-tables', 'edit', 'forms' ),
			'20191105'
		);
	}

	/** CRONJOB SYNC ********************************************************/

	public function sync( $group_id ) {
		$feeds = eo_get_feeds( array(
			'fields'     => 'ids',
			'meta_key'   => '_eventorganiser_feed_group_id',
			'meta_value' => $group_id
		) );

		if ( $feeds ) {
			foreach ( $feeds as $feed_id ) {
				set_time_limit( 600 );
				eo_fetch_feed( $feed_id );
			}
		}

		die();
	}

	/**
	 * Removes iCalendar cron job for the group on group deletion.
	 *
	 * @param BP_Groups_Group $group Group being deleted.
	 */
	public function delete_cron_on_group_delete( $group ) {
		$schedule_args = array( $group->id );

		$next = wp_next_scheduled( 'bpeo_group_ical_sync', $schedule_args );
		if ( false !== $next ) {
			wp_unschedule_event( $next, 'bpeo_group_ical_sync', $schedule_args );
		}
	}

	/** SAVE HOOKS **********************************************************/

	public function save_group_id_to_feed( $meta_id, $post_id, $meta_key ) {
		if ( '_eventorganiser_feed_source' !== $meta_key ) {
			return;
		}

		// Make sure we're doing this from a BP group page.
		$group = groups_get_current_group();
		if ( empty( $group->id ) ) {
			return;
		}

		// Save the group ID into the feed's meta.
		update_post_meta( $post_id, '_eventorganiser_feed_group_id', $group->id );

		// Also save a random feed color.
		// @todo Make this customizable?
		update_post_meta( $post_id, '_eventorganiser_feed_color', bpeo_get_item_calendar_color( $feed_id, 'feed' ) );
	}

	public function add_group_to_synced_event( $event_id, $event, $feed_id ) {
		$group_id = (int) get_post_meta( $feed_id, '_eventorganiser_feed_group_id', true );
		$group    = groups_get_group( $group_id );

		// Sanity check.
		if ( empty( $group->id ) ) {
			return;
		}

		// Connect the event to the group.
		bpeo_connect_event_to_group( $event_id, $group_id );

		// Also change event status to private if group isn't public.
		$feed_status = get_post_meta( $feed_id, '_eventorganiser_feed_status', true );
		if ( '0' === $feed_status && 'public' !== bp_get_group_status( $group ) ) {
			wp_update_post( array(
				'ID'          => $event_id,
				'post_status' => 'private'
			) );
		}

		// Re-enable term saving.
		$this->reenable_term_saving();
	}

	/**
	 * AJAX modifications.
	 *
	 * We need to bypass the 'manage_options' capability and modify some strings.
	 */
	public function ajax_mods() {
		// If not on our "Manage > Events" page, bail.
		if ( true !== ( bp_is_groups_component() && bp_is_current_action( 'admin' ) && bp_is_action_variable( $this->get_events_slug(), 0 ) ) ) {
			return;
		}

		if ( ! bp_is_item_admin() ) {
			return;
		}

		// Bypass 'manage_options' capability so we can save our settings.
		add_filter( 'map_meta_cap', array( $this, 'pass_manage_options_cap' ), 10, 4 );

		// Change some strings.
		add_filter( 'gettext', array( $this, 'gettext_overrides' ), 10, 3 );
	}

	/**
	 * Disables term saving and activity creation.
	 *
	 * Terms are disabled if we've blocked categories or venues from being
	 * imported.  Activity creation for the imported event is disabled to prevent
	 * flooding the group stream.
	 *
	 * @todo Maybe turn activity creation back on?
	 *
	 * @param array $retval Meta key map array.
	 */
	public function disable_term_and_activity_saving( $retval ) {
		add_filter( 'pre_insert_term',         array( $this, 'block_term_saving' ), 10, 2 );
		add_action( 'bp_activity_before_save', array( $this, 'block_activity_saving' ), 0 );

		return $retval;
	}

	public function reenable_term_saving() {
		remove_filter( 'pre_insert_term', array( $this, 'block_term_saving' ), 10 );
	}

	/* DISPLAY HOOKS ********************************************************/

	public function filter_fullcalendar_query( $retval ) {
		if ( empty( buddypress()->bpeo_querystring ) ) {
			return $retval;
		}

		// Parse our stashed querystring.
		parse_str( buddypress()->bpeo_querystring, $qs );

		if ( ! empty( $qs['ical_feed'] ) ) {
			$retval['meta_key']   = '_eventorganiser_feed';
			$retval['meta_value'] = (int) $qs['ical_feed'];
		}

		return $retval;
	}

	public function fullcalendar_feed_background_color( $retval, $post_id ) {
		if ( bp_is_group() && bp_is_current_action( $this->get_events_slug() ) ) {
			$feed_id = get_post_meta( $post_id, '_eventorganiser_feed', true );

			// Sanity check.
			if ( empty( $feed_id ) ) {
				return $retval;
			}

			// See if our custom color exists, if so use it.
			$color = get_post_meta( $feed_id, '_eventorganiser_feed_color', true );
			if ( ! empty( $color ) ) {
				$retval = "#{$color}";
			}
		}

		return $retval;
	}

	public function filter_filter_title( $retval ) {
		if ( empty( $_GET['ical_feed'] ) ) {
			return $retval;
		}

		return sprintf( __( "Filtered by feed '%s'", 'bp-event-organiser' ), esc_html( get_the_title( (int) $_GET['ical_feed'] ) ) );
	}

	public function show_external_feed_data_in_event() {
		// Show this only in groups for now...
		if ( ! bp_is_group() ) {
			return;
		}

		$feed = get_post_meta( get_the_ID(), '_eventorganiser_feed', true );
		if ( empty( $feed ) ) {
			return;
		}

		printf( '<li><strong>' . esc_html( 'Imported from:', 'bp-event-organiser' ) . '</strong> %s</li>',
			sprintf( '<a href="%1$s">%2$s</a>',
				esc_url( add_query_arg( 'ical_feed', (int) $feed, bpeo_get_group_permalink() ) ),
				esc_html( get_the_title( (int) $feed ) )
			)
		);
	}

	/* MISC CALLBACKS / HELPERS *********************************************/

	public function filter_feeds_by_group( $q ) {
		$q->set( 'meta_key',   '_eventorganiser_feed_group_id' );
		$q->set( 'meta_value', bp_get_current_group_id() );
	}

	public function pass_manage_options_cap( $caps, $cap, $user_id, $args ) {
		if ( ! is_user_logged_in() || 'manage_options' !== $cap ) {
			return $caps;
		}

		return array( 'exist' );
	}

	public function block_term_saving( $retval, $taxonomy ) {
		if ( true !== bpeo_is_import_venues_enabled() && 'event-venue' === $taxonomy ) {
			return new WP_Error( 'term_disabled', __( 'Event venues are disabled', 'bp-event-organiser' ) );
		}

		if ( true !== bpeo_is_import_categories_enabled() && 'event-category' === $taxonomy ) {
			return new WP_Error( 'term_disabled', __( 'Event categories are disabled', 'bp-event-organiser' ) );
		}

		return $retval;
	}

	/**
	 * Block activity items from being created.
	 *
	 * To block activity items, we wipe out the activity's component property.
	 * BuddyPress will then prevent the item from being created.
	 *
	 * @param object $activity Activity item before being saved.
	 */
	public function block_activity_saving( $activity ) {
		$activity->component = false;
	}

	public function gettext_overrides( $translated_text, $untranslated_text, $domain ) {
		switch ( $untranslated_text ) {
			case 'iCal Feeds' :
				$translated_text = __( 'Manage iCalendar Feeds', 'bp-event-organiser' );
				break;

			case 'Event status' :
			case 'Event Status' :
				$translated_text = __( 'Event privacy', 'bp-event-organiser' );
				break;

			case 'Use status specified in feed' :
				$translated_text = __( 'Inherit group privacy', 'bp-event-organiser' );
				break;

			case 'Published' :
				$translated_text = __( 'Public', 'bp-event-organiser' );
				break;
		}

		return $translated_text;
	}

	protected function get_events_slug() {
		/** This filter is documented in /bp-event-organiser-groups.php */
		$slug = apply_filters( 'bpeo_extension_slug', bpeo_get_events_slug() );

		return $slug;
	}
}