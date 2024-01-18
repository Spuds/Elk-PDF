<?php

/**
 * @package "PDF" Addon for Elkarte
 * @author Spuds
 * @copyright (c) 2011-2022 Spuds
 * @license BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * @version 1.2.0
 *
 */

/**
 * PDF Controller
 */
class PDF_Controller extends Action_Controller
{
	public string $pdf_page = 'letter';
	public string $pdf_unit = 'mm';
	public string $pdf_orientation = 'P';
	public int $pdf_wmargin = 15;
	public int $pdf_hmargin = 15;

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
		{
			redirectexit();
		}

		// You have to be able to print to create PDF's
		if (!empty($modSettings['disable_print_topic']) || !allowedTo('send_topic'))
		{
			unset($_REQUEST['action']);
			$context['theme_loaded'] = false;

			\Errors::instance()->fatal_lang_error('feature_disabled', false);
		}

		// Clear out any template layers
		$template_layers = Template_Layers::instance();
		$template_layers->removeAll();

		// Get the topic information.
		require_once(SUBSDIR . '/Topic.subs.php');
		$topicinfo = getTopicInfo($topic, 'starter');

		// Redirect to the boardindex if no valid topic id is provided.
		if (empty($topicinfo))
		{
			redirectexit();
		}

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
		{
			$context['parent_boards'][] = $parent['name'];
		}

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
	private function _create_pdf(): void
	{
		global $mbname, $context;

		// Extra memory is always good with PDF creation
		detectServer()->setMemoryLimit('128M');

		// Core PDF functions
		require_once(EXTDIR . '/tfpdf.php');
		require_once(SUBSDIR . '/Elk_PDF.class.php');

		try
		{
			$pdf = $this->initializePdf($mbname, $context);

			// On to the posts for this topic
			$count = 0;
			foreach ($context['posts'] as $post)
			{
				$this->processPost($pdf, $post, $context, $count);
				$count++;
			}

			$this->outputPdfContent($pdf, $mbname, $context['topic_subject']);

		}
		catch (Exception $e)
		{
			throw new Elk_Exception($e->getMessage());
		}
	}

	/**
	 * Initialize a PDF object with the necessary settings and fonts.
	 *
	 * @return ElkPdf The initialized ElkPdf object.
	 */
	private function initializePdf($mbname, $context): ElkPdf
	{

		// Portrait, millimeter, page size (Letter, A4, etc)
		$pdf = new ElkPdf($this->pdf_orientation, $this->pdf_unit, $this->pdf_page);

		// Stream handle for external images
		stream_wrapper_register('elkimg', 'VariableStream');

		// Common page setup
		$margin = 28.35 / (72 / 25.4); // based on unit mm in the above instance
		$pdf->SetAuthor(un_htmlspecialchars($mbname), true);
		$pdf->SetTitle(un_htmlspecialchars($mbname) . '_' . $context['board_name'], true);
		$pdf->SetSubject($context['topic_subject'], true);
		$pdf->SetMargins($this->pdf_wmargin, $this->pdf_hmargin);
		$pdf->SetAutoPageBreak(true,$margin); // 1 cm
		$pdf->SetLineWidth(.1);

		// Fonts we will or may use
		$pdf->AddFont('DejaVu', '', 'DejaVuSerifCondensed.ttf', true);
		$pdf->AddFont('DejaVu', 'B', 'DejaVuSerifCondensed-Bold.ttf', true);
		$pdf->AddFont('DejaVu', 'I', 'DejaVuSerifCondensed-Italic.ttf', true);
		$pdf->AddFont('DejaVu', 'BI', 'DejaVuSerifCondensed-BoldItalic.ttf', true);
		$pdf->AddFont('OpenSans', '', 'OpenSans-Regular.ttf', true);
		$pdf->AddFont('OpenSans', 'B', 'OpenSans-Bold.ttf', true);
		$pdf->AddFont('OpenSans', 'I', 'OpenSans-Italic.ttf', true);
		$pdf->AddFont('OpenSans', 'BI', 'OpenSans-BoldItalic.ttf', true);

		// Start the first page and auto page counter
		$pdf->AliasNbPages('{elk_nb}');
		$pdf->begin_page();

		return $pdf;
	}

