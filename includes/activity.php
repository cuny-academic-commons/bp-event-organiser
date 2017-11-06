<?php

/**
 * Activity component integration.
 */

/**
 * Create activity on event save.
 *
 * The 'save_post' hook fires both on insert and update, so we use this function as a router.
 *
 * Run late to ensure that group connections have been set.
 *
 * @param int $event_id ID of the event.
 */
function bpeo_create_activity_for_event( $event_id, $event = null, $update = null ) {
	if ( is_null( $event ) ) {
		$event = get_post( $event_id );
	}

	// Skip auto-drafts and other post types.
	if ( 'event' !== $event->post_type ) {
		return;
	}

	// Skip post statuses other than 'publish' and 'private' (the latter is for non-public groups).
	if ( ! in_array( $event->post_status, array( 'publish', 'private' ), true ) ) {
		return;
	}

	// Hack: distinguish 'create' from 'edit' by comparing post_date and post_modified.
	if ( 'before_delete_post' === current_action() ) {
		$type = 'bpeo_delete_event';
	} elseif ( $event->post_date === $event->post_modified ) {
		$type = 'bpeo_create_event';
	} else {
		$type = 'bpeo_edit_event';
	}

	// Existing activity items for this event.
	$activities = bpeo_get_activity_by_event_id( $event_id );

	// There should never be more than one top-level create item.
	if ( 'bpeo_create_event' === $type ) {
		$create_items = array();
		foreach ( $activities as $activity ) {
			if ( 'bpeo_create_event' === $activity->type && 'events' === $activity->component ) {
				return;
			}
		}
	}

	// Prevent edit floods.
	if ( 'bpeo_edit_event' === $type ) {

		if ( $activities ) {

			// Just in case.
			$activities = bp_sort_by_key( $activities, 'date_recorded' );
			$last_activity = end( $activities );

			/**
			 * Filters the number of seconds in the event edit throttle.
			 *
			 * This prevents activity stream flooding by multiple edits of the same event.
			 *
			 * @param int $throttle_period Defaults to 6 hours.
			 */
			$throttle_period = apply_filters( 'bpeo_event_edit_throttle_period', 6 * HOUR_IN_SECONDS );
			if ( ( time() - strtotime( $last_activity->date_recorded ) ) < $throttle_period ) {
				return;
			}
		}
	}

	switch ( $type ) {
		case 'bpeo_create_event' :
			$recorded_time = $event->post_date_gmt;
			break;
		case 'bpeo_edit_event' :
			$recorded_time = $event->post_modified_gmt;
			break;
		default :
			$recorded_time = bp_core_current_time();
			break;
	}

	$hide_sitewide = 'publish' !== $event->post_status;

	$activity_args = array(
		'component' => 'events',
		'type' => $type,
		'user_id' => $event->post_author, // @todo Event edited by non-author?
		'primary_link' => get_permalink( $event ),
		'secondary_item_id' => $event_id, // Leave 'item_id' blank for groups.
		'recorded_time' => $recorded_time,
		'hide_sitewide' => $hide_sitewide,
	);

	bp_activity_add( $activity_args );

	do_action( 'bpeo_create_event_activity', $activity_args, $event );
}
add_action( 'save_post', 'bpeo_create_activity_for_event', 20, 3 );
//add_action( 'before_delete_post', 'bpeo_create_activity_for_event' );

/**
 * Get activity items associated with an event ID.
 *
 * @param int $event_id ID of the event.
 * @return array Array of activity items.
 */
function bpeo_get_activity_by_event_id( $event_id ) {
	$a = bp_activity_get( array(
		'filter_query' => array(
			'relation' => 'AND',
			array(
				'column' => 'component',
				'value' => array( 'groups', 'events' ),
				'compare' => 'IN',
			),
			array(
				'column' => 'type',
				'value' => array( 'bpeo_create_event', 'bpeo_edit_event', 'bpeo_delete_event' ),
				'compare' => 'IN',
			),
			array(
				'column' => 'secondary_item_id',
				'value' => $event_id,
				'compare' => '=',
			),
		),
		'show_hidden' => true,
	) );

	return $a['activities'];
}

/**
 * Register activity actions and format callbacks.
 */
