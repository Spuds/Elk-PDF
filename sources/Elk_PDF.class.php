<?php

/**
 * @package "PDF" Addon for Elkarte
 * @author Spuds
 * @license BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * @version 1.0.4
 *
 */

if (!defined('ELK'))
{
	die('No access...');
}

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
	// Start position of a quote block, used to draw a box
	var $quote_start_y;
	// Line height for breaks etc
	var $line_height = 5;
	// The html that will be parsed
	var $html = '';
	// If this the first node, used to prevent excess whitespace at start
	var $_first_node = true;
	// Image types we support
	var $_validImageTypes = array(1 => 'gif', 2 => 'jpg', 3 => 'png', 9 => 'jpg');
	// holds html object from dom parser str_get_html
	var $doc;
	// holds loaded image data
	var $image_data;
	// holds results of getimagesize
	var $image_info;
	// Primary font face to use in the PDF, 'DejaVu' or 'OpenSans'
	var $font_face = 'OpenSans';
	// Temp file if needed for de interlace
	var $temp_file = CACHEDIR . '/pdf-print.temp.png';

	/**
	 * Converts a block of HTML to appropriate fPDF commands
	 *
	 * @param string $html
	 */
	public function write_html($html)
	{
		// Prepare the html for PDF-ifiing
		$this->html = $html;
		$this->_prepare_html();

		// Set the default font family
		$this->SetFont($this->font_face, '', 10);

		// Split up all the tags
		$a = preg_split('~<(.*?)>~', $this->html, -1, PREG_SPLIT_DELIM_CAPTURE);

		foreach ($a as $i => $e)
		{
			// Between the tags, is the text
			if ($i % 2 == 0)
			{
				// Text or link text?
				if ($this->_href)
				{
					$this->_add_link($this->_href, $e);
				}
				elseif (!empty($e))
				{
					$this->Write($this->line_height, $e);
				}
			}
			// HTML Tag
			else
			{
				// Ending Tag?
				if ($e[0] === '/')
				{
					$this->_close_tag(trim(substr($e, 1)));
				}
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
						{
							$attr[strtolower($a3[1])] = $a3[2];
						}
					}

					$this->_open_tag($tag, $attr);
				}
			}
		}
	}

	/**
	 * Cleans up the HTML by removing tags / blocks that we can not render
	 */
	private function _prepare_html()
	{
		// Up front, remove whitespace between html tags
		$this->html = preg_replace('/(?:(?<=\>)|(?<=\/\>))(\s+)(?=\<\/?)/', '', $this->html);

		// Add "tabs" for the code blocks
		$this->html = str_replace('<span class="tab"></span>', '    ', $this->html);

		// The external lib is easier to use for class searches
		require_once(EXTDIR . '/simple_html_dom.php');
		$this->doc = str_get_html($this->html, true, true, 'UTF-8', false);

		// Gallerys are kind of special, see this function on one way to deal with them
		// $this->_prepare_gallery();

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
	private function _open_tag($tag, $attr)
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
				$this->SetFont($this->font_face, '', 8);
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
				{
					$this->Ln($this->line_height);
				}
				break;
			case 'div':
				if (!$this->_first_node)
				{
					$this->Ln($this->line_height);
				}

				// If its the start of a quote block
				if (isset($attr['class']) && $attr['class'] === 'quoteheader')
				{
					// Need to track the first quote so we can tag the border box start
					if ($this->in_quote == 0)
					{
						$this->quote_start_y = $this->GetY();
						$this->SetFont($this->font_face, '', 8);
					}

					// Keep track of quote depth so they are indented
					$this->lMargin += ($this->in_quote) * 10;
					$this->in_quote++;
				}
				// Maybe a codeblock
				elseif (isset($attr['class']) && $attr['class'] === 'codeheader')
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
	private function _close_tag($tag)
	{
		$tag = strtolower($tag);

		switch ($tag)
		{
			// Closing tag
			case 'pre':
				$this->SetFont($this->font_face, '', 10);
				break;
			case 'blockquote':
				$this->in_quote--;
				$this->lMargin -= ($this->in_quote) * 10;

				if ($this->in_quote == 0)
				{
					$this->SetFont($this->font_face, '', 10);
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
	 */
	public function begin_page()
	{
		$this->AddPage();
		$this->_get_page_width();
		$this->_get_page_height();
	}

	/**
	 * Called when a page break needs to occur
	 *
	 * @return boolean
	 */
	public function AcceptPageBreak()
	{
		// If in a quote block, close the current outline box
		if ($this->in_quote > 0)
		{
			$this->Rect($this->lMargin, $this->quote_start_y, ($this->w - $this->rMargin - $this->lMargin), ($this->h - $this->quote_start_y - $this->bMargin), 'D');
		}

		return $this->AutoPageBreak;
	}

	/**
	 * Draws a rectangular box around a quoteblock
	 */
	private function _draw_box()
	{
		$this->Rect($this->lMargin, $this->quote_start_y, ($this->w - $this->rMargin - $this->lMargin), ($this->GetY() - $this->quote_start_y), 'D');
	}

	/**
	 * Add a link of text to the output
	 *
	 * @param string $url
	 * @param string $caption
	 */
	private function _add_link($url, $caption = '')
	{
		// Underline blue text for links
		$this->SetTextColor(0, 0, 255);
		$this->_set_style('u', true);
		$this->SetFont($this->font_face, '', ($this->in_quote ? 8 : 10));
		$this->Write($this->line_height, $caption ? $caption : ' ', $url);
		$this->SetFont($this->font_face, '', 10);
		$this->_set_style('u', false);
		$this->SetTextColor(-1);
	}

	/**
	 * An example for gallerys, gets the image filename that will be used to load
	 * locally via _fetch_image().  Sets an .gal extension used to let _fetch_image
	 * know that its a local file it need to attempt to load.
	 */
	private function _prepare_gallery()
	{
		global $settings;

		// Dependencies
		require_once(SUBSDIR . '/Aeva-Subs.php');
		aeva_loadSettings();

		// Remove extra markup not needed
		$elements = $this->doc->find('div.caption');
		foreach ($elements as $node)
		{
			$node->outertext = '';
		}

		// All the gallery links
		$elements = $this->doc->find('table.aextbox td a');
		foreach ($elements as $node)
		{
			// Get the id
			$type = 'preview';
			$id = '';
			if (preg_match('~.*in=(\d+).*~', $node->href, $match))
			{
				$id = (int) $match[1];
			}

			if (empty($id) || !aeva_allowedTo('access'))
			{
				$path = $settings['theme_dir'] . '/images/aeva/denied.png';
			}
			else
			{
				list($path, ,) = aeva_getMediaPath($id, $type);
			}

			// Set the image src to the file location so it can be fetched.
			$node->parent()->outertext = '<img src="' . (!empty($path) ? $path : $settings['theme_dir'] . '/images/aeva/denied.png') . '.gal">';
		}
	}

	/**
	 * Inserts images below the post text
	 * Attempts to place as many on a single line as possible
	 *
	 * @param mixed[] $attach
	 */
	public function add_attachments($attach)
	{
		$this->Ln($this->line_height);
		$this->_draw_line();
		$this->Ln(2);
		$this->AutoPageBreak = false;
		$this->image_line = 0;

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
				// Scale to fit in our grid as required
				list($a['width'], $a['height']) = $this->_scale_image($a['width'], $a['height']);

				// Does it fit on this row
				$this->image_line += $a['width'];

				if ($this->image_line >= $this->page_width)
				{
					// New row, move the cursor down to the next row based on the tallest image
					$this->Ln($this->image_height + $this->line_height);
					$this->image_height = 0;
					$this->image_line = 0;
				}

				// Does it fit on this page, or is a new one needed?
				if ($this->y + $a['height'] > $this->PageBreakTrigger)
				{
					$this->AddPage();
					$this->image_height = 0;
					$this->image_line = 0;
				}

				// Detect and repair interlaced PNG files.
				if ($type === 'PNG')
				{
					$a['filename'] = $this->deInterlace($a['filename']);
				}

				$this->image_height = max($this->image_height, $a['height']);
				$this->Cell($a['width'] + $this->_px2mm($this->rMargin), $a['height'] + $this->_px2mm($this->tMargin), $this->Image($a['filename'], $this->GetX(), $this->GetY(), $a['width'], $a['height'], $type), 0, 0, 'L', false);
				$this->image_line += $this->_px2mm($this->rMargin);

				// Cleanup if needed
				if ($a['filename'] === $this->temp_file)
				{
					@unlink($this->temp_file);
				}
			}
		}

		// Last image, move the cursor position to the next row
		$this->AutoPageBreak = true;

		if (isset($a['height']))
		{
			$this->Ln(max($this->image_height, $a['height']));
		}
	}

	public function deInterlace($filename)
	{
		$success = false;

		// Open the file and check the interlaced" flag it's byte 13 of the iHDR
		$handle = fopen($filename, 'r');
		$contents = fread($handle, 32);
		fclose($handle);

		// If the interlace flag is on, lets try to de-interlace it to a temp file
		if (ord($contents[28]) != 0)
		{
			require_once(SUBSDIR . '/Graphics.subs.php');

			if (checkImagick())
			{
				$image = new Imagick($filename);
				$success = $image->writeImage($this->temp_file);
				$image->clear();
			}
			elseif (checkGD())
			{
				$image = imagecreatefrompng($filename);
				imagealphablending($image, false);
				imagesavealpha($image, true);
				imageinterlace($image, 0);
				$success = imagepng($image, $this->temp_file);
			}
		}

		return $success ? $this->temp_file : $filename;
	}

	/**
	 * Inserts images below the post text, does not do it "inline" but each on a new
	 * line.
	 *
	 * @param mixed[] $attr
	 */
	private function _add_image($attr)
	{
		// With a source lets fetch it
		if (isset($attr['src']))
		{
			// Load the image in to memory, set type based on what is loaded
			$this->_fetch_image($attr['src']);

			// Nothing loaded, or not an image we process or ... show a link instead
			if (empty($this->image_data) || empty($this->image_info) || !isset($this->_validImageTypes[$this->image_info[2]]))
			{
				$caption = pathinfo($attr['src']);
				$caption = ' [ ' . (!empty($attr['title']) ? $attr['title'] : $caption['basename']) . ' ] ';
				$this->_add_link($attr['src'], $caption);

				return;
			}

			// Set the type based on what was loaded
			$attr['type'] = $this->_validImageTypes[(int) $this->image_info[2]];

			// If no specific width/height was on the image tag, check if its in the style
			if (isset($attr['style']) && (!isset($attr['width']) && !isset($attr['height'])))
			{
				// Extract the style width and height
				if (preg_match('~.*?width:(\d+)px.*?~', $attr['style'], $matches))
				{
					$attr['width'] = $matches[1];
				}
				if (preg_match('~.*?height:(\d+)px.*?~', $attr['style'], $matches))
				{
					$attr['height'] = $matches[1];
				}
			}

			// No size set that we can find, so just use the image size
			if (empty($attr['width']) && empty($attr['height']))
			{
				$attr['width'] = $this->image_info[0];
				$attr['height'] = $this->image_info[1];
			}
			// Maybe a width but no height, square is good
			elseif (!empty($attr['width']) && empty($attr['height']))
			{
				$attr['height'] = $attr['width'];
			}
			// Maybe a height but no width, square is dandy
			elseif (empty($attr['width']) && !empty($attr['height']))
			{
				$attr['width'] = $attr['height'];
			}

			// Some scaling may be needed, does the image even fit on a page?
			list($thumbwidth, $thumbheight) = $this->_scale_image($attr['width'], $attr['height']);

			// Does it fit on this page, or is a new one needed?
			if ($this->y + $thumbheight > $this->PageBreakTrigger)
			{
				$this->AddPage();
			}

			// Use our stream wrapper since we have the data in memory
			$elkimg = 'img' . md5($this->image_data);
			$GLOBALS[$elkimg] = $this->image_data;
			$this->Ln($this->line_height);
			$this->Cell($thumbwidth, $thumbheight, $this->Image('elkimg://' . $elkimg, $this->GetX(), $this->GetY(), $thumbwidth, $thumbheight, isset($attr['type']) ? $attr['type'] : ''), 0, 0, 'L', false);
			$this->Ln($thumbheight);
			unset($GLOBALS[$elkimg], $this->image_data, $this->image_info);
		}
	}

	/**
	 * Given an image URL simply load its data to memory
	 *
	 * @param string $name
	 * @return string '' if no path extension found
	 */
	private function _fetch_image($name)
	{
		global $boardurl;

		require_once(SUBSDIR . '/Package.subs.php');

		// Local file or remote?
		$pathinfo = pathinfo($name);

		// Not going to look then
		if (!isset($pathinfo['extension']))
		{
			return '';
		}

		if ((strpos($name, $boardurl) !== false && in_array($pathinfo['extension'], $this->_validImageTypes)) || $pathinfo['extension'] === 'gal')
		{
			// Gallery image?
			if ($pathinfo['extension'] === 'gal')
			{
				$name = substr($name, 0, -4);
			}

			$this->image_data = file_get_contents(str_replace($boardurl, BOARDDIR, $name));
		}
		else
		{
			$this->image_data = fetch_web_data($name);
		}

		// Image size and type
		$this->image_info = @getimagesizefromstring($this->image_data);
	}

	/**
	 * Scale an image to fit in the page limits
	 * Returns the image width height in page units not px
	 *
	 * @param int $width in px
	 * @param int $height in px
	 *
	 * @return array (width, height)
	 */
	private function _scale_image($width, $height)
	{
		// Normalize to page units
		$width = $this->_px2mm($width);
		$height = $this->_px2mm($height);
		$across = 2;
		$down = 2;

		// Max width and height
		$max_width = $this->page_width / $across - $this->_px2mm(($across - 1) * $this->rMargin);
		$max_height = $this->page_height / $down - $this->_px2mm(($down - 1) * $this->tMargin);

		// Some scaling may be needed, does the image even fit on a page?
		if ($max_width < $width && $width >= $height)
		{
			$thumbwidth = $max_width;
			$thumbheight = ($max_width / $width) * $height;
		}
		elseif ($max_height < $height && $height >= $width)
		{
			$thumbheight = $max_height;
			$thumbwidth = ($max_height / $height) * $width;
		}
		else
		{
			$thumbheight = $height;
			$thumbwidth = $width;
		}

		return array(ceil($thumbwidth), ceil($thumbheight));
	}

	/**
	 * Add the poll question, options, and vote count
	 */
	public function add_poll($name, $options, $allowed_view_votes)
	{
		global $txt;

		// The question
		$this->Ln(2);
		$this->SetFont($this->font_face, '', 10);
		$this->Write($this->line_height, $txt['poll_question'] . ': ');
		$this->SetFont($this->font_face, 'B', 10);
		$this->Write($this->line_height, $name);
		$this->SetFont($this->font_face, '', 10);
		$this->Ln($this->line_height);

		// Choices with vote count
		$print_options = 1;
		foreach ($options as $option)
		{
			$this->SetFont($this->font_face, '', 10);
			$this->Write($this->line_height, $txt['option'] . ' ' . $print_options++ . ' » ');
			$this->SetFont($this->font_face, 'B', 10);
			$this->Write($this->line_height, $option['option']);
			$this->SetFont($this->font_face, '', 10);

			if ($allowed_view_votes)
			{
				$this->Write($this->line_height, ' (' . $txt['votes'] . ': ' . $option['votes'] . ')');
			}

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
	public function message_header($subject, $author, $date)
	{
		global $txt;

		// Make sure the head stays with the body
		if ($this->y + 4 > $this->page_height)
		{
			$this->AddPage();
		}

		// Subject
		$this->_draw_line();
		$this->SetFont($this->font_face, '', 8);
		$this->Write($this->line_height, $txt['title'] . ': ');
		$this->SetFont($this->font_face, 'B', 9);
		$this->Write($this->line_height, $subject);
		$this->Ln(4);

		// Posted by and time
		$this->SetFont($this->font_face, '', 8);
		$this->Write($this->line_height, $txt['post_by'] . ': ');
		$this->SetFont($this->font_face, 'B', 9);
		$this->Write($this->line_height, $author . ' ');
		$this->SetFont($this->font_face, '', 8);
		$this->Write($this->line_height, $txt['search_on'] . ' ');
		$this->SetFont($this->font_face, 'B', 9);
		$this->Write($this->line_height, $date);

		$this->Ln($this->line_height);
		$this->_draw_line();
		$this->Ln(2);
	}

	/**
	 * Items to print below the end of the message to provide separation
	 */
	public function end_message()
	{
		$this->Ln(10);
	}

	/**
	 * Print a page header, called automatically by fPDF
	 */
	public function header()
	{
		global $context, $txt;

		$linktree = $context['category_name'] . ' » ' . (!empty($context['parent_boards']) ? implode(' » ', $context['parent_boards']) . ' » ' : '') . $context['board_name'] . ' » ' . $txt['topic_started'] . ': ' . $context['poster_name'] . ' ' . $txt['search_on'] . ' ' . $context['post_time'];

		// Print the linktree followed by a solid bar
		$this->SetFont($this->font_face, '', 9);
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
			$this->SetFont($this->font_face, '', 8);
		}
	}

	/**
	 * Output a page footer, called automatically by fPDF
	 */
	public function footer()
	{
		global $scripturl, $topic, $mbname, $txt, $context;

		$this->SetFont($this->font_face, '', 8);
		$this->Ln($this->line_height);
		$this->_draw_line();
		$this->Write($this->line_height, $txt['page'] . ' ' . $this->page . ' / {elk_nb} ---- ' . html_entity_decode(htmlspecialchars_decode($mbname)) . ' ' . $txt['topic'] . ' ' . $txt['link'] . ': ');
		$this->in_quote++;
		$this->_add_link($scripturl . '?topic=' . $topic, $context['topic_subject']);
		$this->in_quote--;
	}

	/**
	 * Set the text color to an r,g,b value
	 *
	 * @param int $r
	 * @param int $g
	 * @param int $b
	 */
	private function _elk_set_text_color($r, $g = 0, $b = 0)
	{
		static $_r = 0, $_g = 0, $_b = 0;

		// Repeat the current color
		if ($r == -1)
		{
			$this->SetTextColor($_r, $_g, $_b);
		}
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
	private function _set_style($tag, $enable)
	{
		// Keep track of the style depth / nesting
		$this->{$tag} += ($enable ? 1 : -1);

		$style = '';
		foreach (array('b', 'i', 'u') as $s)
		{
			if ($this->{$s} > 0)
			{
				$style .= $s;
			}
		}

		// Set / un-set the style
		$this->SetFont('', $style);
	}

	/**
	 * Conversion pixel -> millimeter at 96 dpi
	 *
	 * @param int $px
	 * @return float
	 */
	private function _px2mm($px)
	{
		return ($px * 25.4) / 96;
	}

	/**
	 * Conversion millimeter -> pixel at 96 dpi
	 *
	 * @param int $mm
	 * @return float
	 */
	private function _mm2px($mm)
	{
		return ($mm * 96) / 25.4;
	}

	/**
	 * Draw a horizontal line
	 */
	private function _draw_line()
	{
		$this->Line($this->lMargin, $this->y, ($this->w - $this->rMargin), $this->y);
	}

	/**
	 * Draw a filled rectangle
	 */
	private function _draw_rectangle()
	{
		$this->Rect($this->lMargin, $this->y, (int) ($this->w - $this->lMargin - $this->lMargin), 1, 'F');
	}

	/**
	 * Returns the available page width for printing
	 */
	private function _get_page_width()
	{
		$this->page_width = $this->w - $this->rMargin - $this->lMargin;
	}

	/**
	 * Returns the available page height for printing
	 */
	private function _get_page_height()
	{
		$this->page_height = $this->h - $this->bMargin - $this->tMargin;
	}
}

/**
 * Provides basic stream functions for custom stream_wrapper_register
 *
 * Uses a global variable as the stream. e.g. elkpdf://varname in global space
 */
class VariableStream
{
	protected $position;
	protected $varname;

	/**
	 * Callback for fopen()
	 *
	 * @param string $path
	 *
	 * @return boolean
	 */
	public function stream_open($path)
	{
		$url = parse_url($path);
		$this->varname = $url["host"];
		$this->position = 0;

		return true;
	}

	/**
	 * Callback for fread()
	 *
	 * @param int $count
	 * @return string
	 */
	public function stream_read($count)
	{
		if (!isset($GLOBALS[$this->varname]))
		{
			return '';
		}

		$ret = substr($GLOBALS[$this->varname], $this->position, $count);
		$this->position += strlen($ret);

		return $ret;
	}

	/**
	 * Callback for fwrite()
	 *
	 * @param string $data
	 * @return int
	 */
	public function stream_write($data)
	{
		if (!isset($GLOBALS[$this->varname]))
		{
			$GLOBALS[$this->varname] = $data;
		}
		else
		{
			$GLOBALS[$this->varname] = substr_replace($GLOBALS[$this->varname], $data, $this->position, strlen($data));
		}

		$this->position += strlen($data);

		return strlen($data);
	}

	/**
	 * Callback for ftell()
	 */
	public function stream_tell()
	{
		return $this->position;
	}

	/**
	 * Callback for feof()
	 */
	public function stream_eof()
	{
		return !isset($GLOBALS[$this->varname]) || $this->position >= strlen($GLOBALS[$this->varname]);
	}

	/**
	 * Callback for fseek()
	 *
	 * @param int $offset
	 * @param int $whence
	 *
	 * @return boolean
	 */
	public function stream_seek($offset, $whence)
	{
		$result = true;

		switch ($whence)
		{
			case SEEK_SET:
				if ($offset < strlen($GLOBALS[$this->varname]) && $offset >= 0)
				{
					$this->position = $offset;
				}
				else
				{
					$result = false;
				}
				break;
			case SEEK_CUR:
				if ($offset >= 0)
				{
					$this->position += $offset;
				}
				else
				{
					$result = false;
				}
				break;
			case SEEK_END:
				if (strlen($GLOBALS[$this->varname]) + $offset >= 0)
				{
					$this->position = strlen($GLOBALS[$this->varname]) + $offset;
				}
				else
				{
					$result = false;
				}
				break;
			default:
				$result = false;
		}

		return $result;
	}

	/**
	 * Callback for fstat().
	 *
	 * @return array
	 */
	public function stream_stat()
	{
		return array();
	}
}
