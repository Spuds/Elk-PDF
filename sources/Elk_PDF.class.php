<?php

/**
 * @package "PDF" Addon for Elkarte
 * @author Spuds
 * @license BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * @version 1.0
 *
 */

if (!defined('ELK'))
	die('No access...');

class ElkPdf extends tFPDF
{
	// Current href src
	var $_href = '';
	// Depth of b
	var $b;
	// Depth of u
	var $u;
	// Depth of i
	var $i;
	// Total width of images in a line of images
	var $image_line = 0;
	// Tallest image in a line
	var $image_height = 0;
	// Page width less margins
	var $page_width;
	// Page height less margins
	var $page_height;
	// If we are in a quote or not
	var $in_quote = 0;
	// Start postion of a quote block, used to draw a box
	var $quote_start_y;
	// Line height for breaks etc
	var $line_height = 5;
	// The html that will be parsed
	var $html = '';
	// If this the first node, used to prevent excess whitespace at start
	var $_first_node = true;
	// Image types we support
	var $_validImageTypes = array(1 => 'gif', 2 => 'jpg', 3 => 'png', 9 => 'jpg');
	// holds html object from domparser str_get_html
	var $doc;

	/**
	 * Converts a block of HTML to appropriate fPDF commands
	 *
	 * @param string $html
	 */
	function write_html($html)
	{
		// Prepare the html for PDF-ifiing
		$this->html = $html;
		$this->_prepare_html();

		// Set the default font family
		$this->SetFont('DejaVu', '', 10);

		// Split up all the tags
		$a = preg_split('~<(.*?)>~', $this->html, -1, PREG_SPLIT_DELIM_CAPTURE);
		foreach ($a as $i => $e)
		{
			// Between the tags, is the text
			if ($i % 2 == 0)
			{
				// Text or link text?
				if ($this->_href)
					$this->_add_link($this->_href, $e);
				elseif (!empty($e))
					$this->Write($this->line_height, $e);
			}
			// HTML Tag
			else
			{
				// Ending Tag?
				if ($e[0] == '/')
					$this->_close_tag(trim(substr($e,1)));
				else
				{
					// Opening Tag
					$a2 = explode(' ', $e);
					$tag = array_shift($a2);

					// Extract any attributes
					$attr = array();
					foreach ($a2 as $value)
					{
						if (preg_match('~([^=]*)=["\']?([^"\']*)~', $value, $a3))
							$attr[strtolower($a3[1])] = $a3[2];
					}

					$this->_open_tag($tag, $attr);
				}
			}
		}
	}

	/**
	 * Cleans up the HTML by removing tags / blocks that we can not render
	 */
	function _prepare_html()
	{
		// Up front, remove whitespace between html tags
		$this->html = preg_replace('/(?:(?<=\>)|(?<=\/\>))(\s+)(?=\<\/?)/', '', $this->html);

		// The external lib is easier to use for class searches
		require_once(EXTDIR . '/simple_html_dom.php');
		$this->doc = str_get_html($this->html, true, true, 'UTF-8', false);

		$elements = $this->doc->find('div.aeva_details');
		foreach ($elements as $node)
			$node->outertext = '';
		$elements = $this->doc->find('div.aep a');
		foreach ($elements as $node)
		{
			$link = $node->href;
			$node->parent()->outertext = '<img src="' . $link . '">';
		}

		// Get whats left
		$this->html = $this->doc->save();

		// Clean it up for proper printing
		$this->html = html_entity_decode(htmlspecialchars_decode($this->html, ENT_QUOTES), ENT_QUOTES, 'UTF-8');
		$this->html = strip_tags($this->html, '<a><img><div><p><br><blockquote><pre><ol><ul><li><hr><b><i><u><strong><em>');
	}

