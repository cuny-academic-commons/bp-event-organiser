=== BuddyPress Event Organiser ===
Contributors: cuny-academic-commons, r-a-y, boonebgorges, needle
Tags: buddypress, event, organiser, groups
Requires at least: 3.6
Tested up to: 5.4.2
Stable tag: 1.2.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Adds BuddyPress support to the [Event Organiser](https://wordpress.org/plugins/event-organiser/) plugin.


== Description ==

Extends the [Event Organiser](https://wordpress.org/plugins/event-organiser/) plugin to add support to BuddyPress members and groups.

Key features:

* Members and groups can create events and have their own event calendar.
* Events can be attached to multiple groups, but if any of those groups are public, the event becomes public.
* oEmbed support for public group calendars.

== Changelog ==

= 1.2.0 =
* For group administrators, add ability to import events from an uploaded ICS file
* For group administrators, add ability to import events from an iCalendar URL (requires Event Organiser iCal Sync premium extension)
* Allow private group iCalendar files to be publicly-accessible
* Better filterability of event content strings.
* Usability improvements for Subscribe link.
* Fix bug that could cause routing error for certain iCal slugs
* Fix bug on canonical event pages where event meta information could be displayed twice

== Installation ==

1. Extract the plugin archive
1. Upload plugin files to your `/wp-content/plugins/` directory
1. Activate the plugin through the 'Plugins' menu in WordPress
