<?php

/**
 * Group functionality.
 */

/**
 * Register group connection taxonomy.
 *
 * Fires at init:15 to ensure EO has a chance to register its post type first.
 */
function bpeo_register_group_connection_taxonomy() {
	register_taxonomy( 'bpeo_event_group', 'event', array(
		'public' => false,
	) );
}
add_action( 'init', 'bpeo_register_group_connection_taxonomy', 15 );

/**
 * Connect an event to a group.
 *
 * @param int $event_id ID of the event.
 * @param int $group_id ID of the group.
 * @return bool|WP_Error True on success, WP_Error on failure.
 */
function bpeo_connect_event_to_group( $event_id, $group_id ) {
	$group = groups_get_group( array( 'group_id' => $group_id ) );
	if ( ! $group->id ) {
		return new WP_Error( 'group_not_found', __( 'No group found by that ID.', 'bp-event-organiser' ) );
	}

	$event = get_post( $event_id );
	if ( ! ( $event instanceof WP_Post ) || 'event' !== $event->post_type ) {
		return new WP_Error( 'event_not_found', __( 'No event found by that ID.', 'bp-event-organiser' ) );
	}

	$set = wp_set_object_terms( $event_id, array( 'group_' . $group_id ), 'bpeo_event_group', true );

	if ( is_wp_error( $set ) || empty( $set ) ) {
		return $set;
	} else {
		return true;
	}
}

/**
 * Disconnect an event from a group.
 *
 * @param int $event_id ID of the event.
 * @param int $group_id ID of the group.
 * @return bool|WP_Error True on success, WP_Error on failure.
 */
 function bpeo_disconnect_event_from_group( $event_id, $group_id ) {
	$group = groups_get_group( array( 'group_id' => $group_id ) );
	if ( ! $group->id ) {
		return new WP_Error( 'group_not_found', __( 'No group found by that ID.', 'bp-event-organiser' ) );
	}

	$event = get_post( $event_id );
	if ( ! ( $event instanceof WP_Post ) || 'event' !== $event->post_type ) {
		return new WP_Error( 'event_not_found', __( 'No event found by that ID.', 'bp-event-organiser' ) );
	}

	$event_groups = bpeo_get_group_events( $group_id );
	if ( ! in_array( $event_id, $event_groups ) ) {
		return new WP_Error( 'event_not_found_for_group', __( 'No event found by that ID connected to this group.', 'bp-event-organiser' ) );
	}

	$removed = wp_remove_object_terms( $event_id, 'group_' . $group_id , 'bpeo_event_group' );

	return $removed;
}

/**
 * Get event IDs associated with a group.
 *
 * @param int   $group_id ID of the group.
 * @param array $args {
 *     Optional query args. All WP_Query args are accepted, along with the following.
 *     @type bool $showpastevents True to show past events, false otherwise. Default: false.
 * }
 * @return array Array of event IDs.
 */
function bpeo_get_group_events( $group_id, $args = array() ) {
	$r = array_merge( array(
		'posts_per_page' => -1,
		'showpastevents' => true,
	), $args );

	$r['fields'] = 'ids';
	$r['post_type'] = 'event';

	$r['tax_query'] = array(
		array(
			'taxonomy' => 'bpeo_event_group',
			'terms' => 'group_' . $group_id,
			'field' => 'name',
		),
	);

	$q = new WP_Query( $r );

	return $q->posts;
}

/**
 * Get group IDs associated with an event.
 *
 * @param int $event_id ID of the event.
 * @return array Array of group IDs.
 */
function bpeo_get_event_groups( $event_id ) {
	$group_terms = wp_get_object_terms( $event_id, 'bpeo_event_group' );
	$group_term_names = wp_list_pluck( $group_terms, 'name' );

	$group_ids = array();
	foreach ( $group_term_names as $group_term_name ) {
		// Trim leading 'group_'.
		$group_ids[] = intval( substr( $group_term_name, 6 ) );
	}

	return $group_ids;
}

/**
 * Modify `WP_Query` requests for the 'bp_group' param.
 *
 * @param WP_Query Query object, passed by reference.
 */
