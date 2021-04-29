<?php

/**
 * @package "Elk2Pdf" Addon for Elkarte
 * @author Spuds
 * @license BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * @version 1.0.8
 *
 */

/**
 * integrate_display_buttons hook, called from Display.controller
 *
 * - Used to add additional buttons to topic views
 */
function idb_elk2pdf()
{
	global $context, $scripturl, $txt, $user_info;

	$txt['pdf'] = 'PDF';

	// Replace the print action with PDF
	if (isset($context['normal_buttons']['print']) && !$user_info['is_guest'])
	{
		$context['normal_buttons']['print'] = array(
			'test' => 'can_print',
			'text' => 'pdf',
			'image' => 'print.png',
			'lang' => true,
			'custom' => 'rel="nofollow"',
			'class' => 'new_win',
			'url' => $scripturl . '?action=PDF;topic=' . $context['current_topic'] . '.0');
	}
}
