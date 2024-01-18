<?php

/**
 * @package "PDF" Addon for Elkarte
 * @author Spuds
 * @copyright (c) 2011-2024 Spuds
 * @license BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * @version 1.2.0
 *
 */

class ElkPdf extends tFPDF
{
	/** Current href src */
	private string $_href = '';
	/** Tallest image in a line */
	private int $image_height = 0;
	/** Page width less margins */
	private int $page_width = 0;
	/** Page height less margins */
	private int $page_height = 0;
	/** If we are in a quote or not */
	private int $in_quote = 0;
	/** Start position of a quote block, used to draw a box */
	private int $quote_start_y = 0;
	/** Line height for breaks etc */
	private int $line_height = 5;
	/** The html that will be parsed */
	private string $html = '';
	/** If this the first node, used to prevent excess whitespace at start */
	private bool $_first_node = true;
	/** Image types we support */
	private array $_validImageTypes = array(1 => 'gif', 2 => 'jpg', 3 => 'png', 9 => 'jpg');
	/** holds html object from dom parser str_get_html */
	private object $doc;
	/** holds loaded image data */
	private string $image_data;
	/** @var array|bool holds results of getimagesize */
	private $image_info = [];
	/** Primary font face to use in the PDF, 'DejaVu' or 'OpenSans' */
	private string $font_face = 'OpenSans';
	/** Temp file if needed for image manipulations */
	private string $temp_file = '';
	/** holds attachment array data for a single message */
	private array $attachments = [];
	/** holds ids of ILA attachments we have used */
	private array $dontShowBelow = [];
	/** current line height position, used to force linebreak on next image */
	private int $ila_height = 0;
	/** Tracks usage of ila style images so we clear after the last one */
	private int $ila_image_count = -1;

	/**
	 * Converts a block of HTML to appropriate fPDF commands
	 *
	 * @param string $html
	 */
	public function write_html(string $html): void
	{
		// Prepare the html for PDF-ifiing
		$this->html = $html;
		$this->_prepare_html();

		// Set the default font family
		$this->SetFont($this->font_face, '', 10);

		// Split up all the tags
		$tags = preg_split('~<(.*?)>~', $this->html, -1, PREG_SPLIT_DELIM_CAPTURE);

		foreach ($tags as $i => $tag)
		{
			$this->processTag($i, $tag);
		}
	}

	/**
	 * Processes a tag within the given index and tag
	 *
	 * @param int $i The index of the tag
	 * @param string $tag The tag to process
	 *
	 * @return void
	 */
	private function processTag(int $i, string $tag) : void
	{
		// Between the tags, is text
		if ($i % 2 === 0)
		{
			$tag = trim($tag);

			// Text or link text?
			if ($this->_href)
			{
				$this->_add_link($this->_href, $tag);
			}
			elseif (!empty($tag))
			{
				$this->Write($this->line_height, $tag);
			}
		}
		// HTML Tag
		elseif ($tag[0] === '/')
		{
			$this->_close_tag(trim(substr($tag, 1)));
		}
		else
		{
			// Opening Tag
			$tagItems = explode(' ', $tag);
			$tag = array_shift($tagItems);

			// Extract any attributes
			$attr = array();
			foreach ($tagItems as $value)
			{
				if (preg_match('~([^=]*)=["\']?([^"\']*)~', $value, $tagAttr))
				{
					$attr[strtolower($tagAttr[1])] = $tagAttr[2];
				}
			}

			$this->_open_tag($tag, $attr);
		}
	}

	/**
	 * Cleans up the HTML by removing tags / blocks that we can not render
	 */
	private function _prepare_html(): void
	{
		global $context;

		// Up front, remove whitespace between html tags
		$this->html = preg_replace('/(?:(?<=\>)|(?<=\/\>))(\s+)(?=\<\/?)/', '', $this->html);

		// If this message has Levertine gallery images, lets render them now
		if (!empty($context['lgal_embeds']))
		{
			$context['lgal_embeds']->processPBE($this->html);
		}

		// Add "tabs" for the code blocks
		$this->html = str_replace('<span class="tab"></span>', '    ', $this->html);

		// Create a new instance of DOMDocument
		$this->doc = new \DOMDocument();

		// Load the HTML into the instance
		$this->doc->loadHTML(htmlspecialchars_decode(htmlentities($this->html)));

		// ILA's will be shown in the post text, but only left aligned
		$this->_prepare_ila();

		// Gallery's are kind of special, see this function for ways to deal with them
		$this->_prepare_gallery();

		// Save the updated HTML from the instance, remove the DOCTYPE, html and body tags automatically added
		$this->html = preg_replace('~<(?:!DOCTYPE|/?(?:html|body))[^>]*>\s*~i', '', $this->doc->saveHTML());

		// Clean it up for proper printing
		$this->html = html_entity_decode(un_htmlspecialchars($this->html), ENT_QUOTES, 'UTF-8');
		$this->html = strip_tags($this->html, '<a><img><div><p><br><blockquote><pre><ol><ul><li><hr><b><i><u><strong><em>');
	}