function bpeo_filter_query_for_bp_group( $query ) {
	// Only modify 'event' queries.
	$post_types = $query->get( 'post_type' );
	if ( ! in_array( 'event', (array) $post_types ) ) {
		return;
	}

	$bp_group = $query->get( 'bp_group', null );
	if ( null === $bp_group ) {
		return;
	}

	if ( ! is_array( $bp_group ) ) {
		$group_ids = array( $bp_group );
	} else {
		$group_ids = $bp_group;
	}

	// Empty array will always return no results.
	if ( empty( $group_ids ) ) {
		$query->set( 'post__in', array( 0 ) );
		return;
	}

	// Convert group IDs to a tax query.
	$tq = $query->get( 'tax_query' );
	$group_terms = array();
	foreach ( $group_ids as $group_id ) {
		$group_terms[] = 'group_' . $group_id;
	}

	$tq[] = array(
		'taxonomy' => 'bpeo_event_group',
		'terms' => $group_terms,
		'field' => 'name',
		'operator' => 'IN',
	);

	$query->set( 'tax_query', $tq );
}
add_action( 'pre_get_posts', 'bpeo_filter_query_for_bp_group' );

/**
 * Modify the calendar query to include the current group ID.
 *
 * @param  array $query Query vars as set up by EO.
 * @return array
 */
function bpeo_filter_calendar_query_for_bp_group( $query ) {
	if ( ! bp_is_group() ) {
		return $query;
	}

	$query['bp_group'] = bp_get_current_group_id();

	return $query;
}
add_filter( 'eventorganiser_fullcalendar_query', 'bpeo_filter_calendar_query_for_bp_group' );

/**
 * Filter event links on a group events page to use the group event permalink.
 *
 * @param string $retval Current event permalink
 * @return string
 */
function bpeo_calendar_filter_event_link_for_bp_group( $retval ) {
	if ( ! bp_is_group() ) {
		return $retval;
	}

	// this is to avoid requerying the event just for the post slug
	$event_url = explode( '/', untrailingslashit( $retval ) );
	$post_slug = array_pop( $event_url );

	// regenerate the post URL to account for group permalink
	return trailingslashit( bpeo_get_group_permalink() . $post_slug );
}
add_filter( 'eventorganiser_calendar_event_link', 'bpeo_calendar_filter_event_link_for_bp_group' );

/**
 * Add group information to calendar event markup.
 *
 * @param array $event         Array of data about the event.
 * @param int   $event_id      ID of the event.
 * @param int   $occurrence_id ID of the occurrence.
 * @return array
 */
function bpeo_add_group_info_to_calendar_event( $event, $event_id, $occurrence_id ) {
	foreach ( bpeo_get_event_groups( $event_id ) as $group_id ) {
		$event['className'][] = 'eo-event-bp-group-' . intval( $group_id );
	}

	return $event;
}
add_filter( 'eventorganiser_fullcalendar_event', 'bpeo_add_group_info_to_calendar_event', 10, 3 );

/**
 * Modify EO capabilities for group membership.
 *
 * @param array  $caps    Capability array.
 * @param string $cap     Capability to check.
 * @param int    $user_id ID of the user being checked.
 * @param array  $args    Miscellaneous args.
 * @return array Caps whitelist.
 */
function bpeo_event_meta_cap( $caps, $cap, $user_id, $args ) {
	// @todo Need real caching in BP for group memberships.
	if ( ! in_array( $cap, array( 'read_event' ) ) ) {
		return $caps;
	}

	$event = get_post( $args[0] );
	if ( 'event' !== $event->post_type ) {
		return $caps;
	}

	$event_groups = bpeo_get_event_groups( $event->ID );
	$user_groups = groups_get_user_groups( $user_id );

	switch ( $cap ) {
		case 'read_event' :
			if ( 'private' !== $event->post_status ) {
				// EO uses 'read', which doesn't include non-logged-in users.
				$caps = array( 'exist' );
			} elseif ( array_intersect( $user_groups['groups'], $event_groups ) ) {
				$caps = array( 'read' );
			}

			break;
	}

	return $caps;
}
add_filter( 'map_meta_cap', 'bpeo_event_meta_cap', 20, 4 );

/** TEMPLATE ************************************************************/

/**
 * Get the permalink to a group's events page.
 *
 * @param  BP_Groups_Group|int $group The group object or the group ID to fetch the group for.
 * @return string
 */
function bpeo_get_group_permalink( $group = 0 ) {
	if ( empty( $group ) ) {
		$group = groups_get_current_group();
	}

	if ( ! empty( $group ) && ! $group instanceof BP_Groups_Group && is_int( $group ) ) {
		$group = groups_get_group( array(
			'group_id'        => $group,
			'populate_extras' => false
		) );
	}

	return trailingslashit( bp_get_group_permalink( $group ) . bpeo_get_events_slug() );
}