	/**
	 * Process a topic post and create the necessary PDF content.
	 *
	 * @param ElkPdf $pdf The PDF generator object.
	 * @param array $post The post data.
	 * @param array $context The context data.
	 * @param int $count The count data.
	 * @return void
	 */
	private function processPost(ElkPdf $pdf, $post, $context, $count): void
	{
		// Write message header
		$pdf->message_header(html_entity_decode($post['subject']), html_entity_decode($post['member']), $post['time']);

		// Handle polls.
		if (!empty($context['poll']) && empty($count))
		{
			$pdf->add_poll(html_entity_decode($context['poll']['question']), $context['poll']['options'], $context['allow_poll_view']);
		}

		// Load images
		if (!empty($context['printattach'][$post['id_msg']]))
		{
			$pdf->set_attachments($context['printattach'][$post['id_msg']]);
		}

		// Write message body.
		$pdf->write_html(preg_replace_callback('~(&#(\d{1,7}|x[0-9a-fA-F]{1,6});)~', 'fixchar__callback', $post['body']));

		// Show below post attachment images
		if (!empty($context['printattach'][$post['id_msg']]))
		{
			$pdf->add_attachments();
		}

		$pdf->end_message();
	}

	/**
	 * Output the PDF content to the browser.
	 *
	 * @param ElkPdf $pdf The PDF generator object.
	 * @param string $mbname The name of the message board.
	 * @param string $topicSubject The subject of the topic.
	 * @return void
	 * @throws \RuntimeException If headers have already been sent.
	 */
	private function outputPdfContent(ElkPdf $pdf, $mbname, $topicSubject): void
	{
		// Make sure we can send
		if (headers_sent($filename, $linenum))
		{
			throw new \RuntimeException("Headers already sent in $filename on line $linenum");
		}

		// Clear anything in the buffers
		while (@ob_get_level() > 0)
		{
			@ob_end_clean();
		}

		$outputName = $this->filter_filename(un_htmlspecialchars($mbname) . '_' . html_entity_decode($topicSubject)) . '.pdf';

		// Get the PDF output
		$out = $pdf->Output($outputName, 'S', true);

		ob_start();

		// Output content to browser
		header('Content-Encoding: none');
		header('Content-Type: application/pdf');

		if ($_SERVER['SERVER_PORT'] === '443' && (strpos($_SERVER['HTTP_USER_AGENT'], 'MSIE') !== false))
		{
			header('Cache-Control: must-revalidate, post-check=0, pre-check=0', true);
			header('Pragma: public', true);
		}

		header('Content-Length: ' . strlen($out));
		header('Content-disposition: inline; filename=' . $outputName);

		echo $out;

		// Just exit now
		obExit(false);
	}

	/**
	 * File system reserved https://en.wikipedia.org/wiki/Filename#Reserved_characters_and_words
	 * control characters http://msdn.microsoft.com/en-us/library/windows/desktop/aa365247%28v=vs.85%29.aspx
	 * non-printing characters DEL, NO-BREAK SPACE, SOFT HYPHEN
	 * URI reserved https://tools.ietf.org/html/rfc3986#section-2.2
	 * URL unsafe characters https://www.ietf.org/rfc/rfc1738.txt
	 *
	 * @param $filename
	 * @param bool $beautify
	 * @return string
	 */
	private function filter_filename($filename, $beautify = true)
	{
		// Sanitize filename
		$filename = preg_replace('~[<>:"/\\|?*]|[\x00-\x1F]|[\x7F\xA0\xAD]|[#\[\]@!$&\'()+,;=]|[{}^\~`]~x', '-', $filename);

		// Avoid ".", ".." or ".hiddenFiles"
		$filename = ltrim($filename, '.-');

		// Beautification
		if ($beautify)
		{
			$filename = $this->beautify_filename($filename);
		}

		// Maximize filename length to 250 characters
		return Util::shorten_text(pathinfo($filename, PATHINFO_FILENAME), 250, false, '');
	}

	/**
	 * Make a filename normal looking after illegal characters have been replaced
	 *
	 * @param $filename
	 * @return string
	 */
	private function beautify_filename($filename)
	{
		// reduce consecutive characters, file   name.zip" becomes "file-name.zip"
		$filename = preg_replace(array('/ +/', '/_+/', '/-+/'), '-', $filename);

		// "file--.--.-.--name.zip" becomes "file.name.zip"
		$filename = preg_replace(array('/-*\.-*/', '/\.{2,}/'), '.', $filename);

		// ".file-name.-" becomes "file-name"
		return trim($filename, '.-');
	}
}
