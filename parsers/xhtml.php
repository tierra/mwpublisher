<?php

/** \file MediaWiki Publisher XHTML Parser.
 *
 *  \date    Revision date:      $Date$
 *  \version Revision version:   $Revision$
 *  \author  Revision committer: $Author$
 *
 *  Copyright (C) 2006 Bryan Petty <bryan@mwpublisher.org> et al
 *  MediaWiki Publisher - http://mwpublisher.org/
 *
 *  This file is part of MediaWiki Publisher.
 *
 *  MediaWiki Publisher is free software; you can redistribute it and/or
 *  modify it under the terms of version 2 of the GNU General Public
 *  License as published by the Free Software Foundation.
 *
 *  MediaWiki Publisher is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with MediaWiki Publisher; if not, write to the Free Software
 *  Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA 02110-1301 USA
 *  http://www.gnu.org/licenses/gpl.html
 */

require_once "general.php";
require_once "parser.php";
require_once "wikipage.php";

class mwpXHTMLParser extends mwpGenericParser
{
	/// Array of pages (values) to retrieve and filenames (key) to save to.
	var $pages = array();

	/// Directory to save images to.
	var $image_dir = "images/";

	/// Directory to save HelpBlocks page files to.
	var $xhtml_dir = "xhtml/";

	/// Extension to save XHTML files with.
	var $xhtml_extension = "htm";

	/// Header and footer to be added to every page.
	var $header = '';
	var $footer = '';

	/// Internally used page link cache.
	var $links = array();

	function mwpXHTMLParser()
	{
		parent::mwpGenericParser();

		$this->set_id('xhtml');
		$this->set_name('XHTML 1.0');
	}

	function add_page($filename, $location, $title = '')
	{
		if($title == '')
			$this->pages[$filename] = array($location, $location->get_title());
		else
			$this->pages[$filename] = array($location, $title);
	}

	/** Sets the location where images should be saved off to. This
	 *  directory needs to be writable by the webserver or whoever
	 *  is running the script. */
	function set_image_dir($dir)
	{
		$this->image_dir = $dir;
	}

	/** Sets the location where HelpBlocks page files should be saved
	 *  off to. This directory needs to be writable by the webserver or
	 *  whoever is running the script. */
	function set_xhtml_dir($dir)
	{
		$this->xhtml_dir = $dir;
	}

	/** Sets the extension to be used when saving off XHTML files. This
	 *  can be used to save for any scripting language, and include code
	 *  in your header and footer for style information. */
	function set_xhtml_extension($ext)
	{
		$this->xhtml_extension = $ext;
	}

	/** Includes the given header in all saved XHTML files. */
	function set_header($header)
	{
		$this->header = $header;
	}

	/** Includes the given footer in all saved XHTML files. */
	function set_footer($footer)
	{
		$this->footer = $footer;
	}

	function parse()
	{
		// Index all pages first for internal link lookup.
		$lastpage = array('', '');
		foreach($this->pages as $filename => $info)
		{
			list($location, $title) = $info;
			$this->links[$location->to_string(false)] = $filename;

			$this->pages[$filename] = array_merge($this->pages[$filename], $lastpage);
			$lastpage = array("$filename." . $this->xhtml_extension, $title);
		}

		$this->pages = array_reverse($this->pages, true);

		$lastpage = array('', '');
		foreach($this->pages as $filename => $info)
		{
			list($location, $title, $prev_page, $prev_page_title) = $info;
			$this->pages[$filename] = array_merge($this->pages[$filename], $lastpage);
			$lastpage = array("$filename." . $this->xhtml_extension, $title);
		}

		$this->pages = array_reverse($this->pages, true);

		foreach($this->pages as $filename => $info)
		{
			$output = '';
			list($location, $title, $prev_page, $prev_page_title,
				$next_page, $next_page_title) = $info;

			// Header
			$header = $this->header;
			$header = preg_replace('/\{TOPIC\}/', $title, $header);
			$header = preg_replace('/\{PREV_PAGE\}/', $prev_page, $header);
			$header = preg_replace('/\{PREV_PAGE_TITLE\}/', $prev_page_title, $header);
			$header = preg_replace('/\{NEXT_PAGE\}/', $next_page, $header);
			$header = preg_replace('/\{NEXT_PAGE_TITLE\}/', $next_page_title, $header);
			$output .= $header;

			// Content
			$wikipage = new mwpWikiPage($location);
			mwpMessage("XHTML: Parsing page: [$title] " . $location->to_string());
			$output .= parent::parse_page($wikipage, $location);

			// Footer
			$footer = $this->footer;
			$footer = preg_replace('/\{TOPIC\}/', $title, $footer);
			$footer = preg_replace('/\{PREV_PAGE\}/', $prev_page, $footer);
			$footer = preg_replace('/\{PREV_PAGE_TITLE\}/', $prev_page_title, $footer);
			$footer = preg_replace('/\{NEXT_PAGE\}/', $next_page, $footer);
			$footer = preg_replace('/\{NEXT_PAGE_TITLE\}/', $next_page_title, $footer);
			$output .= $footer;

			file_put_contents($this->xhtml_dir . "$filename." .
				$this->xhtml_extension, $output);
		}

		mwpMessage("Done.");
	}

	function handle_image_file($location, $file_info)
	{
		mwpMessage("XHTML: Downloading image: " . $file_info['filename']);
		file_put_contents($this->image_dir . $file_info['filename'],
			file_get_contents($file_info['url']));
	}

	function format_internal_link($location, $label, $link)
	{
		//mwpMessage("XHTML: Internal Link: [$label] \"" . $location->to_string() . "\"", MWP_DEBUG);

		// Confirm valid link to section specific page
		if($location->get_section() && isset($this->links[$location->to_string(false)]))
		{
			$replacement = '<a href="' . $this->links[$location->to_string(false)] .
				'.' . $this->xhtml_extension . '#' .
				urlencode($location->get_section()) . "\">$label</a>";
		}
		// Confirm valid link to non-section specific page
		else if(isset($this->links[$location->get_full_title()]))
		{
			if($location->get_section() !== false)
				$replacement = '<a href="' . $this->links[$location->get_full_title()] .
					'.' . $this->xhtml_extension . '#' .
					urlencode($location->get_section()) . "\">$label</a>";
			else
				$replacement = '<a href="' . $this->links[$location->get_full_title()] .
					'.' . $this->xhtml_extension . "\">$label</a>";
		}
		else
		{
			$replacement = $label;
			mwpMessage('XHTML: Failed to resolve internal link: [[' . $link . ']]', MWP_WARNING);
		}

		return $replacement;
	}
}

?>
