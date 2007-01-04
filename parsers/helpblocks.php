<?php

/** \file MediaWiki Publisher HelpBlocks Parser.
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

class mwpHelpBlocksParser extends mwpGenericParser
{
	/// Array of pages (values) to retrieve and filenames (key) to save to.
	var $pages = array();

	/// Directory to save images to.
	var $image_dir = "images/";

	/// Directory to save HelpBlocks page files to.
	var $htd_dir = "htd/";

	/// Internally used page link cache.
	var $links = array();

	function mwpHelpBlocksParser()
	{
		parent::mwpGenericParser();

		$this->set_id('helpblocks');
		$this->set_name('HelpBlocks');
	}

	function add_page($filename, $location)
	{
		$this->pages[$filename] = $location;
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
	function set_htd_dir($dir)
	{
		$this->htd_dir = $dir;
	}

	function parse()
	{
		// Index all pages first for internal link lookup.
		foreach($this->pages as $filename => $location)
		{
			$this->links[$location->to_string(false)] = $filename;
		}

		foreach($this->pages as $filename => $location)
		{
			$wikipage = new mwpWikiPage($location);
			mwpMessage("HelpBlocks: Parsing page: " . $location->to_string());
			$output = parent::parse_page($wikipage, $location);
			file_put_contents($this->htd_dir . $filename . '.htd', $output);
		}

		mwpMessage("Done.");
	}

	function handle_image_file($location, $file_info)
	{
		mwpMessage("HelpBlocks: Downloading image: " . $file_info['filename']);
		file_put_contents($this->image_dir . $file_info['filename'],
			file_get_contents($file_info['url']));
	}

	function format_internal_link($location, $label, $link)
	{
		//mwpMessage("HelpBlocks: Internal Link: [$label] \"" . $location->to_string() . "\"", MWP_DEBUG);

		// Here's to hoping this works right.
		$gpp_safe = str_replace(',', '\,', $label);
		$gpp_safe = str_replace('(', '\(', $gpp_safe);
		$gpp_safe = str_replace(')', '\)', $gpp_safe);
		
		// Confirm valid link to section specific page
		if($location->get_section() && isset($this->links[$location->to_string(false)]))
		{
			// HelpBlocks Link Macro (to differentiate links in monolithic builds)
			$replacement = "_HREF(" . $this->links[$location->to_string(false)] . ",$gpp_safe)";
		}
		// Confirm valid link to non-section specific page
		else if(isset($this->links[$location->get_full_title()]))
		{
			// HelpBlocks Link Macro (to differentiate links in monolithic builds)
			if($location->get_section() !== false)
				$replacement = "\n#ifdef _FORMAT_SINGLE_FILE\n<a href=\"#" .
					$location->get_section() . "\">$label</a>" . "\n#else\n<a href=\"" .
					$this->links[$location->get_full_title()] . ".htm#" .
					$location->get_section() . "\">$label</a>\n#endif\n";
			else
				$replacement = "_HREF(" . $this->links[$location->get_full_title()] . ",$gpp_safe)";
		}
		else
		{
			$replacement = $label;
			mwpMessage('HelpBlocks: Failed to resolve internal link: [[' . $link . ']]', MWP_WARNING);
		}

		return $replacement;
	}

	function format_external_link($url, $label = '')
	{
		// We override this function to open in a new window.
		if($label == '')
			return "<a href=\"$url\" target=\"new\">$url</a>";
		return "<a href=\"$url\" target=\"new\">$label</a>";
	}
}

?>
