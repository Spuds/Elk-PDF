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
	var $_href = '';
	var $b;
	var $u;
	var $i;
	var $image_line = 0;
	var $image_height = 0;
	var $page_width;
	var $in_quote = 0;
	var $quote_start_y;
	var $line_height = 5;

	/**
	 * Converts a block of HTML to appropriate fPDF commands
	 *
	 * @param string $html
	 */
	function write_html($html)
	{
		// Remove unsupported tags
		$html = strip_tags($html, '<a><img><p><div><br><blockquote><pre><ol><ul><li><hr><b><i><u><strong><em>');

		// Set the default font family
		$this->SetFont('DejaVu', '', 10);

		$a = preg_split('~<(.*?)>~', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
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
				$this->Ln($this->line_height);
				break;
			case 'div':
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
	function begin_page($title)
	{
		$this->AddPage();
		$this->_get_page_width();
	}

	/**
	 * Called when a page break needs to occur
	 * @return boolean
	 */
	function AcceptPageBreak()
	{
		// If in a quote block, close the current outline box
		if ($this->in_quote > 0)
			$this->Rect($this->lMargin, $this->quote_start_y, ($this->w - $this->rMargin - $this->lMargin), ($this->h - $this->quote_start_y - $this->bMargin), 1, 'D');

		return $this->AutoPageBreak;
	}

	/**
	 * Draws a rectangular box around a quoteblock
	 */
	function _draw_box()
	{
		$this->Rect($this->lMargin, $this->quote_start_y, ($this->w - $this->rMargin - $this->lMargin), ($this->GetY() - $this->quote_start_y), 1, 'D');
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
		$this->SetFont('DejaVu', '', 10);
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

			if (!empty($type))
			{
				// If the image is to wide to fix, scale it
				if ($this->_px2mm($a['width']) > $this->page_width)
				{
					$ratio = $a['width'] / $a['height'];
					$a['width'] = $this->_mm2px($this->page_width);
					$a['height'] = $a['width'] * $ratio;
				}

				$this->image_line += $this->_px2mm($a['width']) + $this->line_height;
				if ($this->image_line > $this->page_width)
				{
					// New row, move the cursor to the next row based on the tallest image
					$this->image_line = 0;
					$this->Ln($this->image_height + $this->line_height);
					$this->image_height = 0;
				}

				$this->image_height = max($this->image_height, $this->_px2mm($a['height']));
				$this->Cell($this->_px2mm($a['width']) + $this->line_height, $this->_px2mm($a['height']) + $this->line_height, $this->Image($a['filename'], $this->GetX(), $this->GetY(), $this->_px2mm($a['width']), null, $type), 0, 0, 'L', false );
			}
		}

		// Last image, move the cursor position to the next row
		$this->Ln(max($this->image_height, $this->_px2mm($a['height'])));
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
				$attr['width'] = 0;
				$attr['height'] = 0;
			}
			// Maybe width but no height, square is good
			elseif (!empty($attr['width']) && empty($attr['height']))
				$attr['height'] = $attr['width'];
			// Maybe height but no width, square is dandy
			elseif (empty($attr['width']) && !empty($attr['height']))
				$attr['width'] = $attr['height'];

			// Some scaling may be needed, does the image even fit on a page?
			$width = $this->_px2mm($attr['width']);
			$height = $this->_px2mm($attr['height']);
			$this->_get_page_height();
			if ($this->page_width < $width && $width >= $height)
			{
				$thumbwidth = $this->page_width;
				$thumbheight = ($thumbwidth / $width) * $height;
			}
			elseif ($this->page_height < $height && $height >= $width)
			{
				$thumbheight = $this->page_height;
				$thumbwidth = ($thumbheight / $height) * $width;
			}
			else
			{
				$thumbheight = $height;
				$thumbwidth = $width;
			}

			// Does it fit on this page, or is a new one needed?
			if ($this->y + $thumbheight > $this->page_height)
				$this->AddPage();

			$this->Cell($thumbwidth, $thumbheight, $this->Image($attr['src'], $this->GetX(), $this->GetY(), $thumbwidth, $thumbheight), 0, 0, 'L', false);
			$this->Ln($thumbheight);
		}
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
		$this->Ln(12);
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
		$this->_add_link($scripturl . '?topic=' . $topic, $mbname);
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
	 * @param int $enable
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