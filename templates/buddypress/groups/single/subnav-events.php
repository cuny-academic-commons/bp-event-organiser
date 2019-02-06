<?php $wrapper = class_exists( 'BP_Nouveau' ) ? 'nav' : 'div'; ?>

<<?php echo $wrapper; ?> class="item-list-tabs no-ajax bp-navs bp-subnavs" id="subnav" role="navigation">
	<ul>
		<?php bp_get_options_nav( buddypress()->groups->current_group->slug . '_events' ); ?>
	</ul>
</<?php echo $wrapper; ?>><!-- .item-list-tabs -->