function bpeo_register_activity_actions() {
	bp_activity_set_action(
		'events',
		'bpeo_create_event',
		__( 'Events created', 'bp-event-organiser' ),
		'bpeo_activity_action_format',
		__( 'Events created', 'buddypress' ),
		array( 'activity', 'member', 'group', 'member_groups' )
	);

	bp_activity_set_action(
		'events',
		'bpeo_edit_event',
		__( 'Events edited', 'bp-event-organiser' ),
		'bpeo_activity_action_format',
		__( 'Events edited', 'buddypress' ),
		array( 'activity', 'member', 'group', 'member_groups' )
	);

	bp_activity_set_action(
		'events',
		'bpeo_delete_event',
		__( 'Events deleted', 'bp-event-organiser' ),
		'bpeo_activity_action_format',
		__( 'Events deleted', 'buddypress' ),
		array( 'activity', 'member', 'group', 'member_groups' )
	);
}
add_action( 'bp_register_activity_actions', 'bpeo_register_activity_actions' );

/**
 * Format activity action strings.
 */
function bpeo_activity_action_format( $action, $activity ) {
	global $_bpeo_recursing_activity;

	if ( ! empty( $_bpeo_recursing_activity ) ) {
		return $action;
	}

	$event = get_post( $activity->secondary_item_id );

	// Sanity check - mainly for unit tests.
	if ( ! ( $event instanceof WP_Post ) || 'event' !== $event->post_type ) {
		return $action;
	}

	$user_url = bp_core_get_user_domain( $activity->user_id );
	$user_name = bp_core_get_user_displayname( $activity->user_id );
	$event_url = get_permalink( $event );
	$event_name = $event->post_title;

	switch ( $activity->type ) {
		case 'bpeo_create_event' :
			/* translators: 1: link to user, 2: link to event */
			$base = __( '%1$s created the event %2$s', 'bp-event-organiser' );
			$event_text = sprintf( '<a href="%s">%s</a>', esc_url( $event_url ), esc_html( $event_name ) );
			break;
		case 'bpeo_edit_event' :
			/* translators: 1: link to user, 2: link to event */
			$base = __( '%1$s edited the event %2$s', 'bp-event-organiser' );
			$event_text = sprintf( '<a href="%s">%s</a>', esc_url( $event_url ), esc_html( $event_name ) );
			break;
		case 'bpeo_delete_event' :
			/* translators: 1: link to user, 2: link to event */
			$base = __( '%1$s edited the event %2$s', 'bp-event-organiser' );
			$event_text = esc_html( $event_name );
			break;
	}

	$original_action = $action;

	$action = sprintf(
		$base,
		sprintf( '<a href="%s">%s</a>', esc_url( $user_url ), esc_html( $user_name ) ),
		$event_text
	);

	/**
	 * Filters the activity action for an event.
	 *
	 * The groups component uses this hook to add group-specific information to the action.
	 *
	 * @param string $action          Action string.
	 * @param object $activity        Activity object.
	 * @param string $original_action Action string as originally passed to the object.
	 */
	return apply_filters( 'bpeo_activity_action', $action, $activity, $original_action );
}

/**
 * Remove event-related duplicates from activity streams.
 *
 */