	/**
	 * Used to convert opening html tags to a corresponding fPDF style
	 *
	 * @param string $tag
	 * @param mixed[] $attr
	 */
	function _open_tag($tag, $attr)
	{
		$tag = strtolower($tag);

		// Opening tag
		switch ($tag)
		{
			case 'strong':
			case 'b':
				$this->_set_style('b', true);
				break;
			case 'pre':
				// Preformatted, like a code block
				$this->AddFont('DejaVuMono', '', 'DejaVuSansMono.ttf', true);
				$this->SetFont('DejaVuMono', '', 7);
				$this->_set_style('b', false);
				$this->_set_style('i', false);
				$this->Ln($this->line_height);
				$this->_draw_line();
				break;
			case 'blockquote':
				$this->_elk_set_text_color(100, 100, 45);
				$this->SetFont('DejaVu', '', 8);
				$this->Ln(4);
				break;
			case 'i':
			case 'em':
				$this->_set_style('i', true);
				break;
			case 'u':
				$this->_set_style('u', true);
				break;
			case 'a':
				$this->_href = $attr['href'];
				break;
			case 'img':
				$this->_add_image($attr);
				break;
			case 'li':
				$this->Ln($this->line_height);
				$this->SetTextColor(190, 0, 0);
				$this->Write($this->line_height, '     » ');
				$this->_elk_set_text_color(-1);
				break;
			case 'br':
			case 'p':
				if (!$this->_first_node)
					$this->Ln($this->line_height);
				break;
			case 'div':
				if (!$this->_first_node)
					$this->Ln($this->line_height);

				// If its the start of a quote block
				if (isset($attr['class']) && $attr['class'] == 'quoteheader')
				{
					// Need to track the first quote so we can tag the border box start
					if ($this->in_quote == 0)
					{
						$this->quote_start_y = $this->GetY();
						$this->SetFont('DejaVu', '', 8);
					}

					// Keep track of quote depth so they are indented
					$this->lMargin += ($this->in_quote) * 10;
					$this->in_quote++;
				}
				// Maybe a codeblock
				elseif (isset($attr['class']) && $attr['class'] == 'codeheader')
				{
					$this->_draw_line();
					$this->AddFont('DejaVuMono', '', 'DejaVuSansMono.ttf', true);
					$this->SetFont('DejaVuMono', '', 8);
				}
				break;
			case 'hr':
				$this->_draw_line();
				break;
		}

		$this->_first_node = false;
	}

	/**
	 * Convert a closing html tag to the corresponding fPDF commands
	 *
	 * @param string $tag
	 */
	function _close_tag($tag)
	{
		$tag = strtolower($tag);

		switch ($tag)
		{
			// Closing tag
			case 'pre':
				$this->SetFont('DejaVu', '', 10);
				break;
			case 'blockquote':
				$this->in_quote--;
				$this->lMargin -= ($this->in_quote) * 10;

				if ($this->in_quote == 0)
				{
					$this->SetFont('DejaVu', '', 10);
					$this->_elk_set_text_color(0, 0, 0);
					$this->SetFillColor(0, 0, 0);
					$this->_draw_box();
				}
				break;
			case 'strong':
				$tag = 'b';
				$this->_set_style($tag, false);
				break;
			case 'em':
				$tag = 'i';
				$this->_set_style($tag, false);
				break;
			case 'b':
			case 'i':
			case 'u':
				$this->_set_style($tag, false);
				break;
			case 'a':
				$this->_href = '';
				break;
		}
	}

	/**
	 * Start a new page
	 * @param type $title
	 */
	function begin_page()
	{
		$this->AddPage();
		$this->_get_page_width();
		$this->_get_page_height();
	}

	/**
	 * Called when a page break needs to occur
	 * @return boolean
	 */
	function AcceptPageBreak()
	{
		// If in a quote block, close the current outline box
		if ($this->in_quote > 0)
			$this->Rect($this->lMargin, $this->quote_start_y, ($this->w - $this->rMargin - $this->lMargin), ($this->h - $this->quote_start_y - $this->bMargin), 'D');

		return $this->AutoPageBreak;
	}

