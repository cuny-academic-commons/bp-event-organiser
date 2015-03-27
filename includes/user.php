<?php

/**
 * Get IDs for events that should appear on a user's "My Calendar".
 *
 * @param int $user_id ID of the user.
 */
function bpeo_get_my_calendar_event_ids( $user_id ) {
	$event_ids = array();

	// Events created by me, or by friends.
	$authors = array( $user_id );

	if ( bp_is_active( 'friends' ) ) {
		$authors = array_merge( $authors, friends_get_friend_user_ids( $user_id ) );
	}

	$eids_by_author = get_posts( array(
		'post_type' => 'event',
		'fields' => 'ids',
		'showpastevents' => true,
		'author__in' => $authors,
	) );

	$event_ids = array_merge( $event_ids, $eids_by_author );

	// Events connected to my groups.
	if ( bp_is_active( 'groups' ) ) {
		$user_groups = groups_get_user_groups( $user_id );
		$group_ids = $user_groups['groups'];

		$eids_by_group = get_posts( array(
			'post_type' => 'event',
			'fields' => 'ids',
			'showpastevents' => true,
			'bp_group' => $group_ids,
		) );

		$event_ids = array_merge( $event_ids, $eids_by_group );
	}

	return $event_ids;
}

/**
 * Modify `WP_Query` requests for the 'bp_displayed_user_id' param.
 *
 * @param WP_Query Query object, passed by reference.
 */
function bpeo_filter_query_for_bp_displayed_user_id( $query ) {
	// Only modify 'event' queries.
	$post_types = $query->get( 'post_type' );
	if ( ! in_array( 'event', (array) $post_types ) ) {
		return;
	}

	$user_id = $query->get( 'bp_displayed_user_id', null );
	if ( null === $user_id ) {
		return;
	}

	// Empty user_id will always return no results.
	if ( empty( $user_id ) ) {
		$query->set( 'post__in', array( 0 ) );
		return;
	}

	// Get a list of IDs to pass to post__in.
	$event_ids = bpeo_get_my_calendar_event_ids( $user_id );

	if ( empty( $event_ids ) ) {
		$event_ids = array( 0 );
	}
	$query->set( 'post__in', $event_ids );
}
add_action( 'pre_get_posts', 'bpeo_filter_query_for_bp_displayed_user_id', 1000 );

/**
 * Modify the calendar query to include the displayed user ID.
 *
 * @param  array $query Query vars as set up by EO.
 * @return array
 */
function bpeo_filter_calendar_query_for_bp_user( $query ) {
	if ( ! bp_is_user() ) {
		return $query;
	}

	$query['bp_displayed_user_id'] = bp_displayed_user_id();

	return $query;
}
add_filter( 'eventorganiser_fullcalendar_query', 'bpeo_filter_calendar_query_for_bp_user' );

/**
 * Add author information to calendar event markup.
 *
 * @param array $event         Array of data about the event.
 * @param int   $event_id      ID of the event.
 * @param int   $occurrence_id ID of the occurrence.
 * @return array
 */
function bpeo_add_author_info_to_calendar_event( $event, $event_id, $occurrence_id ) {
	$event_obj = get_post( $event_id );
	$event['className'][] = 'eo-event-author-' . intval( $event_obj->post_author );

	return $event;
}
add_filter( 'eventorganiser_fullcalendar_event', 'bpeo_add_author_info_to_calendar_event', 10, 3 );
