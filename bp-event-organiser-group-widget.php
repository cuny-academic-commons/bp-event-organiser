<?php
/*
Plugin Name: BP Event Organiser - Group Widget
Description: Embed events from one of your groups that you are a member of with this widget.
Author: CUNY Academic Commons Team
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
*/

/**
 * Registers our widget with WordPress.
 */
function bpeo_group_widget_init() {
	// Do not proceed if BuddyPress is not available
	if ( false === function_exists( 'buddypress' ) ) {
		return;
	}

	// Make sure groups component is active
	if ( false === bp_is_active( 'groups' ) ) {
		return;
	}

	// Check if BPEO is registered on the root blog
	$active_plugins = get_blog_option( bp_get_root_blog_id(), 'active_plugins' );
	if( false === in_array( 'bp-event-organiser/bp-event-organiser.php', $active_plugins, true ) ) {
		return;
	}

	// Finally, register our widget!
	register_widget( 'BPEO_Group_Widget' );
}
add_action( 'widgets_init', 'bpeo_group_widget_init' );

/**
 * Group events widget class for BP Event Organiser.
 */
class BPEO_Group_Widget extends WP_Widget {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			'bpeo-group-widget',
			__( '(BuddyPress) Group Events', 'bpeo-group-widget' ),
			array(
				'classname'   => 'widget_bpeo_group',
				'description' => __( 'Embed events from one of your groups.', 'bpeo-group-widget' )
			)
		);
	}

	/**
	 * @param array $args
	 * @param array $instance
	 */
	public function widget( $args, $instance ) {

		/**
		 * Filter the widget title.
		 *
		 * @param string $title    The widget title. Default 'My Events'.
		 * @param array  $instance An array of the widget's settings.
		 * @param mixed  $id_base  The widget ID.
		 */
		$title = apply_filters( 'widget_title', empty( $instance['title'] ) ? __( 'My Events', 'bpeo-group-widget' ) : $instance['title'], $instance, $this->id_base );

		$group_id = ! empty( $instance['group_id'] ) ? (int) $instance['group_id'] : false;
		if ( empty( $group_id ) ) {
			return;
		}

		$group = groups_get_group( array(
		    'group_id' => $group_id,
		    'populate_extras' => false,
		) );

		// Sanity check!
		if ( empty( $group ) ) {
			return;
		}

		// Group calendar
		if ( 'calendar' === $instance['type'] ) {
			$link = bp_get_group_permalink( $group ) . 'events/?embedded=true';

		// Upcoming events
		} else {
			$link = bp_get_group_permalink( $group ) . 'events/upcoming/?embedded=true';
		}

		echo $args['before_widget'];
		if ( $title ) {
			echo $args['before_title'] . $title . $args['after_title'];
		}
	?>

		<iframe src="<?php echo esc_url( $link ); ?>" width="100%"></iframe>

	<?php
		echo $args['after_widget'];		
	}

	/**
	 * @param array $new_instance
	 * @param array $old_instance
	 * @return array
	 */
	public function update( $new_instance, $old_instance ) {
		$instance = $old_instance;

		$instance['title'] = strip_tags( $new_instance['title'] );

		// Sanity check! Check if user is part of the group before saving
		$group_id = (int) $new_instance['group_id'];
		if ( groups_is_user_member( bp_loggedin_user_id(), $group_id ) ) {
			$instance['group_id'] = $group_id;
		}

		if ( in_array( $new_instance['type'], array( 'list', 'calendar' ) ) ) {
			$instance['type'] = $new_instance['type'];
		} else {
			$instance['type'] = 'list';
		}

		return $instance;
	}

	/**
	 * @param array $instance
	 */
	public function form( $instance ) {
		// Defaults
		$instance = wp_parse_args( (array) $instance, array(
			'sortby' => 'post_title',
			'title' => '',
			'group_id' => ''
		) );

		$title = esc_attr( $instance['title'] );

		$groups = groups_get_groups( array(
			'type' => 'alphabetical',
			'order' => 'ASC',
			'user_id' => bp_loggedin_user_id(),
			'per_page' => null,
			'page' => null,
			'update_meta_cache' => false,

			// Do we allow hidden groups to be embedded?
			//'show_hidden' =>
		) );

		if ( ! empty( $groups['groups'] ) ) {
	?>

			<p><label for="<?php echo $this->get_field_id('title'); ?>"><?php _e( 'Title:', 'bpeo-group-widget' ); ?></label> <input class="widefat" id="<?php echo $this->get_field_id('title'); ?>" name="<?php echo $this->get_field_name('title'); ?>" type="text" value="<?php echo $title; ?>" /></p>

			<p><label for="<?php echo $this->get_field_id('group_id'); ?>"><?php _e( 'Group:', 'bpeo-group-widget' ); ?></label>

			<select class="widefat" name="<?php echo esc_attr( $this->get_field_name( 'group_id' ) ); ?>" id="<?php echo esc_attr( $this->get_field_id( 'group_id' ) ); ?>">
				<option value="" <?php selected( $instance['group_id'], '' ); ?>><?php esc_html_e( '--- Select a group ---', 'bpeo-group-widget' ); ?></option>

				<?php foreach ( $groups['groups'] as $i => $group ) : ?>
					<option value="<?php echo esc_attr( $group->id ); ?>" <?php selected( $instance['group_id'], $group->id ); ?>><?php echo esc_html( $group->name ); ?></option>
				<?php endforeach; ?>
			</select></p>

			<p><label for="<?php echo $this->get_field_id('type'); ?>"><?php _e( 'Embed Type:', 'bpeo-group-widget' ); ?></label>

			<select class="widefat" name="<?php echo esc_attr( $this->get_field_name( 'type' ) ); ?>" id="<?php echo esc_attr( $this->get_field_id( 'type' ) ); ?>">
				<option value="list" <?php selected( $instance['type'], 'list' ); ?>><?php esc_html_e( 'List of upcoming events', 'bpeo-group-widget' ); ?></option>
				<option value="calendar" <?php selected( $instance['type'], 'calendar' ); ?>><?php esc_html_e( 'Calendar', 'bpeo-group-widget' ); ?></option>
			</select></p>

<?php
		} else {
?>

			<p><?php _e( "You are not a member of any groups.  Please join a group before attempting to embed a group's event list.", 'bpeo-group-widget' ); ?></p>

<?php
		}
	}
}