	/**
	 * Draws a rectangular box around a quoteblock
	 */
	function _draw_box()
	{
		$this->Rect($this->lMargin, $this->quote_start_y, ($this->w - $this->rMargin - $this->lMargin), ($this->GetY() - $this->quote_start_y), 'D');
	}

	/**
	 * Add a link of text to the output
	 *
	 * @param string $url
	 * @param string $caption
	 */
	function _add_link($url, $caption = '')
	{
		// Underline blue text for links
		$this->SetTextColor(0, 0, 255);
		$this->_set_style('u', true);
		$this->SetFont('DejaVu', '', ($this->in_quote ? 8 : 10));
		$this->Write($this->line_height, $caption ? $caption : '', $url);
		$this->SetFont('DejaVu', '', 10);
		$this->_set_style('u', false);
		$this->SetTextColor(-1);
	}

	/**
	 * Inserts images below the post text
	 * Attempts to place as many on a single line as possible
	 *
	 * @param mixed[] $attach
	 */
	function add_attachments($attach)
	{
		$this->Ln($this->line_height);
		$this->_draw_line();
		$this->Ln(2);

		foreach ($attach as $a)
		{
			switch ($a['mime_type'])
			{
				case 'image/jpeg':
				case 'image/jpg':
					$type = 'JPG';
					break;
				case 'image/png':
					$type = 'PNG';
					break;
				case 'image/gif':
					$type = 'GIF';
					break;
				default:
					$type = '';
					break;
			}

			// Its an image type fPDF understands
			if (!empty($type))
			{
				// If the image is to wide to fix, scale it
				list($a['width'], $a['height']) = $this->_scale_image($a['width'], $a['height']);

				// Does it fit on this row
				$this->image_line += $a['width'] + $this->line_height;
				if ($this->image_line > $this->page_width)
				{
					// New row, move the cursor down to the next row based on the tallest image
					$this->image_line = 0;
					$this->Ln($this->image_height + $this->line_height);
					$this->image_height = 0;
				}

				// Does it fit on this page, or is a new one needed?
				if ($this->y + $a['height'] > $this->page_height)
					$this->AddPage();

				$this->image_height = max($this->image_height, $a['height']);
				$this->Cell($a['width'] + $this->line_height, $a['height'] + $this->line_height, $this->Image($a['filename'], $this->GetX(), $this->GetY(), $a['width'], $a['height'], $type), 0, 0, 'L', false );
			}
		}

		// Last image, move the cursor position to the next row
		if (isset($a['height']))
			$this->Ln(max($this->image_height, $a['height']));
	}

	/**
	 * Inserts images below the post text, does not do it "inline" but each on a new
	 * line.
	 *
	 * @param mixed[] $attr
	 */
	function _add_image($attr)
	{
		// With a source lets display it
		if (isset($attr['src']))
		{
			// No specific width/height on the image tag, so perhaps its in the style
			if (isset($attr['style']) && (!isset($attr['width']) && !isset($attr['height'])))
			{
				// Extract the style width and height
				if (preg_match('~.*?width:(\d+)px.*?~', $attr['style'], $matches))
					$attr['width'] = $matches[1];
				if (preg_match('~.*?height:(\d+)px.*?~', $attr['style'], $matches))
					$attr['height'] = $matches[1];
			}

			// Nothing found anywhere?
			if (empty($attr['width']) && empty($attr['height']))
			{
				// Slow route to find some info on the image
				list($width, $height, $type) = @getimagesize($attr['src']);
				$attr['width'] = $width;
				$attr['height'] = $height;
				$attr['type'] = isset($this->_validImageTypes[$type]) ? $this->_validImageTypes[$type] : '';
			}
			// Maybe width but no height, square is good
			elseif (!empty($attr['width']) && empty($attr['height']))
				$attr['height'] = $attr['width'];
			// Maybe height but no width, square is dandy
			elseif (empty($attr['width']) && !empty($attr['height']))
				$attr['width'] = $attr['height'];

			// Some scaling may be needed, does the image even fit on a page?
			list($thumbwidth, $thumbheight) = $this->_scale_image($attr['width'], $attr['height']);

			// Does it fit on this page, or is a new one needed?
			if ($this->y + $thumbheight > $this->page_height)
				$this->AddPage();

			$this->Cell($thumbwidth, $thumbheight, $this->Image($attr['src'], $this->GetX(), $this->GetY(), $thumbwidth, $thumbheight, isset($attr['type']) ? $attr['type'] : ''), 0, 0, 'L', false);
			$this->Ln($thumbheight);
		}
	}

