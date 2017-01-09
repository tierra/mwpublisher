<?php

/** \file MediaWiki page retrieval and representation.
 *
 *  \date    Revision date:      $Date$
 *  \version Revision version:   $Revision$
 *  \author  Revision committer: $Author$
 *
 *  Copyright (C) 2006 Bryan Petty <bryan@ibaku.net>
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

// Type tracking of XML character data when parsing MediaWiki export data.
define('MWP_XMLTAG_NONE',	0);
define('MWP_XMLTAG_TITLE',	1);
define('MWP_XMLTAG_PAGE',	2);
define('MWP_XMLTAG_TIMESTAMP',	3);
define('MWP_XMLTAG_TEXT',	4);

class mwpWikiPage
{
	var $title = '';	// Actual page title (without section, case
				// sensitive) reported by MediaWiki on export.
	var $fetched = false;	// Indicates if this page has been retrieved.
	var $exists = false;	// Indicates if the MediaWiki was found and has
				// content. Also guilty until proven innocent.
	var $source = '';	// Original wiki page source.
	var $timestamp = '';	// Last modification time.

	var $xmltag = MWP_XMLTAG_NONE;

	function mwpWikiPage($location)
	{
		// This will be set to false if detected the same later
		$this->fetched = false;
		$this->exists = false;
		$this->source = '';
		$this->timestamp = '';

		$this->fetch_source($location);
	}

	/* Save this for when PHP4 compatibility is dropped.
	function __toString()
	{
		return $this->to_string();
	}*/

	function to_string()
	{
		return 'Page: "' . $this->get_title() .
			'"<br/>Fetched: ' . $this->fetched ? 'Yes' : 'No' .
			'<br/>Exists: ' . $this->exists() ? 'Yes' : 'No' .
			'<br/>Timestamp: ' . $this->format_timestamp();
	}

	function get_title()
	{
		return $this->title;
	}

	function get_timestamp()
	{
		return $this->timestamp;
	}

	function format_timestamp($format = DATE_RFC822)
	{
		return date($format, $this->timestamp);
	}

	function set_timestamp($timestamp)
	{
		$this->timestamp = $timestamp;
	}

	function get_source()
	{
		if(!$this->exists())
		{
			mwpMessage("Invalid page source returned.\n<br/>" .
				$this->to_string(), MWP_ERROR);
		}

		return $this->source;
	}

	function fetch_source($location)
	{
		static $page_cache = array();

		if($this->fetched)
			return true;

		// Check if it's in our cache first.
		if(isset($page_cache[$location->get_title()]))
		{
			if($page_cache[$location->get_title()] === false)
			{
				$this->mark_found(false);
				$this->source = '';
			}
			else
			{
				$this->mark_found(true);
				$this->source = $page_cache[$location->get_title()];
			}
			$this->fetched = true;
		}
		else
		{
			// Every page request will tack on an extra 60 second buffer to
			// avoid max_execution_time issues even though work is being done.
			set_time_limit(60);

			$pagexml = '';
			mwpMessage('Fetching: ' . $location->get_title());
			mwpMessage('Page URL: ' . $location->get_url(), MWP_DEBUG);
			$stream = fopen($location->get_url(), 'r');

			if($stream)
			{
				while(!feof($stream))
					$pagexml .= fread($stream, 4096);
				fclose($stream);

				$xml_parser = xml_parser_create();
				xml_set_object($xml_parser, $this);
				xml_set_element_handler($xml_parser,
					"xml_start_element_handler", "xml_end_element_handler");
				xml_set_character_data_handler($xml_parser, "xml_character_handler");
				if(!xml_parse($xml_parser, $pagexml, true))
				{
					mwpMessage(sprintf("XML Parsing Error: %s at line %d",
						xml_error_string(xml_get_error_code($xml_parser)),
						xml_get_current_line_number($xml_parser)), MWP_ERROR);
					xml_parser_free($xml_parser);
					return false;
				}
				xml_parser_free($xml_parser);

				// Cache the source to help avoid duplicate lookups.
				$page_cache[$location->get_title()] = $this->exists() ? $this->source : false;
				$fetched = true;
				return true;
			}

			mwpMessage('Failed to open stream.', MWP_DEBUG);
			fclose($stream);
			return false;
		}

		return true;
	}

	function xml_start_element_handler($parser, $name, $attribs)
	{
		//mwpMessage("XML Element Found: $name", MWP_DEBUG);

		switch($name)
		{
		case 'TITLE':
			$this->xmltag = MWP_XMLTAG_TITLE;
			break;
		case 'PAGE':
			$this->xmltag = MWP_XMLTAG_PAGE;
			$this->mark_found(true);
			break;
		case 'TIMESTAMP':
			$this->xmltag = MWP_XMLTAG_TIMESTAMP;
			$this->set_timestamp('');
			break;
		case 'TEXT':
			$this->xmltag = MWP_XMLTAG_TEXT;
			$this->source = '';
			break;
		}
	}

	function xml_end_element_handler($parser, $name)
	{
		$this->xmltag = MWP_XMLTAG_NONE;
	}

	function xml_character_handler($parser, $data)
	{
		switch($this->xmltag)
		{
		case MWP_XMLTAG_TITLE:
			$this->title .= $data;
			break;
		case MWP_XMLTAG_TIMESTAMP:
			$this->set_timestamp($data);
			break;
		case MWP_XMLTAG_TEXT:
			$this->source .= $data;
			break;
		}
	}

	function mark_found($found)
	{
		$this->exists = $found;
	}

	function exists()
	{
		return $this->exists;
	}
}

?>