function bpeo_remove_duplicates_from_activity_stream( $activity, $r, $iterator = 0 ) {
	global $_bpeo_recursing_activity;

	// Get a list of queried activity IDs before we start removing.
	$queried_activity_ids = wp_list_pluck( $activity['activities'], 'id' );

	// Make a list of all 'bpeo_' results, sorted by type and event ID.
	$eas = array();
	foreach ( $activity['activities'] as $a_index => $a ) {
		if ( 0 === strpos( $a->type, 'bpeo_' ) ) {
			if ( ! isset( $eas[ $a->type ] ) ) {
				$eas[ $a->type ] = array();
			}

			if ( ! isset( $eas[ $a->type ][ $a->secondary_item_id ] ) ) {
				$eas[ $a->type ][ $a->secondary_item_id ] = array();
			}

			$eas[ $a->type ][ $a->secondary_item_id ][] = $a_index;
		}
	}

	// Find cases of duplicates.
	$removed = 0;
	foreach ( $eas as $type => $events ) {
		foreach ( $events as $event_id => $a_indexes ) {
			// No dupes for this event.
			if ( count( $a_indexes ) <= 1 ) {
				continue;
			}

			/*
			 * Identify the "primary" activity:
			 * - Prefer the "canonical" activity if available (component=events)
			 * - Otherwise just pick the first one
			 */
			$primary_a_index = reset( $a_indexes );
			foreach ( $a_indexes as $a_index ) {
				if ( 'events' === $activity['activities'][ $a_index ]->component ) {
					$primary_a_index = $a_index;
					break;
				}
			}

			// Remove all items but the primary.
			foreach ( $a_indexes as $a_index ) {
				if ( $a_index !== $primary_a_index ) {
					unset( $activity['activities'][ $a_index ] );
					$removed++;
				}
			}
		}
	}

	if ( $removed && $iterator <= 5 ) {
		// Backfill to correct per_page.
		$deduped_activity_count  = count( $activity['activities'] );
		$original_activity_count = count( $queried_activity_ids );
		while ( $deduped_activity_count < $original_activity_count ) {
			$backfill_args = $r;

			// Offset for the originally queried activities.
			$exclude = (array) $r['exclude'];
			$backfill_args['exclude'] = array_merge( $exclude, $queried_activity_ids );

			// In case of more reduction due to further duplication, fetch a generous number.
			$backfill_args['per_page'] = $removed + 10;

			$backfill_args['update_meta_cache'] = false;
			$backfill_args['display_comments'] = false;

			$_bpeo_recursing_activity = true;
			add_filter( 'bp_activity_set_' . $r['scope'] . '_scope_args', 'bpeo_override_activity_scope_args', 20, 2 );

			$backfill = bp_activity_get( $backfill_args );

			unset( $_bpeo_recursing_activity );
			remove_filter( 'bp_activity_set_' . $r['scope'] . '_scope_args', 'bpeo_override_activity_scope_args', 20, 2 );

			/*
			 * If the number of backfill items returned is less than the number requested, it means there
			 * are no more activity items to query after this. Set a flag so that we override the count
			 * logic and break out of the loop.
			 */
			$break_early = false;
			if ( count( $backfill['activities'] ) < $backfill_args['per_page'] ) {
				$break_early = true;
			}

			$activity['activities'] = array_merge( $activity['activities'], $backfill['activities'] );
			$activity['total'] += $backfill['total'];

			// Backfill may duplicate existing items, so we run the whole works through this function again.
			$activity = bpeo_remove_duplicates_from_activity_stream( $activity, $r, $iterator + 1 );

			// If we're left with more activity than we need, trim it down.
			if ( count( $activity['activities'] > $original_activity_count ) ) {
				$activity['activities'] = array_slice( $activity['activities'], 0, $original_activity_count );
			}

			// Break early if we're out of activity to backfill.
			if ( $break_early ) {
				break;
			}

			$deduped_activity_count += count( $activity['activities'] );
		}
	}

	return $activity;
}

function bpeo_override_activity_scope_args( $args, $r ) {
	$args['override']['display_comments'] = false;
	$args['override']['update_meta_cache'] = false;
	return $args;
}

/**
 * Prefetch event data into the cache at the beginning of an activity loop.
 *
 * @param array $activities
 */
function bpeo_prefetch_event_data( $activities ) {
	$event_ids = array();

	if ( empty( $activities ) ) {
		return $activities;
	}

	foreach ( $activities as $activity ) {
		if ( 0 === strpos( $activity->type, 'bpeo_' ) ) {
			$event_ids[] = $activity->secondary_item_id;
		}
	}

	if ( ! empty( $event_ids ) ) {
		_prime_post_caches( $event_ids, true, true );
	}

	return $activities;
}
add_action( 'bp_activity_prefetch_object_data', 'bpeo_prefetch_event_data' );

/**
 * Hook the duplicate-removing logic.
 */
function bpeo_hook_duplicate_removing_for_activity_template( $args ) {
	add_filter( 'bp_activity_get', 'bpeo_remove_duplicates_from_activity_stream', 10, 2 );
	return $args;
}
add_filter( 'bp_before_has_activities_parse_args', 'bpeo_hook_duplicate_removing_for_activity_template' );