	/**
	 * Scale an image to fit in the page limits
	 * Returns the image width height in page units not px
	 *
	 * @param int $width in px
	 * @param int $height in px
	 */
	function _scale_image($width, $height)
	{
		// Normalize to page units
		$width = $this->_px2mm($width);
		$height = $this->_px2mm($height);

		// Max width and height
		$max_width = $this->page_width / 2 - $this->line_height;
		$max_height = $this->page_height / 2 - $this->line_height;

		// Some scaling may be needed, does the image even fit on a page?
		if ($max_width < $width && $width >= $height)
		{
			$thumbwidth = $max_width;
			$thumbheight = ($thumbwidth / $width) * $height;
		}
		elseif ($max_height < $height && $height >= $width)
		{
			$thumbheight = $max_height;
			$thumbwidth = ($thumbheight / $height) * $width;
		}
		else
		{
			$thumbheight = $height;
			$thumbwidth = $width;
		}

		return array(round($thumbwidth, 0), round($thumbheight, 0));
	}

	/**
	 * Add the poll question, options, and vote count
	 */
	function add_poll($name, $options, $allowed_view_votes)
	{
		global $txt;

		// The question
		$this->Ln(2);
		$this->SetFont('DejaVu', '', 10);
		$this->Write($this->line_height, $txt['poll_question'] . ': ');
		$this->SetFont('DejaVu', 'B', 10);
		$this->Write($this->line_height, $name);
		$this->SetFont('DejaVu', '', 10);
		$this->Ln($this->line_height);

		// Choices with vote count
		$print_options = 1;
		foreach ($options as $option)
		{
			$this->SetFont('DejaVu', '', 10);
			$this->Write($this->line_height, $txt['option'] . ' ' . $print_options++ . ' » ');
			$this->SetFont('DejaVu', 'B', 10);
			$this->Write($this->line_height, $option['option']);
			$this->SetFont('DejaVu', '', 10);

			if ($allowed_view_votes)
				$this->Write($this->line_height, ' (' . $txt['votes'] . ': ' . $option['votes'] . ')');

			$this->Ln($this->line_height);
		}

		// Close and move on
		$this->_draw_line();
		$this->Ln($this->line_height);
	}

	/**
	 * Output the header above each post body
	 *
	 * @param string $subject
	 * @param string $author
	 * @param string $date
	 */
	function message_header($subject, $author, $date)
	{
		global $txt;

		// Subject
		$this->_draw_line();
		$this->SetFont('DejaVu', '', 8);
		$this->Write($this->line_height, $txt['title'] . ': ');
		$this->SetFont('DejaVu', 'B', 9);
		$this->Write($this->line_height, $subject);
		$this->Ln(4);

		// Posted by and time
		$this->SetFont('DejaVu', '', 8);
		$this->Write($this->line_height, $txt['post_by'] . ': ');
		$this->SetFont('DejaVu', 'B', 9);
		$this->Write($this->line_height, $author . ' ');
		$this->SetFont('DejaVu', '', 8);
		$this->Write($this->line_height, $txt['search_on'] . ' ');
		$this->SetFont('DejaVu', 'B', 9);
		$this->Write($this->line_height, $date);

		$this->Ln($this->line_height);
		$this->_draw_line();
		$this->Ln(2);
	}

	/**
	 * Items to print below the end of the message to provide separation
	 */
	function end_message()
	{
		$this->Ln(10);
	}

