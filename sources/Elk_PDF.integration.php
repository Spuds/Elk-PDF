<?php

/**
 * @package "Elk2Pdf" Addon for Elkarte
 * @author Spuds
 * @license BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * @version 1.0.3
 *
 */

if (!defined('ELK'))
	die('No access...');

/**
 * integrate_display_buttons hook, called from Display.controller
 *
 * - Used to add additional buttons to topic views
 */
function idb_elk2pdf()
{
	global $context, $scripturl;

	// Replace the print action with PDF
	$context['normal_buttons']['print'] = array(
		'test' => 'can_print',
		'text' => 'print',
		'image' => 'print.png',
		'lang' => true,
		'custom' => 'rel="nofollow"',
		'class' => 'new_win',
		'url' => $scripturl . '?action=PDF;topic=' . $context['current_topic'] . '.0');
}