/**
 * Unhook the duplicate-removing logic.
 */
function bpeo_unhook_duplicate_removing_for_activity_template( $retval ) {
	remove_filter( 'bp_activity_get', 'bpeo_remove_duplicates_from_activity_stream', 10, 2 );
	return $retval;
}
add_filter( 'bp_has_activities', 'bpeo_unhook_duplicate_removing_for_activity_template' );

/** EVENT COMMENT SYNCHRONIZATION ****************************************/

/**
 * Syncs activity comments and posts them back as event comments.
 *
 * Note: This is only a one-way sync - activity comments -> event comment.
 *
 * For event comment -> activity comment, see {@link bp_activity_post_type_comment()}.
 *
 * @param int    $comment_id      The activity ID for the posted activity comment.
 * @param array  $params          Parameters for the activity comment.
 * @param object $parent_activity Parameters of the parent activity item (in this case, the event post).
 */
function bpeo_sync_add_from_activity_comment( $comment_id, $params, $parent_activity ) {
	// if parent activity isn't a post type having the buddypress-activity support, stop now!
	if ( ! bp_activity_type_supports( $parent_activity->type, 'post-type-comment-tracking' ) ) {
		//return;
	}

	// Do not sync if the activity comment was marked as spam.
	$activity = new BP_Activity_Activity( $comment_id );
	if ( $activity->is_spam ) {
		return;
	}

	// Get userdata.
	if ( $params['user_id'] == bp_loggedin_user_id() ) {
		$user = buddypress()->loggedin_user->userdata;
	} else {
		$user = bp_core_get_core_userdata( $params['user_id'] );
	}

	// Get associated post type and set default comment parent
	$post_type      = bp_activity_post_type_get_tracking_arg( $parent_activity->type, 'post_type' );
	$comment_parent = 0;

	// See if a parent WP comment ID exists.
	if ( ! empty( $params['parent_id'] ) && ! empty( $post_type ) ) {
		$comment_parent = bp_activity_get_meta( $params['parent_id'], "bpeo_{$post_type}_comment_id" );
	}

	// Comment args.
	$args = array(
		'comment_post_ID'      => $parent_activity->secondary_item_id,
		'comment_author'       => bp_core_get_user_displayname( $params['user_id'] ),
		'comment_author_email' => $user->user_email,
		'comment_author_url'   => bp_core_get_user_domain( $params['user_id'], $user->user_nicename, $user->user_login ),
		'comment_content'      => $params['content'],
		'comment_type'         => '',
		'comment_parent'       => (int) $comment_parent,
		'user_id'              => $params['user_id'],
		'comment_approved'     => 1
	);

	// Prevent separate activity entry being made.
	remove_action( 'comment_post', 'bp_activity_post_type_comment', 10 );

	// Handle timestamps for the WP comment after we've switched to the blog.
	$args['comment_date']     = current_time( 'mysql' );
	$args['comment_date_gmt'] = current_time( 'mysql', 1 );

	// Post the comment.
	$post_comment_id = wp_insert_comment( $args );

	// Add meta to comment.
	add_comment_meta( $post_comment_id, 'bp_activity_comment_id', $comment_id );

	// Add meta to activity comment.
	if ( ! empty( $post_type ) ) {
		bp_activity_update_meta( $comment_id, "bpeo_event_comment_id", $post_comment_id );
	}

	// Resave activity comment with WP comment permalink.
	//
	// in bp_events_activity_comment_permalink(), we change activity comment
	// permalinks to use the post comment link
	//
	// @todo since this is done after AJAX posting, the activity comment permalink
	// doesn't change on the front end until the next page refresh.
	$resave_activity = new BP_Activity_Activity( $comment_id );
	$resave_activity->primary_link = get_comment_link( $post_comment_id );

	/**
	 * Now that the activity id exists and the post comment was created, we don't need to update
	 * the content of the comment as there are no chances it has evolved.
	 */
	remove_action( 'bp_activity_before_save', 'bpeo_sync_activity_edit_to_post_comment', 20 );

	$resave_activity->save();

	// Add the edit activity comment hook back.
	add_action( 'bp_activity_before_save', 'bpeo_sync_activity_edit_to_post_comment', 20 );

	// Add the comment hook back.
	add_action( 'comment_post', 'bp_activity_post_type_comment', 10, 2 );

	/**
	 * Fires after activity comments have been synced and posted as event comments.
	 *
	 * @since 2.0.0
	 *
	 * @param int    $comment_id      The activity ID for the posted activity comment.
	 * @param array  $args            Array of args used for the comment syncing.
	 * @param object $parent_activity Parameters of the event post parent activity item.
	 * @param object $user            User data object for the event comment.
	 */
	do_action( 'bpeo_sync_add_from_activity_comment', $comment_id, $args, $parent_activity, $user );
}
add_action( 'bp_activity_comment_posted', 'bpeo_sync_add_from_activity_comment', 10, 3 );