	/**
	 * Print a page header, called automatically by fPDF
	 */
	function header()
	{
		global $context, $txt;

		$linktree = $context['category_name'] . ' » ' . (!empty($context['parent_boards']) ? implode(' » ', $context['parent_boards']) . ' » ' : '') . $context['board_name'] . ' » ' . $txt['topic_started'] . ': ' . $context['poster_name'] . ' ' . $txt['search_on'] . ' ' . $context['post_time'];

		// Print the linktree followed by a solid bar
		$this->SetFont('DejaVu', '', 9);
		$this->_elk_set_text_color(0, 0, 0);
		$this->SetFillColor(0, 0, 0);
		$this->Write($this->line_height, $linktree);
		$this->Ln($this->line_height);
		$this->_draw_rectangle();
		$this->Ln($this->line_height);

		// If the quote block wrapped pages, reset the values to the page top
		if ($this->in_quote)
		{
			$this->quote_start_y = $this->y;
			$this->_elk_set_text_color(100, 100, 45);
			$this->SetFont('DejaVu', '', 8);
		}
	}

	/**
	 * Output a page footer, called automatically by fPDF
	 */
	function footer()
	{
		global $scripturl, $topic, $mbname, $txt;

		$this->SetFont('DejaVu', '', 8);
		$this->Ln($this->line_height);
		$this->_draw_line();
		$this->Write($this->line_height, $txt['page'] . ' ' . $this->page . ' / {elk_nb} ---- ' . $txt['topic'] . ' ' . $txt['link'] . ': ');
		$this->in_quote++;
		$this->_add_link($scripturl . '?topic=' . $topic, $mbname);
		$this->in_quote--;
	}

	/**
	 * Set the text color to an r,g,b value
	 *
	 * @param int $r
	 * @param int $g
	 * @param int $b
	 */
	function _elk_set_text_color($r, $g = 0, $b = 0)
	{
		static $_r = 0, $_g = 0, $_b = 0;

		// Repeat the current color
		if ($r == -1)
			$this->SetTextColor($_r, $_g, $_b);
		// Or set a new one
		else
		{
			$this->SetTextColor($r, $g, $b);
			$_r = $r;
			$_g = $g;
			$_b = $b;
		}
	}

	/**
	 * Set / unset the font style of Bold, Italic or Underline
	 * Keeps track of nesting
	 *
	 * @param string $tag
	 * @param boolean $enable
	 */
	function _set_style($tag, $enable)
	{
		// Keep track of the style depth / nesting
		$this->$tag += ($enable ? 1 : -1);

		$style = '';
		foreach (array('b', 'i', 'u') as $s)
		{
			if ($this->$s > 0)
				$style .= $s;
		}

		// Set / un-set the style
		$this->SetFont('', $style);
	}

	/**
	 * Conversion pixel -> millimeter at 96 dpi
	 *
	 * @param int $px
	 */
	function _px2mm($px)
	{
		return ($px * 25.4) / 96;
	}

	/**
	 * Conversion millimeter -> pixel at 96 dpi
	 *
	 * @param int $mm
	 */
	function _mm2px($mm)
	{
		return ($mm * 96) / 25.4;
	}

	/**
	 * Draw a horizontal line
	 */
	function _draw_line()
	{
		$this->Line($this->lMargin, $this->y, ($this->w - $this->rMargin), $this->y);
	}

	/**
	 * Draw a filled rectangle
	 */
	function _draw_rectangle()
	{
		$this->Rect($this->lMargin, $this->y, (int) ($this->w - $this->lMargin - $this->lMargin), 1, 'F');
	}

	/**
	 * Returns the available page width for printing
	 */
	function _get_page_width()
	{
		$this->page_width = $this->w - $this->rMargin - $this->lMargin;
	}

	function _get_page_height()
	{
		$this->page_height = $this->h - $this->bMargin - $this->tMargin;
	}
}