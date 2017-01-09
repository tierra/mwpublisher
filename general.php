<?php

/** \file Contains miscellaneous helper functions and classes.
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

require_once "config.php";
require_once "parser.php";
require_once "wikipage.php";

define('MWP_NOTICE',	0);
define('MWP_WARNING',	1);
define('MWP_ERROR',	2);
define('MWP_DEBUG',	3);

if(!function_exists('file_get_contents'))
{
	# Exists in PHP 4.3.0+
	function file_get_contents($filename)
	{
		return implode('', file($filename));
	}
}

if(!function_exists('file_put_contents'))
{
	# Exists in PHP 5
	function file_put_contents($filename, $data)
	{
		$fp = fopen($filename, 'w');
		$bytes = fwrite($fp, $data);
		fclose($fp);
		return $bytes;
	}
}

function microtime_float()
{
	list($usec, $sec) = explode(" ", microtime());
	return ((float)$usec + (float)$sec);
}

function append_trailing_slash(&$value)
{
	if(preg_match(':[\\/]$:', $value) == 0)
		$value .= '/';
}

function mwpMessage($message, $type = MWP_NOTICE)
{
	switch($type)
	{
	case MWP_NOTICE:
		echo "<p class=\"mwp_notice\">" . $message . "</p>\n";
		break;
	case MWP_WARNING:
		echo "<p class=\"mwp_warning\">Warning: " . $message . "</p>\n";
		break;
	case MWP_ERROR:
		echo "<p class=\"mwp_error\">Error: " . $message . "</p>\n";
		break;
	case MWP_DEBUG:
		if(defined('DEBUG'))
			echo "<p class=\"mwp_debug\">Debug: " . $message . "</p>\n";
		break;
	}

	flush();
}

class mwpLocation
{
	var $language = false;
	var $namespace = false;
	var $title = '';
	var $section = false;
	var $escaped = false;

	function mwpLocation($location)
	{
		//mwpMessage("Location Step 1: " . $location, MWP_DEBUG);

		// Strip the image / category / interlanguage link escape syntax
		if(strpos($location, ':') === 0)
		{
			$this->set_escaped();
			$location = substr($location, 1);
		}

		//mwpMessage("Location Step 2: " . $location, MWP_DEBUG);

		// Strip namespace while checking for ISO 639 language codes

		static $iso_639_codes;
		if(!is_array($iso_639_codes))
		{
			$iso_639_codes = explode(',', file_get_contents('iso-639-1.txt'));
			if(defined('ISO_639_3'))
				$iso_639_codes += explode(',', file_get_contents('iso-639-3.txt'));
		}

		if(strpos($location, ':') !== false)
		{
			$pos = strpos($location, ':');
			$value = substr($location, 0, $pos);
			$location = substr($location, $pos + 1);
			//mwpMessage("Location Step 3: " . $location, MWP_DEBUG);
			//$time = microtime_float();
			if(in_array($value, $iso_639_codes))
			{
				$this->language = $value;
				if(strpos($location, ':') !== false)
				{
					$pos = strpos($location, ':');
					$this->namespace = substr($location, 0, $pos);
					$location = substr($location, $pos + 1);
				}
			}
			else
				$this->namespace = $value;
			//mwpMessage(sprintf("Language code check time: %.6f seconds", microtime_float() - $time));
		}

		//mwpMessage("Location Step 4: " . $location, MWP_DEBUG);

		// Namespaces are case-insensitive despite the $wgCapitalLinks setting
		if($this->namespace !== false)
			$this->namespace = strtolower($this->namespace);

		//mwpMessage("Location Step 5: " . $location, MWP_DEBUG);

		// Finish with the title and section
		if(strpos($location, '#') !== false)
		{
			$pos = strpos($location, '#');
			$this->title = substr($location, 0, $pos);
			$this->section = substr($location, $pos + 1);
		}
		else
			$this->title = $location;

		if($this->title == '')
			mwpMessage("Invalid location specified: $location", MWP_ERROR);

		//mwpMessage("Final Location: " . $this->to_string(), MWP_DEBUG);
	}

	function is_same_as($location)
	{
		if($this->get_title() != $location->get_title())
			return false;

		if($this->get_language() != $location->get_language())
			return false;

		if($this->get_section() != $location->get_section())
			return false;

		if($this->get_namespace() != $location->get_namespace() &&
			!$this->same_template_namespace($this, $location) &&
			!$this->same_template_namespace($location, $this))
			return false;

		return true;
	}

	/** Templates can be specified without explicitly declaring the namespace.
	 *  This function helps check against that. Precondition: Both locations
	 *  must have different namespaces. */
	function same_template_namespace($first, $second)
	{
		if($first->get_namespace() === false && !$first->is_escaped() &&
			$second->get_namespace() == "template")
			return true;
		return false;
	}

	/* Save this for when PHP4 compatibility is dropped.
	function __toString()
	{
		return get_title();
	}*/

	function to_string($with_escape = true)
	{
		$string = '';
		if($with_escape && $this->is_escaped())
			$string .= ':';
		$string .= $this->get_full_title();
		if($this->get_section() !== false)
			$string .= '#' . $this->get_section();
		return $string;
	}

	function get_language()
	{
		return $this->language;
	}

	/** Set the language using either ISO 639-1 or 639-3 codes. */
	function set_language($language)
	{
		$this->language = $language;
	}

	function get_namespace()
	{
		return $this->namespace;
	}

	function set_namespace($namespace)
	{
		$this->namespace = $namespace;
	}

	function get_title()
	{
		return $this->title;
	}

	function set_title($title)
	{
		$this->title = $title;
	}

	function get_full_title()
	{
		$full = '';

		if($this->language)
			$full .= $this->language . ':';
		if($this->namespace)
			$full .= ucfirst($this->namespace) . ':';

		return $full . $this->title;
	}

	function get_stripped_title()
	{
		// With MediaWiki syntax, '[[main:Media (album)|]]' is labelled 'Media'.
		return trim(preg_replace('/(?U:\(.*\)$)/', '', $this->get_title()));
	}

	function get_section()
	{
		return $this->section;
	}

	function set_section($section)
	{
		$this->section = $section;
	}

	function is_escaped()
	{
		return $this->escaped;
	}

	function set_escaped($value = true)
	{
		$this->escaped = $value;
	}

	function get_url()
	{
		global $MWURL;

		return $MWURL . "index.php/Special:Export/" .
			urlencode(str_replace(' ', '_', $this->get_full_title()));
	}
}

?>