	/**
	 * Used to convert opening html tags to a corresponding fPDF style
	 *
	 * @param string $tag
	 * @param array $attr
	 */
	private function _open_tag(string $tag, array $attr): void
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
			case 'ul':
			case 'ol':
				$this->line_height = 6;
				break;
			case 'li':
				$this->SetTextColor(190, 0, 0);
				$this->Write($this->line_height, '     » ');
				$this->_elk_set_text_color(-1);
				break;
			case 'br':
				$this->Ln($this->line_height);
				break;
			case 'p':
				if (!$this->_first_node)
				{
					if ($this->ila_image_count === 0)
					{
						$this->Ln($this->ila_height - $this->y);
						$this->ila_image_count = -1;
						$this->ila_height = 0;
					}
					else
					{
						$this->Ln($this->line_height);
					}
				}
				break;
			case 'div':
				if (!$this->_first_node)
				{
					if ($this->ila_image_count === 0)
					{
						$this->Ln($this->ila_height - $this->y);
						$this->ila_image_count = -1;
						$this->ila_height = 0;
					}
					else
					{
						$this->Ln($this->line_height);
					}
				}

				// If its the start of a quote block
				if (isset($attr['class']) && strpos($attr['class'], 'quoteheader') !== false)
				{
					// Need to track the first quote, so we can tag the border box start
					if ($this->in_quote === 0)
					{
						$this->quote_start_y = $this->GetY();
						$this->SetFont($this->font_face, '', 8);
					}

					// Keep track of quote depth so they are indented
					$this->lMargin += ($this->in_quote) * 5;
					$this->SetLeftMargin($this->lMargin);
					$this->in_quote++;
				}
				// Maybe a codeblock
				elseif (isset($attr['class']) && strpos($attr['class'], 'codeheader') !== false)
				{
					$this->_draw_line();
					$this->AddFont('DejaVuMono', '', 'DejaVuSansMono.ttf', true);
					$this->SetFont('DejaVuMono', '', 8);
				}
				break;
			case 'hr':
				$this->Ln($this->line_height);
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
	private function _close_tag(string $tag): void
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
				$this->lMargin -= ($this->in_quote) * 5;
				$this->SetLeftMargin($this->lMargin);
				$this->Ln(8);

