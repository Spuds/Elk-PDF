<?php

/**
 * @package "PDF" Addon for Elkarte
 * @author Spuds
 * @license BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * @version 1.0.2
 *
 */

if (!defined('ELK'))
	die('No access...');

/**
 * PDF Controller
 */
class PDF_Controller extends Action_Controller
{
	/**
	 * Entry point for this class (by default).
	 *
	 * @see Action_Controller::action_index()
	 */
	public function action_index()
	{
		$this->action_pdfpage();
	}

	/**
	 * Format a topic to be PDF friendly.
	 * Must be called with a topic specified.
	 * Accessed via ?action=topic;sa=pdfpage.
	 *
	 * @uses Pdfpage template, main sub-template.
	 * @uses print_above/print_below later without the main layer.
	 */
	public function action_pdfpage()
	{
		global $topic, $context, $board_info, $modSettings;

		// Redirect to the boardindex if no valid topic id is provided.
		if (empty($topic))
			redirectexit();

		// You have to be able to print to create PDF's
		if (!empty($modSettings['disable_print_topic']))
		{
			unset($_REQUEST['action']);
			$context['theme_loaded'] = false;
			fatal_lang_error('feature_disabled', false);
		}

		// Clear out any template layers
		$template_layers = Template_Layers::getInstance();
		$template_layers->removeAll();

		// Get the topic information.
		require_once(SUBSDIR . '/Topic.subs.php');
		$topicinfo = getTopicInfo($topic, 'starter');

		// Redirect to the boardindex if no valid topic id is provided.
		if (empty($topicinfo))
			redirectexit();

		// If the topic has poll's lets load those in
		if ($topicinfo['id_poll'] > 0 && !empty($modSettings['pollMode']) && allowedTo('poll_view'))
		{
			loadLanguage('Post');
			require_once(SUBSDIR . '/Poll.subs.php');

			loadPollContext($topicinfo['id_poll']);
		}

		// Lets "output" all that info.
		$context['board_name'] = $board_info['name'];
		$context['category_name'] = $board_info['cat']['name'];
		$context['poster_name'] = $topicinfo['poster_name'];
		$context['post_time'] = standardTime($topicinfo['poster_time'], false);
		$context['parent_boards'] = array();

		foreach ($board_info['parent_boards'] as $parent)
			$context['parent_boards'][] = $parent['name'];

		// Get all the messages in this topic
		$_GET['images'] = true;
		$context['posts'] = topicMessages($topic, false);
		$posts_id = array_keys($context['posts']);

		$context['topic_subject'] = $context['posts'][min($posts_id)]['subject'];

		// Fetch image attachments so we can print them
		if (!empty($modSettings['attachmentEnable']) && allowedTo('view_attachments'))
		{
			require_once(SUBSDIR . '/Topic.subs.php');
			$context['printattach'] = messagesAttachments(array_keys($context['posts']));
		}

		// With all the data, lets create the PDF
		$this->_create_pdf();
	}

	/**
	 * Create the PDF from the topic information
	 */
	private function _create_pdf()
	{
		global $modSettings, $forum_version, $mbname, $context;

		if (empty($modSettings['pdf_page']))
			$modSettings['pdf_page'] = 'letter';

		$modSettings['pdf_wmargin'] = 15;
		$modSettings['pdf_hmargin'] = 15;

		// Extra memory is always good with PDF creation
		setMemoryLimit('128M');

		// Core PDF functions
		require_once(EXTDIR . '/tfpdf.php');
		require_once(SUBSDIR . '/Elk_PDF.class.php');

		// Portrait, millimeter, page size (Letter, A4, etc)
		$pdf = new ElkPdf('P', 'mm', $modSettings['pdf_page']);

		// Stream handle for external images
		stream_wrapper_register("elkimg", "VariableStream");

		// Common page setup
		$pdf->SetAuthor($forum_version);
		$pdf->SetTitle($mbname);
		$pdf->SetSubject($context['topic_subject']);
		$pdf->SetMargins($modSettings['pdf_wmargin'], $modSettings['pdf_hmargin']);
		$pdf->SetLineWidth(.1);

		// Fonts we will or may use
		$pdf->AddFont('DejaVu', '', 'DejaVuSerifCondensed.ttf', true);
		$pdf->AddFont('DejaVu', 'B', 'DejaVuSerifCondensed-Bold.ttf', true);
		$pdf->AddFont('DejaVu', 'I', 'DejaVuSerifCondensed-Italic.ttf', true);

		// Start the first page and auto page counter
		$pdf->AliasNbPages('{elk_nb}');
		$pdf->begin_page($context['topic_subject']);

		// On to the posts for this topic
		$count = 0;
		foreach ($context['posts'] as $post)
		{
			// Write message header
			$pdf->message_header(html_entity_decode($post['subject']), html_entity_decode($post['member']), $post['time']);

			// Handle polls.
			if (!empty($context['poll']) && empty($count))
				$pdf->add_poll(html_entity_decode($context['poll']['question']), $context['poll']['options'], $context['allow_poll_view']);

			// Write message body.
			$pdf->write_html(preg_replace_callback('~(&#(\d{1,7}|x[0-9a-fA-F]{1,6});)~', 'fixchar__callback', $post['body']));

			// Show attachment images
			if (!empty($context['printattach'][$post['id_msg']]))
				$pdf->add_attachments($context['printattach'][$post['id_msg']]);

			$pdf->end_message();
			$count++;
		}

		// Make sure we can send
		if (!headers_sent($filename, $linenum))
		{
			// Clear anything in the buffers
			while (@ob_get_level() > 0)
				@ob_end_clean();

			// Get the PDF output
			$out = $pdf->Output('ElkArte' . date('Ymd') . '.pdf', 'S');

			// Set the output compression
			if (!empty($modSettings['enableCompressedOutput']) && strlen($out) <= 4194304)
				ob_start('ob_gzhandler');
			else
			{
				ob_start();
				header('Content-Encoding: none');
			}

			// Output content to browser
			header('Content-Type: application/pdf');

			if ($_SERVER['SERVER_PORT'] == '443' && (strpos($_SERVER['HTTP_USER_AGENT'], 'MSIE') !== false))
			{
				header('Cache-Control: must-revalidate, post-check=0, pre-check=0', true);
				header('Pragma: public', true);
			}

			header('Content-Length: ' . strlen($out));
			header('Content-disposition: inline; filename=ElkForum' . date('Ymd') . '.pdf');

			echo $out;

			// Just exit now
			obExit(false);
		}
		else
			echo "Headers already sent in $filename on line $linenum";
	}
}