function bpeo_allow_event_activity_comments( $can_comment, $activity_type ) {
	global $activities_template;
	$activity = $activities_template->activity;
	if ( $activity_type !== 'bpeo_create_event' ) {
		return $can_comment;
	} else if ( comments_open( $activity->secondary_item_id ) ) {
		return true;
	} else {
		return $can_comment;
	}
}
add_filter( 'bp_activity_can_comment', 'bpeo_allow_event_activity_comments', 10, 2 );

/**
 * Set up the tracking arguments for the 'post' post type.
 * 
 * @see bp_activity_get_post_type_tracking_args() for information on parameters.
 *
 * @param object|null $params    Tracking arguments.
 * @param string|int  $post_type Post type to track.
 * @return object|null
 */
function bp_events_register_post_tracking_args( $params = null, $post_type = 0 ) {
	if ( $post_type !== 'event' || $post_type !== 'bpeo_create_event' )
		return $params;

	// Set specific params for the 'event' post type.
	$params->component_id    = 'bpeo';
	$params->action_id       = 'new_event';
	$params->admin_filter    = __( 'New event created', 'buddypress' );
	$params->contexts        = array( 'activity', 'member' );
	$params->position		 = 5;

	if ( post_type_supports( $post_type, 'comments' ) ) {
		$params->comment_action_id = 'new_event_comment';
		$params->comments_tracking = new stdClass();
		$params->comments_tracking->component_id    = 'bpeo';
		$params->comments_tracking->action_id       = 'new_event_comment';
		$params->comments_tracking->admin_filter    = __( 'New event comment posted', 'buddypress' );
		$params->comments_tracking->front_filter    = __( 'Comments', 'buddypress' );
		$params->comments_tracking->contexts        = array( 'activity', 'member' );
		$params->comments_tracking->position        = 10;
	}

	return $params;
}
add_filter( 'bp_activity_get_post_type_tracking_args', 'bp_events_register_post_tracking_args', 10, 2 );

/**
 * Deletes the event comment when the associated activity comment is deleted.
 *
 * Note: This is hooked on the 'bp_activity_delete_comment_pre' filter instead
 * of the 'bp_activity_delete_comment' action because we need to fetch the
 * activity comment children before they are deleted.
 *
 *
 * @param bool $retval             Whether BuddyPress should continue or not.
 * @param int  $parent_activity_id The parent activity ID for the activity comment.
 * @param int  $activity_id        The activity ID for the pending deleted activity comment.
 * @param bool $deleted            Whether the comment was deleted or not.
 * @return bool
 */
function bpeo_sync_delete_from_activity_comment( $retval, $parent_activity_id, $activity_id, &$deleted ) {
	$parent_activity = new BP_Activity_Activity( $parent_activity_id );

	// if parent activity isn't a post type having the buddypress-activity support, stop now!
	if ( ! bp_activity_type_supports( $parent_activity->type, 'post-type-comment-tracking' ) ) {
		//return $retval;
	}

	// Fetch the activity comments for the activity item.
	$activity = bp_activity_get( array(
		'in'               => $activity_id,
		'display_comments' => 'stream',
		'spam'             => 'all',
	) );

	// Get all activity comment IDs for the pending deleted item.
	$activity_ids   = bp_activity_recurse_comments_activity_ids( $activity );
	$activity_ids[] = $activity_id;

	// Remove associated event comments.
	bpeo_remove_associated_event_comments( $activity_ids, current_user_can( 'moderate_comments' ) );

	// Rebuild activity comment tree
	// emulate bp_activity_delete_comment().
	BP_Activity_Activity::rebuild_activity_comment_tree( $parent_activity_id );

	// Avoid the error message although the comments were successfully deleted
	$deleted = true;

	// We're overriding the default bp_activity_delete_comment() functionality
	// so we need to return false.
	return false;
}
add_filter( 'bp_activity_delete_comment_pre', 'bpeo_sync_delete_from_activity_comment', 10, 4 );