				if ($this->in_quote === 0)
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
			case 'ul':
			case 'ol':
				$this->line_height = 5;
				break;
		}
	}

	/**
	 * Start a new page
	 */
	public function begin_page(): void
	{
		$this->AddPage();
		$this->_get_page_width();
		$this->_get_page_height();
		$this->ila_height = 0;
	}

	/**
	 * Called when a page break needs to occur
	 *
	 * @return boolean
	 */
	public function AcceptPageBreak(): bool
	{
		// If in a quote block, close the current outline box
		if ($this->in_quote > 0)
		{
			$this->Rect($this->lMargin, $this->quote_start_y, ($this->w - $this->rMargin - $this->lMargin), ($this->h - $this->quote_start_y - $this->bMargin), 'D');
		}

		return $this->AutoPageBreak;
	}

	/**
	 * Draws a rectangular box around a quote block
	 */
	private function _draw_box(): void
	{
		$this->Rect($this->lMargin, $this->quote_start_y, ($this->w - $this->rMargin - $this->lMargin), ($this->GetY() - $this->quote_start_y), 'D');
	}

	/**
	 * Add a link of text to the output
	 *
	 * @param string $url
	 * @param string $caption
	 */
	private function _add_link(string $url, string $caption = ''): void
	{
		// Underline blue text for links
		$this->SetTextColor(0, 0, 255);
		$this->_set_style('u', true);
		$this->SetFont($this->font_face, '', ($this->in_quote ? 8 : 10));
		$this->Write($this->line_height, !empty($caption) ? $caption : ' ', $url);
		$this->SetFont($this->font_face, '', 10);
		$this->_set_style('u', false);
		$this->SetTextColor(-1);
	}

	/**
	 * Finds ILA DNA in the markup and replaces the wrapped <a link+img> with a new
	 * img only tag set to local src for the attachment
	 */
	private function _prepare_ila(): void
	{
		// ILA <img> has class .bbc_img, a below message will have .attachment_image
		$xpath = new \DOMXPath($this->doc);
		$elements = $xpath->query('//a[@id]//img[contains(@class, "bbc_img")]');

		foreach ($elements as $node)
		{
			$parent = $node->parentNode;
			$ilaDetected = strpos($node->getAttribute('src'), 'dlattach') !== false && $parent->hasAttribute('data-lightboximage');
			if ($ilaDetected)
			{
				$attach_id = $parent->getAttribute('data-lightboximage');
				$attach = $this->find_attachment($attach_id);
				if ($attach !== false)
				{
					$newNode = $this->doc->createElement('img');
					$newNode->setAttribute('src', $attach['filename'] . '.gal');
					$parent->parentNode->replaceChild($newNode, $parent);

					$this->dontShowBelow[$attach['id_attach']] = $attach['id_attach'];
				}
			}

			$this->ila_image_count = empty($this->dontShowBelow) ? -1 : count($this->dontShowBelow);
		}
	}

	/**
	 * Returns the attachment array for a given attachment id
	 *
	 * @param int $id
	 * @return false|mixed
	 */
	private function find_attachment(int $id)
	{
		// id_attach, id_msg, approved, width", height, file_hash, filename, id_folder, mime_type
		foreach ($this->attachments as $attachment)
		{
			if ((int) $attachment['id_attach'] === $id)
			{
				return $attachment;
			}
		}

		return false;
	}

	/**
	 * Process gallery items.  Gets the image filename that will be used to load
	 * locally via _fetch_image().  Sets a .gal extension, used to let _fetch_image
	 * know that its a local file to load.
	 */
	private function _prepare_gallery(): void
	{
		if (file_exists(SOURCEDIR . '/levgal_src/LevGal-Bootstrap.php'))
		{
			$this->_process_levgal_items();
		}
	}

	/**
	 * Process legal items by replacing a link with an image element.
	 *
	 * This method uses DOMXPath to query for elements with a class containing "levgal".
	 * It then replaces each matching element with a new image element.
	 *
	 * @return void
	 */
	private function _process_levgal_items(): void
	{
		$xpath = new \DOMXPath($this->doc);
		$elements = $xpath->query('//a[contains(@class, "levgal")]');

		foreach ($elements as $node)
		{
			$fileName = str_replace('/item/', '/file/', $node->getAttribute('href')) . 'preview/.gal';

			// The fileName will only work for a guest level file, so lets check it ourselfs
			$query = parse_url($node->getAttribute('href'));
			if (preg_match('~^media\/item\/([a-z0-9%-]+\.\d+)?\/?$~i', $query['query'], $matches) === 1)
			{
				[$slug, $id] = explode('.', $matches[1]);

				$itemModel = new LevGal_Model_Item();
				$item_details = $itemModel->getFileInfoById($id);
				$item_paths = $itemModel->getFilePaths();

				// Do we have a valid item?
				if (!empty($item_details) && !empty($item_paths['preview']) && $item_details['item_slug'] === $slug && $itemModel->isVisible())
				{
					$fileName = $item_paths['preview'] . '.gal';
				}
			}

			$newNode = $this->doc->createElement('img');
			$newNode->setAttribute('src', $fileName);

			$node->parentNode->replaceChild($newNode, $node);
		}
	}

	/**
	 * Sets attachments array to the class
	 *
	 * @param array $attach
	 */
	public function set_attachments(array $attach): void
	{
		$this->attachments = $attach;
	}

	/**
	 * Inserts images below the post text
	 * Attempts to place as many on a single line as possible
	 */
	public function add_attachments(): void
	{
		if (!empty($this->ila_height))
		{
			$this->Ln($this->ila_height - $this->y);
		}
		else
		{
			$this->Ln($this->line_height);
		}

		$this->_draw_line();
		$this->Ln(2);
		$this->AutoPageBreak = false;
		$image_line = 0;

		foreach ($this->attachments as $attachment)
		{
			if (isset($this->dontShowBelow[$attachment['id_attach']]))
			{
				continue;
			}

			switch ($attachment['mime_type'])
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
				case 'image/webp':
					$type = 'WEBP';
					break;
				case 'image/bmp':
					$type = 'BMP';
					break;
				default:
					$type = '';
					break;
			}

			// Convert formats tfpdf does not support
			if ($type === 'WEBP' || $type === 'BMP')
			{
				$type = $this->convertImage($attachment, $type);
			}

			// Detect and repair interlaced PNG files.
			if ($type === 'PNG')
			{
				$this->deInterlace($attachment);
			}

			// An image type fPDF understands
			if (!empty($type))
			{
				// Scale to fit in our grid as required
				[$attachment['width'], $attachment['height']] = $this->_scale_image($attachment['width'], $attachment['height']);

				// Does it fit on this row
				$image_line += $attachment['width'];
				if ($image_line >= $this->page_width)
				{
					// New row, move the cursor down to the next row based on the tallest image
					$this->Ln($this->image_height + 2);
					$this->image_height = 0;
					$image_line = $attachment['width'];
				}

				// Does it fit on this page, or is a new one needed?
				if ($this->y + $attachment['height'] > $this->h - 6)
				{
					$this->AddPage();
					$this->image_height = 0;
					$image_line = $attachment['width'];
				}

				$this->image_height = max($this->image_height, $attachment['height']);
				$this->Image($attachment['filename'], $this->x, $this->y, $attachment['width'], $attachment['height'], $type);
				$this->Cell($attachment['width'] + 2, $attachment['height'], '', 0, 0, 'L', false);

				$image_line += 2;

				// Cleanup if needed
				if ($attachment['filename'] === $this->temp_file)
				{
					@unlink($this->temp_file);
				}
			}
		}

		// Last image, move the cursor position to the next row
		$this->Ln($this->image_height);

		$this->AutoPageBreak = true;
	}

	/**
	 * Currently tfpdf does not support webp or bmp, so we convert those
	 * to PNG (could use JPG as well, but have potential alpha png)
	 *
	 * @param array $attachment
	 * @param string $type
	 * @return string
	 */
	public function convertImage(array &$attachment, string $type): string
	{
		require_once(SUBSDIR . '/Graphics.subs.php');

		$success = false;

		// No webp support on the server
		if ($type === 'WEBP' && function_exists('hasWebpSupport') && !hasWebpSupport())
		{
			return '';
		}

		$this->temp_file = CACHEDIR . '/' . $attachment['file_hash'] . '.gal';
		if (checkImagick())
		{
			$image = new Imagick($attachment['filename']);
			$image->setImageFormat ('png');
			$success = $image->writeImage($this->temp_file);
			$image->clear();
		}
		elseif (checkGD())
		{
			$image = imagecreatefrompng($attachment['filename']);
			imagealphablending($image, false);
			imagesavealpha($image, true);
			imageinterlace($image, 0);
			$success = imagepng($image, $this->temp_file);
		}

		if ($success)
		{
			$attachment['filename'] = $this->temp_file;
			$attachment['mime_type'] = 'image/png';

			return 'PNG';
		}

		@unlink($this->temp_file);

		return '';
	}

	/**
	 * The pdf parser only works with none interlaced images.  This will
	 * use GD or Imagick functions to create a new standard image for
	 * insertion.
	 *
	 * @param array $attachment
	 */
	public function deInterlace(array &$attachment): void
	{
		$success = false;

		// Open the file and check the "interlaced" flag at byte 13 of the iHDR
		$handle = fopen($attachment['filename'], 'rb');
		$contents = fread($handle, 32);
		fclose($handle);

		// The interlace flag is on, try to de-interlace to a temp file
		if (ord($contents[28]) !== 0)
		{
			require_once(SUBSDIR . '/Graphics.subs.php');

			$this->temp_file = CACHEDIR . '/' . $attachment['file_hash'] . '.gal';
			if (checkImagick())
			{
				$image = new Imagick($attachment['filename']);
				$success = $image->writeImage($this->temp_file);
				$image->clear();
			}
			elseif (checkGD())
			{
				$image = imagecreatefrompng($attachment['filename']);
				imagealphablending($image, false);
				imagesavealpha($image, true);
				imageinterlace($image, 0);
				$success = imagepng($image, $this->temp_file);
			}
		}

		if ($success)
		{
			$attachment['filename'] = $this->temp_file;
		}
	}

	/**
	 * Inserts images with left "in line" alignment.  Only one image per line with wrapped text.
	 *
	 * @param array $attr
	 */
	private function _add_image(array $attr): void
	{
		// With a source lets fetch it
		if (isset($attr['src']))
		{
			// Load the image in to memory, set type based on what is loaded
			$this->_fetch_image($attr['src']);

			// Nothing loaded, or not an image we process or ... show a link instead
			if (empty($this->image_data) || empty($this->image_info) || !isset($this->_validImageTypes[$this->image_info[2]]))
			{
				$this->ila_image_count--;
				$caption = pathinfo($attr['src']);
				$caption = ' [ ' . (!empty($attr['title']) ? $attr['title'] : $caption['basename']) . ' ] ';
				$this->_add_link($attr['src'], $caption);

				return;
			}

			// Some scaling may be needed to conform to our 2x2 grid
			$this->_setImageAttr($attr);
			[$thumbwidth, $thumbheight] = $this->_scale_image($attr['width'], $attr['height']);

			// If we output a previous image "inline" then we need to add this image below the previous
			// before we plop in this new image
			if ($this->y < $this->ila_height)
			{
				$this->Ln($this->ila_height - $this->y);
			}

			// Does it fit on this page, or is a new one needed?
			if ($this->y + $thumbheight > $this->PageBreakTrigger)
			{
				$this->AddPage();
				$this->ila_height = 0;
			}

			// Use our stream wrapper since we have the data in memory
			$elkimg = 'img' . md5($this->image_data);
			$GLOBALS[$elkimg] = $this->image_data;

			// Add the image, keep smiles and other small images inline
			$smiley = $thumbheight < 18;
			if (!$smiley)
			{
				$this->ila_image_count--;
				$this->ila_height = ceil($this->y + $thumbheight + 2);

				// Already in a line, break to a newline
				if ($this->x > $this->lMargin)
				{
					$this->Ln(1);
				}
			}

			// Output the image
			$this->Image('elkimg://' . $elkimg, $this->x, $this->y, $thumbwidth, $thumbheight, $attr['type'] ?? '');

			// Wrap the image in a left aligned cell
			$this->Cell($thumbwidth + 2, $thumbheight, $smiley ? ' ' : '', 0, 0, 'L', false);

			unset($GLOBALS[$elkimg], $this->image_data, $this->image_info);
		}
	}

	/**
	 * Sets image attributes either from the html tag or from what we can determine from the
	 * image data
	 *
	 * @param array $attr
	 */
	private function _setImageAttr(array &$attr): void
	{
		// Set the type based on what was loaded
		$attr['type'] = $this->_validImageTypes[(int) $this->image_info[2]];

		// If no specific width/height was on the image tag, check in the style
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
	}

	/**
	 * Given an image URL simply load its data to memory
	 *
	 * @param string $name
	 * @return void '' if no path extension found
	 */
	private function _fetch_image(string $name): void
	{
		global $boardurl;

		require_once(SUBSDIR . '/Package.subs.php');

		// Local file or remote?
		$pathinfo = pathinfo($name);

		// Not going to look then
		if (!isset($pathinfo['extension']))
		{
			return;
		}

		if ($pathinfo['extension'] === 'gal' ||
			(strpos($name, $boardurl) !== false && in_array($pathinfo['extension'], $this->_validImageTypes, true)))
		{
			// Gallery image?
			if ($pathinfo['extension'] === 'gal')
			{
				$name = substr($name, 0, -4);
			}

			// No filename is most likely a url, like to a levgal image
			if ($pathinfo['filename'] !== '')
			{
				$this->image_data = file_get_contents(str_replace($boardurl, BOARDDIR, $name));
			}
			else
			{
				$this->image_data = file_get_contents($pathinfo['dirname']);
			}
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
	private function _scale_image(int $width, int $height): array
	{
		// Normalize to page units
		$width = (int) $this->_px2mm($width);
		$height = (int) $this->_px2mm($height);
		$across = 2;
		$down = 2;

		// Max width and height
		$max_width = floor($this->page_width / $across - ($across - 1) * 2);
		$max_height = floor($this->page_height / $down - ($down - 1) * 2);

		// Some scaling may be needed, does the image even fit on a page?
		if ($max_width < $width && $width >= $height)
		{
			$thumbWidth = $max_width;
			$thumbHeight = ($max_width / $width) * $height;
		}
		elseif ($max_height < $height && $height >= $width)
		{
			$thumbHeight = $max_height;
			$thumbWidth = ($max_height / $height) * $width;
		}
		else
		{
			$thumbHeight = $height;
			$thumbWidth = $width;
		}

		return array(floor($thumbWidth), floor($thumbHeight));
	}

	/**
	 * Add the poll question, options, and vote count
	 */
	public function add_poll($name, $options, $allowed_view_votes): void
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
	public function message_header(string $subject, string $author, string $date): void
	{
		global $txt;

		// Make sure the head stays with the body
		if ($this->y + 4 > $this->page_height)
		{
			$this->AddPage();
			$this->ila_height = 0;
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
	public function end_message(): void
	{
		$this->Ln(!empty($this->ila_height) ? $this->ila_height - $this->y : 10);
		$this->ila_height = 0;
	}

	/**
	 * Print a page header, called automatically by fPDF
	 */
	public function Header(): void
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
	public function Footer()
	{
		global $scripturl, $topic, $mbname, $txt, $context;

		$this->SetFont($this->font_face, '', 8);
		$this->SetY($this->h - 6);
		$this->_draw_line();
		$this->Write($this->line_height, $txt['page'] . ' ' . $this->page . ' / {elk_nb} ---- ' . html_entity_decode(un_htmlspecialchars($mbname)) . ' ---- ' . $txt['topic'] . ' ' . $txt['link'] . ': ');
		$this->in_quote++;
		$this->_add_link($scripturl . '?topic=' . $topic, un_htmlspecialchars($context['topic_subject']));
		$this->in_quote--;
	}

	/**
	 * Set the text color to an r,g,b value
	 *
	 * @param int $r
	 * @param int $g
	 * @param int $b
	 */
	private function _elk_set_text_color(int $r, int $g = 0, int $b = 0): void
	{
		static $_r = 0, $_g = 0, $_b = 0;

		// Repeat the current color
		if ($r === -1)
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
	private function _set_style(string $tag, bool $enable): void
	{
		// Keep track of the style depth / nesting
		$this->{$tag} = $this->{$tag} ?? 0;
		$this->{$tag} += ($enable ? 1 : -1);

		$style = '';
		foreach (array('b', 'i', 'u') as $s)
		{
			if (isset($this->{$s}) && $this->{$s} > 0)
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
	private function _px2mm(int $px): float
	{
		return ($px * 25.4) / 96;
	}

	/**
	 * Conversion millimeter -> pixel at 96 dpi
	 *
	 * @param int $mm
	 * @return float
	 */
	private function _mm2px(int $mm): float
	{
		return ($mm * 96) / 25.4;
	}

	/**
	 * Draw a horizontal line
	 */
	private function _draw_line(): void
	{
		$this->Line($this->lMargin, $this->y, ($this->w - $this->rMargin), $this->y);
	}

	/**
	 * Draw a filled rectangle
	 */
	private function _draw_rectangle(): void
	{
		$this->Rect($this->lMargin, $this->y, (int) ($this->w - $this->lMargin - $this->lMargin), 1, 'F');
	}

	/**
	 * Returns the available page width for printing
	 */
	private function _get_page_width(): void
	{
		$this->page_width = $this->w - $this->rMargin - $this->lMargin;
	}

	/**
	 * Returns the available page height for printing
	 */
	private function _get_page_height(): void
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
	protected int $position;
	protected string $varname;

	/**
	 * Callback for fopen()
	 *
	 * @param string $path
	 *
	 * @return boolean
	 */
	public function stream_open(string $path): bool
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
	public function stream_read(int $count): string
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
	public function stream_write(string $data): int
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
	public function stream_eof(): bool
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
	public function stream_seek(int $offset, int $whence): bool
	{
		$result = true;

		switch ($whence)
		{
			case SEEK_SET:
				if ($offset >= 0 && $offset < strlen($GLOBALS[$this->varname]))
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
	public function stream_stat(): array
	{
		return array();
	}
}