/**
 * Updates the event comment when the associated activity comment is edited.
 *
 *
 * @param BP_Activity_Activity $activity The activity object.
 */
function bp_blogs_sync_activity_edit_to_event_comment( BP_Activity_Activity $activity ) {
	// This is a new entry, so stop!
	// We only want edits!
	if ( empty( $activity->id ) ) {
		return;
	}

	// fetch parent activity item
	$parent_activity = new BP_Activity_Activity( $activity->item_id );

	// if parent activity isn't a post type having the buddypress-activity support for comments, stop now!
	if ( ! bp_activity_type_supports( $parent_activity->type, 'post-type-comment-tracking' ) ) {
		//return;
	}

	$post_type = bp_activity_post_type_get_tracking_arg( $parent_activity->type, 'post_type' );

	// No associated post type for this activity comment, stop.
	if ( ! $post_type ) {
		return;
	}

	// Try to see if a corresponding blog comment exists.
	$post_comment_id = bp_activity_get_meta( $activity->id, "bpeo_event_comment_id" );

	if ( empty( $post_comment_id ) ) {
		return;
	}

	// Get the comment status
	$post_comment_status = wp_get_comment_status( $post_comment_id );
	$old_comment_status  = $post_comment_status;

	// No need to edit the activity, as it's the activity who's updating the comment
	remove_action( 'transition_comment_status', 'bp_activity_transition_post_type_comment_status', 10 );
	remove_action( 'bp_activity_post_type_comment', 'bpeo_comment_sync_activity_comment', 10 );

	if ( 1 === $activity->is_spam && 'spam' !== $post_comment_status ) {
		wp_spam_comment( $post_comment_id );
	} elseif ( ! $activity->is_spam ) {
		if ( 'spam' === $post_comment_status  ) {
			wp_unspam_comment( $post_comment_id );
		} elseif ( 'trash' === $post_comment_status ) {
			wp_untrash_comment( $post_comment_id );
		} else {
			// Update the blog post comment.
			wp_update_comment( array(
				'comment_ID'       => $post_comment_id,
				'comment_content'  => $activity->content,
			) );
		}
	}

	// Restore actions
	add_action( 'transition_comment_status',     'bp_activity_transition_post_type_comment_status', 10, 3 );
	add_action( 'bp_activity_post_type_comment', 'bpeo_comment_sync_activity_comment',          10, 4 );
}
add_action( 'bp_activity_before_save', 'bpeo_sync_activity_edit_to_event_comment', 20 );

/**
 * When an event is trashed, remove each comment's associated activity meta.
 *
 * When an event is trashed and later untrashed, we currently don't reinstate
 * activity items for these comments since their activity entries are already
 * deleted when initially trashed.
 *
 * Since these activity entries are deleted, we need to remove the deleted
 * activity comment IDs from each comment's meta when an event is trashed.
 *
 *
 * @param int   $post_id  The post ID of the event.
 * @param array $comments Array of comment statuses. The key is comment ID, the
 *                        value is the $comment->comment_approved value.
 */
function bp_blogs_remove_activity_meta_for_trashed_comments( $post_id = 0, $comments = array() ) {
	if ( ! empty( $comments ) && get_post_type( $post_id ) === 'event' ) {
		foreach ( array_keys( $comments ) as $comment_id ) {
			delete_comment_meta( $comment_id, 'bp_activity_comment_id' );
		}
	}
}
add_action( 'trashed_post_comments', 'bpeo_remove_activity_meta_for_trashed_comments', 10, 2 );

function bpeotest() {
	global $wpdb;
	$i = 8;
	var_dump($wpdb);
	exit;
}
//add_action( 'init', 'bpeotest' );