<?php

/** \file MediaWiki syntax parsing functions.
 *
 *  \date    Revision date:      $Date$
 *  \version Revision version:   $Revision$
 *  \author  Revision committer: $Author$
 *
 *  Copyright (C) 2006 Bryan Petty <bryan@mwpublisher.org> et al
 *  MediaWiki Publisher - http://mwpublisher.org/
 *  Copyright (C) 2002-2005 Brion Vibber <brion@pobox.com> et al
 *  MediaWiki - http://www.mediawiki.org/
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

require_once 'config.php';
require_once 'general.php';
require_once 'sanitizer.php';
require_once 'wikipage.php';

$mw_title_chars = " %!\"$&'()*,\\-.\\/0-9:;=?@A-Z\\\\^_`a-z~\\x80-\\xFF";

/** Base parser class, all parsers must extend this. I'd use an interface or
 *  abstract class, but I'm trying to keep PHP4 compatability.*/
class mwpParser
{
	var $id = '';	// Set this to a unique URL friendly one word
			// string for your parser.
	var $name = '';	// Set this to a human readable parser name.

	/** The main entry function to trigger parser processing.
	 *  This function is responsible for implementing all page
	 *  processing and file output. */
	function parse()
	{ return false; }

	/** Get this parser's unique, URL friendly name. */
	function get_id()
	{ return $this->id; }

	/** Get this parser's human readable name. */
	function get_name()
	{ return $this->name; }

	/** Set this parser's unique, URL friendly name. */
	function set_id($id)
	{ $this->id = $id; }

	/** Set this parser's human readable name. */
	function set_name($name)
	{ $this->name = $name; }
}

/** Generic parser that implements all basic, common functionality.
 *  Most parsers should be able to simply derive from this parser, and
 *  override a few of the basic functions for necessary changes. */
class mwpGenericParser extends mwpParser
{
	/// Array of templates by page name to exclude from substitution.
	/// Namespace rules apply here, specify ":Box" for excluding only the
	/// main namespace article. "Box" will assume the article in the
	/// "Template" namespace. These need to be instances of mwpLocation.
	var $excluded_templates = array();

	/// Array of magic variable replacement function callbacks. Add
	/// functions that return string replacements for the magic variable
	/// keyword set as key. See mwpGenericParser constructor for examples.
	var $magic_vars = array();

	// Internal variables used during parsing.

	var $blockInPre		= false;
	var $blockLastSection	= '';
	var $blockDToopen	= false;
	// Lets try to get away without needing this...
	//var $blockUniqPrefix	= 'UNIQ' . dechex(mt_rand(0, 0x7fffffff)) . dechex(mt_rand(0, 0x7fffffff));

	function mwpGenericParser()
	{
		if($this->get_id() == '')
			$this->set_id('generic');
		if($this->get_name() == '')
			$this->set_name('Generic');

		// Default magic variable replacement functions. To disable any
		// of these, erase them from the array after parser creation.
		$this->magic_vars['CURRENTMONTHNAME']
			= $this->mv_function('return date("F");');
		$this->magic_vars['CURRENTDAY']
			= $this->mv_function('return date("j");');
		$this->magic_vars['CURRENTYEAR']
			= $this->mv_function('return date("Y");');
	}

	/** The main parser initiation functions. This needs to be overridden
	 *  to handle page setup and serialization of generated output. */
	function parse()
	{
		mwpMessage("The generic parser does not implement a page " .
			"handler, please use a valid parser.", MWP_ERROR);
	}

	/** Call this function for typical page parsing. The steps taken
	 *  here have been broken down into various functions that can be
	 *  overridden and disabled or changed. You can still override to do
	 *  pre or post parsing and still call the parent::parse(). */
	function parse_page($wikipage, $location)
	{
		global $mw_title_chars;
		$text = $wikipage->get_source();

		// This is all handled in the same order MediaWiki's parser handles it
		// except templates which I moved to before magic variables instead of
		// after so any magic variables in any templates are handled the same
		// time as the rest of the page (which they may have been in MediaWiki,
		// but I've completely rewritten the template and variable parsing
		// methods breaking any chance of it working that way).

		# templates
		$text = preg_replace_callback("/{{([$mw_title_chars]+?)}}/",
			array($this, 'sub_template'), $text);

		# magic variables
		$text = preg_replace_callback("/{{([$mw_title_chars]+?)}}/",
			array($this, 'sub_mv'), $text);

		# lines
		$text = $this->format_lines($text);

		# headings - this step is required for strip_section() to work
		$text = $this->format_headings($text);

		# strip down to section if specified (not original functionality of MediaWiki)
		if($location->get_section() !== false)
			$text = $this->strip_section($text, $location->get_section());

		// Clean up any markup we left to distinguish sections.
		$text = preg_replace('/<!-- MWP(?U:.*) -->/', '', $text);

		# quotes (simple text formatting)
		$text = $this->format_quotes($text);

		# links and images
		$text = preg_replace_callback("/\[\[(.+)\]\]/",
			array($this, 'sub_internal_link'), $text);
		$text = preg_replace_callback("/\[(.+)\]/",
			array($this, 'sub_external_link'), $text);

		# tables
		$text = $this->format_tables($text);

		# block level elements (':', '*', '#', paragraphs, etc)
		$text = $this->format_block_levels($text, $linestart);

		return $text;
	}

	function exclude_template($template)
	{
		$this->excluded_templates[] = new mwpLocation($template);
	}

	function sub_template($matches)
	{
		// To disable template substitution, override this function
		// and return $matches[0].

		global $mw_title_chars;
		static $loop_stack = array();

		// If we can't find a matching template in the wiki,
		// it's possibly a magic variable, so we'll leave it alone.
		$replacement = $matches[0];

		// Skip replacement if a loop is detected.
		if(in_array($matches[1], $loop_stack))
		{
			mwpMessage("Template recursion detected: " . $replacement, MWP_WARNING);
			return $replacement;
		}
		array_push($loop_stack, $matches[1]);

		$location = new mwpLocation($matches[1]);
		if(!$location->is_escaped() && $location->get_namespace() == false)
			$location->set_namespace('template');

		// It's common to use templates for marking articles that
		// need work done on them, so you can add those templates
		// to $excluded_templates to filter them out.
		foreach($this->excluded_templates as $template)
		{
			if(!$template->is_escaped() && $template->get_namespace() == false)
			{
				$name_fix = $template;
				$name_fix->set_namespace('template');
				if($name_fix->is_same_as($location))
				{
					array_pop($loop_stack);
					return '';
				}
			}
			else
				if($template->is_same_as($location))
				{
					array_pop($loop_stack);
					return '';
				}
		}

		$wikipage = new mwpWikiPage($location);

		if($wikipage->exists())
		{
			$twikitext = $wikipage->get_source();
			// Recursive Templates, Yay!
			$twikitext = preg_replace_callback("/{{([$mw_title_chars]*?)}}/",
				array($this, 'sub_template'), $twikitext);
			$replacement = $twikitext;
		}

		array_pop($loop_stack);
		return $replacement;
	}

	function sub_mv($matches)
	{
		$result = $this->sub_mv_keyword($matches[1]);
		if($result !== false)
			return $result;
		return $matches[0];
	}

	function sub_mv_keyword($keyword, $replacement = false)
	{
		// Supporting full MediaWiki MagicWords is a little more work
		// than this script was originally meant for, so we'll
		// handle them on a per use basis (add them as they get used).
		// See mwpGenericParser constructor and $magic_vars comments.

		// If overridding this function (not recommended unless
		// $magic_vars capability isn't enough), it would be good
		// practice to call this parent function for additional
		// features like notification and any future functionality.
		// Simply add this call last:
		// return parent::sub_mv_keyword($keyword, $replacement);

		if($replacement === false)
		{
			if(array_key_exists($keyword, $this->magic_vars))
			{
				if(is_callable($this->magic_vars[$keyword]))
				{
					$replacement = $this->magic_vars[$keyword]();
					//mwpMessage("Replacing \"$keyword\" magic variable with: $replacement", MWP_DEBUG);
				}
				else
					mwpMessage("Invalid magic variable replacement method for \"$keyword\".", MWP_WARNING);
			}
			else
				mwpMessage("Unknown magic variable or missing template: {{{$keyword}}}", MWP_WARNING);
		}

		if($replacement !== false)
			mwpMessage("Magic variable substitution: {{{$keyword}}}: $replacement");

		return $replacement;
	}

	function mv_function($code)
	{
		// This function may be extended in the future to pull in
		// additional external information, functions, or libraries
		// if needed for magic variables.
		return create_function('', $code);
	}

	function format_lines($text)
	{
		// If outputting anything besides HTML, you will want to
		// override this function.
		return preg_replace('/(^|\n)-----*/', '\\1<hr />', $text);
	}

	function format_headings($text)
	{
		for($i = 6; $i >= 1; --$i)
		{
			$h = substr('======', 0, $i);
			$text = preg_replace_callback("/^($h)(.+)$h(\\s|$)/m",
				array($this, "format_headings_level"), $text);
		}
		return $text;
	}

	function format_headings_level($matches)
	{
		//mwpMessage('Heading: "' . $matches[1] . '", "' . $matches[2] . '"', MWP_DEBUG);
		$level = strlen($matches[1]);
		if($level > 6) $level = 6;
		if($level < 1) $level = 1;
		$t = $this->clean_heading($matches[2]);
		$extra = '';
		if(trim($matches[3]) != '')
			$extra = ' ' . trim($matches[3]);
		return "<!-- MWP-PREHEAD --><a name=\"$t\"></a><h$level>" .
			trim($matches[2]) . "</h$level>" . $extra . "<!-- MWP:$level:$t -->";
	}

	function clean_heading($text)
	{
		// Strip out links and images (as we use them in the heading in a few places)
		// The string returned here is used for the actual anchor of the heading,
		// not for visual output.
		return trim(preg_replace("/\[\[(.*)\]\]/", '', $text));
	}

	function strip_section($text, $section)
	{
		// By default, we return a blank page if the section wasn't found correctly
		// since if we include a section, that usually means the rest of the page
		// is not material we want published and could contain sensitive information
		// as well as not being formatted for the intended audience of the publication.

		$level = 0;
		$split = explode('<!-- ', $text);

		//mwpMessage("Section Search: \"$section\"", MWP_DEBUG);

		$i = 0;
		for(; $i < count($split); $i++)
		{
			$part = $split[$i];

			if(substr($part, 0, 4) != 'MWP:')
				continue;

			$label = substr($part, 6, strpos($part, ' -->') - 6);

			//mwpMessage("Beginning Section: \"$label\" Level: " . substr($part, 4, 1), MWP_DEBUG);

			if($label == $section)
			{
				//mwpMessage("Anchor Found: \"$label\" Section: \"$section\"", MWP_DEBUG);
				$level = substr($part, 4, 1);
				break;
			}
		}

		$newsection = '';
		
		if($level != 0)
		{
			$beginning = $i;

			for($i++ ; $i < count($split); $i++)
			{
				$part = $split[$i];

				if(substr($part, 0, 4) != 'MWP:')
					continue;

				$label = substr($part, 6, strpos($part, ' -->') - 6);
				$endlevel = substr($part, 4, 1);

				//mwpMessage("End Section: \"$label\" Level: $endlevel", MWP_DEBUG);

				// If we don't run into this, it should grab the rest of the page
				if($endlevel <= $level)
				{
					$i--;
					break;
				}
			}

			$end = $i;
			$split = array_slice($split, $beginning, $end - $beginning);
			$newsection = '<!-- ' . implode('<!-- ', $split);
		}
		else
		{
			mwpMessage("Page section \"$section\" not found, truncating page.", MWP_WARNING);
		}

		return $newsection;
	}

	function format_quotes($text)
	{
		$outtext = '';
		$lines = explode("\n", $text);
		foreach($lines as $line)
			$outtext .= $this->format_single_quote($line) . "\n";
		$outtext = substr($outtext, 0, -1);
		return $outtext;
	}

	function format_single_quote($text)
	{
		$arr = preg_split( "/(''+)/", $text, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( count( $arr ) == 1 )
			return $text;
		else
		{
			# First, do some preliminary work. This may shift some apostrophes from
			# being mark-up to being text. It also counts the number of occurrences
			# of bold and italics mark-ups.
			$i = 0;
			$numbold = 0;
			$numitalics = 0;
			foreach ( $arr as $r )
			{
				if ( ( $i % 2 ) == 1 )
				{
					# If there are ever four apostrophes, assume the first is supposed to
					# be text, and the remaining three constitute mark-up for bold text.
					if ( strlen( $arr[$i] ) == 4 )
					{
						$arr[$i-1] .= "'";
						$arr[$i] = "'''";
					}
					# If there are more than 5 apostrophes in a row, assume they're all
					# text except for the last 5.
					else if ( strlen( $arr[$i] ) > 5 )
					{
						$arr[$i-1] .= str_repeat( "'", strlen( $arr[$i] ) - 5 );
						$arr[$i] = "'''''";
					}
					# Count the number of occurrences of bold and italics mark-ups.
					# We are not counting sequences of five apostrophes.
					if ( strlen( $arr[$i] ) == 2 ) $numitalics++;  else
					if ( strlen( $arr[$i] ) == 3 ) $numbold++;     else
					if ( strlen( $arr[$i] ) == 5 ) { $numitalics++; $numbold++; }
				}
				$i++;
			}

			# If there is an odd number of both bold and italics, it is likely
			# that one of the bold ones was meant to be an apostrophe followed
			# by italics. Which one we cannot know for certain, but it is more
			# likely to be one that has a single-letter word before it.
			if ( ( $numbold % 2 == 1 ) && ( $numitalics % 2 == 1 ) )
			{
				$i = 0;
				$firstsingleletterword = -1;
				$firstmultiletterword = -1;
				$firstspace = -1;
				foreach ( $arr as $r )
				{
					if ( ( $i % 2 == 1 ) and ( strlen( $r ) == 3 ) )
					{
						$x1 = substr ($arr[$i-1], -1);
						$x2 = substr ($arr[$i-1], -2, 1);
						if ($x1 == ' ') {
							if ($firstspace == -1) $firstspace = $i;
						} else if ($x2 == ' ') {
							if ($firstsingleletterword == -1) $firstsingleletterword = $i;
						} else {
							if ($firstmultiletterword == -1) $firstmultiletterword = $i;
						}
					}
					$i++;
				}

				# If there is a single-letter word, use it!
				if ($firstsingleletterword > -1)
				{
					$arr [ $firstsingleletterword ] = "''";
					$arr [ $firstsingleletterword-1 ] .= "'";
				}
				# If not, but there's a multi-letter word, use that one.
				else if ($firstmultiletterword > -1)
				{
					$arr [ $firstmultiletterword ] = "''";
					$arr [ $firstmultiletterword-1 ] .= "'";
				}
				# ... otherwise use the first one that has neither.
				# (notice that it is possible for all three to be -1 if, for example,
				# there is only one pentuple-apostrophe in the line)
				else if ($firstspace > -1)
				{
					$arr [ $firstspace ] = "''";
					$arr [ $firstspace-1 ] .= "'";
				}
			}

			# Now let's actually convert our apostrophic mush to HTML!
			$output = '';
			$buffer = '';
			$state = '';
			$i = 0;
			foreach ($arr as $r)
			{
				if (($i % 2) == 0)
				{
					if ($state == 'both')
						$buffer .= $r;
					else
						$output .= $r;
				}
				else
				{
					if (strlen ($r) == 2)
					{
						if ($state == 'i')
						{ $output .= '</i>'; $state = ''; }
						else if ($state == 'bi')
						{ $output .= '</i>'; $state = 'b'; }
						else if ($state == 'ib')
						{ $output .= '</b></i><b>'; $state = 'b'; }
						else if ($state == 'both')
						{ $output .= '<b><i>'.$buffer.'</i>'; $state = 'b'; }
						else # $state can be 'b' or ''
						{ $output .= '<i>'; $state .= 'i'; }
					}
					else if (strlen ($r) == 3)
					{
						if ($state == 'b')
						{ $output .= '</b>'; $state = ''; }
						else if ($state == 'bi')
						{ $output .= '</i></b><i>'; $state = 'i'; }
						else if ($state == 'ib')
						{ $output .= '</b>'; $state = 'i'; }
						else if ($state == 'both')
						{ $output .= '<i><b>'.$buffer.'</b>'; $state = 'i'; }
						else # $state can be 'i' or ''
						{ $output .= '<b>'; $state .= 'b'; }
					}
					else if (strlen ($r) == 5)
					{
						if ($state == 'b')
						{ $output .= '</b><i>'; $state = 'i'; }
						else if ($state == 'i')
						{ $output .= '</i><b>'; $state = 'b'; }
						else if ($state == 'bi')
						{ $output .= '</i></b>'; $state = ''; }
						else if ($state == 'ib')
						{ $output .= '</b></i>'; $state = ''; }
						else if ($state == 'both')
						{ $output .= '<i><b>'.$buffer.'</b></i>'; $state = ''; }
						else # ($state == '')
						{ $buffer = ''; $state = 'both'; }
					}
				}
				$i++;
			}
			# Now close all remaining tags.  Notice that the order is important.
			if ($state == 'b' || $state == 'ib')
				$output .= '</b>';
			if ($state == 'i' || $state == 'bi' || $state == 'ib')
				$output .= '</i>';
			if ($state == 'bi')
				$output .= '</b>';
			if ($state == 'both')
				$output .= '<b><i>'.$buffer.'</i></b>';
			return $output;
		}
	}

	function sub_internal_link($matches)
	{
		$replacement = $matches[0];
		$secondarytext = '';
		$copy = $matches[1];
		$originallink = $copy;
		
		// Recursively handle subsequent internal links on the same line
		// since it seems PREG is a little greedy. There's a way to fix it,
		// but I'm not that good with REGEX, and this works fine.
		if(strpos($matches[0], '[[', 2) !== false)
		{
			$copy = substr($copy, 0, strpos($copy, ']]'));
			$originallink = $copy;
			$secondarytext = substr($matches[0], strpos($matches[0], ']]') + 2);
			$secondarytext = preg_replace_callback("/\[\[(.+)\]\]/", array($this, 'sub_internal_link'), $secondarytext);
		}
		
		$label = '';
		
		// Suck out the label if there is one
		if(strpos($copy, '|') !== false)
		{
			$label = substr($copy, strpos($copy, '|') + 1);
			$copy = substr($copy, 0, strpos($copy, '|'));
		}

		// mwpLocation can take care of the rest ;)
		$location = new mwpLocation($copy);

		if(strstr($location->get_namespace(), "image") !== false && !$location->is_escaped())
			if($label === false)
				$replacement = $this->handle_image($location, array());
			else
				$replacement = $this->handle_image($location, explode('|', $label));
		else
		{
			if($label === false)
				$label = trim(preg_replace('\(.*\)', '', $location->get_title()));
			if($label === '')
				$label = $location->get_full_title();

			$replacement = $this->format_internal_link($location, $label, $originallink);
		}

		//mwpMessage("Internal Link Output: " . htmlentities($replacement), MWP_DEBUG);
		return $replacement . $secondarytext;
	}

	/** This is called for anything in the "Image" namespace found while
	 *  while parsing. This needs to save off image files if needed and
	 *  return formatted replacement output. Look at overriding the
	 *  following functions instead of this one: handle_image_file(), and
	 *  format_image(). */
	function handle_image($location, $styles)
	{
		global $MWURL;
		static $image_cache = array();
		$title = $location->get_title();
		$label = '';
		$alignment = 'none';
		$decoration = 'none';
		$size = -1;

		if(!isset($image_cache[$title]))
		{
			$image_cache[$title] = array();

			// We'll use the "external application image editor" feature
			// of MediaWiki to track down the direct image URL so we avoid
			// dependancy on the MediaWiki DB just for images.
			// /index.php?title=Image:Filename.png&action=edit&externaledit=true&mode=file

			$imageurl = $MWURL . 'index.php?title=Image:' . urlencode($location->get_title()) .
				'&action=edit&externaledit=true&mode=file';
			$tmpimagefile = tempnam('', '');
			file_put_contents($tmpimagefile, file_get_contents($imageurl));
			$imageinfo = parse_ini_file($tmpimagefile);
			//unlink($tmpimagefile);

			$image_cache[$title]['url'] = $imageinfo['URL'];
			$urlinfo = parse_url($imageinfo['URL']);
			$pathinfo = pathinfo($urlinfo['path']);
			$image_cache[$title]['filename'] = $pathinfo['basename'];
		}

		$this->handle_image_file($location, $image_cache[$title]);

		foreach($styles as $style)
		{
			// In the spirit of MediaWiki overwriting previous settings,
			// this will do exactly the same if re-specified.

			$test = strtolower($style);

			$result = preg_match('/^(\d+)px$/', $test, $matches);
			if($result == 1)
				$size = $matches[1];

			switch($test)
			{
			case 'center':
			case 'right':
			case 'left':
			case 'none':
				$alignment = $test;
				break;
			case 'thumbnail':
				$decoration = 'thumb';
				break;
			case 'thumb':
			case 'frame':
				$decoration = $test;
				break;
			default:
				$label = $style;
				break;
			}
		}

		return $this->format_image($location, $label, $alignment,
			$decoration, $size, $image_cache[$title]);
	}

	/** Called from handle_image(), this handles the base work needed to
	 *  save off image files if needed.
	 *	\param $location mwpLocation specified for image.
	 *	\param $file_info Array with the following keys:
	 *		"url": Location for downloading the image file.
	 *		"filename": Base image filename (not always the same as the title). */
	function handle_image_file($location, $file_info)
	{
		// The generic parser doesn't know where, how, or if it needs
		// to save these off, so it's up to the implementing parser to
		// override this.
		return false;
	}

	/** Called from handle_image(), this function serves to format images.
	 *	\param $location mwpLocation specified for this image (not including styles).
	 *	\param $label Alternate text and/or caption to be shown with image.
	 *	\param $alignment Either "left", "center", "right", or "none".
	 *	\param $decoration Can be "thumb" (specifying "thumbnail" also results in "thumb"), "frame", or "none".
	 *	\param $size -1 if not specified, otherwise, the max height and width.
	 *	\param $file_info Array with the following keys:
	 *		"url": Location for downloading the image file.
	 *		"filename": Base image filename (not always the same as the title). */
	function format_image($location, $label, $alignment, $decoration, $size, $file_info)
	{
		// This outputs a simple HTML 4.01 compliant format.

		$replacement = '';
		$extra = '';

		switch($alignment)
		{
		case 'right':
			$extra = ' align="right"';
			break;
		case 'left':
			$extra = ' align="left"';
			break;
		}

		if($label == '')
			$label = $location->get_title();

		if($alignment == 'center')
			$replacement .= '<center>';
		$replacement .= '<img src="images/' . $file_info['filename'] .
			'" alt="' . $label . '"' . $extra . ">";
		if($alignment == 'center')
			$replacement .= '</center>';

		return $replacement;
	}

	/** Function for formatting internal links to other wiki pages/sections.
	 *  You will want to override this so links (if enabled) point to the
	 *  correct location and valid pages (if you only plan to publish parts
	 *  of your wiki).
	 *	\param $location mwpLocation of the page that needs linked.
	 *	\param $label Pre-formatted label to use for the link.
	 *	\param $link Original link text used for this link. */
	function format_internal_link($location, $label, $link)
	{
		// The generic parser doesn't know where, how, or if it needs
		// to actually link this, so it's up to the implementing parser to
		// override this.
		return $label;
	}

	/** Locates all external links that need formatting. See
	 *  format_external_links() for customization. */
	function sub_external_link($matches)
	{
		$replacement = $matches[0];
		$secondarytext = '';
		$copy = $matches[1];

		// Recursively handle subsequent external links on the same line
		if(strpos($matches[0], '[', 1) !== false)
		{
			$copy = substr($copy, 0, strpos($copy, ']'));
			$secondarytext = substr($matches[0], strpos($matches[0], ']') + 1);
			$secondarytext = preg_replace_callback("/\[(.+)\]/", 'sub_external_link', $secondarytext);
		}

		$url = '';
		$label = '';

		if(strpos($copy, ' ') !== false)
		{
			$url = substr($copy, 0, strpos($copy, ' '));
			if(strpos($copy, ' ') + 1 < strlen($copy))
				$label = substr($copy, strpos($copy, ' ') + 1);
		}
		else
		{
			$url = $copy;
		}

		return $this->format_external_link($url, $label) . $secondarytext;
	}

	/** Formats links to external sources (not in the wiki). Override for
	 *  different formatting or behaviour. Defaults to HTML anchors. This
	 *  will show the URL if no label is specified. */
	function format_external_link($url, $label = '')
	{
		if($label == '')
			return "<a href=\"$url\">$url</a>";
		return "<a href=\"$url\">$label</a>";
	}

	function format_tables($text)
	{
		$t = $text;

		$t = explode ( "\n" , $t ) ;
		$td = array () ; # Is currently a td tag open?
		$ltd = array () ; # Was it TD or TH?
		$tr = array () ; # Is currently a tr tag open?
		$ltr = array () ; # tr attributes
		$indent_level = 0; # indent level of the table
		foreach ( $t AS $k => $x )
		{
			$x = trim ( $x ) ;
			$fc = substr ( $x , 0 , 1 ) ;
			if ( preg_match( '/^(:*)\{\|(.*)$/', $x, $matches ) ) {
				$indent_level = strlen( $matches[1] );
				
				$attributes = $this->mw_unstrip_for_html( $matches[2] );
					$t[$k] = str_repeat( '<dl><dd>', $indent_level ) .
					'<table' . Sanitizer::fixTagAttributes ( $attributes, 'table' ) . '>' ;
				array_push ( $td , false ) ;
				array_push ( $ltd , '' ) ;
				array_push ( $tr , false ) ;
				array_push ( $ltr , '' ) ;
			}
			else if ( count ( $td ) == 0 ) { } # Don't do any of the following
			else if ( '|}' == substr ( $x , 0 , 2 ) ) {
				$z = "</table>" . substr ( $x , 2);
				$l = array_pop ( $ltd ) ;
				if ( array_pop ( $tr ) ) $z = '</tr>' . $z ;
				if ( array_pop ( $td ) ) $z = '</'.$l.'>' . $z ;
				array_pop ( $ltr ) ;
				$t[$k] = $z . str_repeat( '</dd></dl>', $indent_level );
			}
			else if ( '|-' == substr ( $x , 0 , 2 ) ) { # Allows for |---------------
				$x = substr ( $x , 1 ) ;
				while ( $x != '' && substr ( $x , 0 , 1 ) == '-' ) $x = substr ( $x , 1 ) ;
				$z = '' ;
				$l = array_pop ( $ltd ) ;
				if ( array_pop ( $tr ) ) $z = '</tr>' . $z ;
				if ( array_pop ( $td ) ) $z = '</'.$l.'>' . $z ;
				array_pop ( $ltr ) ;
				$t[$k] = $z ;
				array_push ( $tr , false ) ;
				array_push ( $td , false ) ;
				array_push ( $ltd , '' ) ;
				$attributes = $this->mw_unstrip_for_html( $x );
				array_push ( $ltr , Sanitizer::fixTagAttributes ( $attributes, 'tr' ) ) ;
			}
			else if ( '|' == $fc || '!' == $fc || '|+' == substr ( $x , 0 , 2 ) ) { # Caption
				# $x is a table row
				if ( '|+' == substr ( $x , 0 , 2 ) ) {
					$fc = '+' ;
					$x = substr ( $x , 1 ) ;
				}
				$after = substr ( $x , 1 ) ;
				if ( $fc == '!' ) $after = str_replace ( '!!' , '||' , $after ) ;
				$after = explode ( '||' , $after ) ;
				$t[$k] = '' ;

				# Loop through each table cell
				foreach ( $after AS $theline )
				{
					$z = '' ;
					if ( $fc != '+' )
					{
						$tra = array_pop ( $ltr ) ;
						if ( !array_pop ( $tr ) ) $z = '<tr'.$tra.">\n" ;
						array_push ( $tr , true ) ;
						array_push ( $ltr , '' ) ;
					}

					$l = array_pop ( $ltd ) ;
					if ( array_pop ( $td ) ) $z = '</'.$l.'>' . $z ;
					if ( $fc == '|' ) $l = 'td' ;
					else if ( $fc == '!' ) $l = 'th' ;
					else if ( $fc == '+' ) $l = 'caption' ;
					else $l = '' ;
					array_push ( $ltd , $l ) ;

					# Cell parameters
					$y = explode ( '|' , $theline , 2 ) ;
					# Note that a '|' inside an invalid link should not
					# be mistaken as delimiting cell parameters
					if ( strpos( $y[0], '[[' ) !== false ) {
						$y = array ($theline);
					}
					if ( count ( $y ) == 1 )
						$y = "{$z}<{$l}>{$y[0]}" ;
					else {
						$attributes = $this->mw_unstrip_for_html( $y[0] );
						$y = "{$z}<{$l}".Sanitizer::fixTagAttributes($attributes, $l).">{$y[1]}" ;
					}
					$t[$k] .= $y ;
					array_push ( $td , true ) ;
				}
			}
		}

		# Closing open td, tr && table
		while ( count ( $td ) > 0 )
		{
			if ( array_pop ( $td ) ) $t[] = '</td>' ;
			if ( array_pop ( $tr ) ) $t[] = '</tr>' ;
			$t[] = '</table>' ;
		}

		$t = implode ( "\n" , $t ) ;
		return $t ;
	}

	function mw_unstrip_for_html($text)
	{
		$text = @$this->mw_unstrip( $text, $this->mStripState );
		$text = @$this->mw_unstrip_no_wiki( $text, $this->mStripState );
		return $text;
	}

	/**
	 * restores pre, math, and hiero removed by strip()
	 *
	 * always call mw_unstrip_no_wiki() after this one
	 * @access private
	 */
	function mw_unstrip($text, &$state)
	{
		# Must expand in reverse order, otherwise nested tags will be corrupted
		foreach( array_reverse( $state, true ) as $tag => $contentDict ) {
			if( $tag != 'nowiki' && $tag != 'html' ) {
				foreach( array_reverse( $contentDict, true ) as $uniq => $content ) {
					$text = str_replace( $uniq, $content, $text );
				}
			}
		}

		return $text;
	}

	/**
	 * always call this after mw_unstrip() to preserve the order
	 *
	 * @access private
	 */
	function mw_unstrip_no_wiki($text, &$state)
	{
		# Must expand in reverse order, otherwise nested tags will be corrupted
		for ( $content = end($state['nowiki']); $content !== false; $content = prev( $state['nowiki'] ) ) {
			$text = str_replace( key( $state['nowiki'] ), $content, $text );
		}
		
		global $wgRawHtml;
		if ($wgRawHtml) {
			for ( $content = end($state['html']); $content !== false; $content = prev( $state['html'] ) ) {
				$text = str_replace( key( $state['html'] ), $content, $text );
			}
		}

		return $text;
	}

	function format_block_levels($text, $linestart)
	{	
		# Parsing through the text line by line.  The main thing
		# happening here is handling of block-level elements p, pre,
		# and making lists from lines starting with * # : etc.
		#
		$textLines = explode( "\n", $text );

		$lastPrefix = $output = '';
		$this->blockDToopen = $inBlockElem = false;
		$prefixLength = 0;
		$paragraphStack = false;

		if ( !$linestart ) {
			$output .= array_shift( $textLines );
		}
		foreach ( $textLines as $oLine ) {
			$lastPrefixLength = strlen( $lastPrefix );
			$preCloseMatch = preg_match('/<\\/pre/i', $oLine );
			$preOpenMatch = preg_match('/<pre/i', $oLine );
			if ( !$this->blockInPre ) {
				# Multiple prefixes may abut each other for nested lists.
				$prefixLength = strspn( $oLine, '*#:;' );
				$pref = substr( $oLine, 0, $prefixLength );

				# eh?
				$pref2 = str_replace( ';', ':', $pref );
				$t = substr( $oLine, $prefixLength );
				$this->blockInPre = !empty($preOpenMatch);
			} else {
				# Don't interpret any other prefixes in preformatted text
				$prefixLength = 0;
				$pref = $pref2 = '';
				$t = $oLine;
			}

			# List generation
			if( $prefixLength && 0 == strcmp( $lastPrefix, $pref2 ) ) {
				# Same as the last item, so no need to deal with nesting or opening stuff
				$output .= $this->mw_block_next_item( substr( $pref, -1 ) );
				$paragraphStack = false;

				if ( substr( $pref, -1 ) == ';') {
					# The one nasty exception: definition lists work like this:
					# ; title : definition text
					# So we check for : in the remainder text to split up the
					# title and definition, without b0rking links.
					$term = $t2 = '';
					if ($this->mw_block_find_colon_no_links($t, $term, $t2) !== false) {
						$t = $t2;
						$output .= $term . $this->mw_block_next_item( ':' );
					}
				}
			} elseif( $prefixLength || $lastPrefixLength ) {
				# Either open or close a level...
				$commonPrefixLength = $this->mw_block_get_common( $pref, $lastPrefix );
				$paragraphStack = false;

				while( $commonPrefixLength < $lastPrefixLength ) {
					$output .= $this->mw_block_close_list( $lastPrefix{$lastPrefixLength-1} );
					--$lastPrefixLength;
				}
				if ( $prefixLength <= $commonPrefixLength && $commonPrefixLength > 0 ) {
					$output .= $this->mw_block_next_item( $pref{$commonPrefixLength-1} );
				}
				while ( $prefixLength > $commonPrefixLength ) {
					$char = substr( $pref, $commonPrefixLength, 1 );
					$output .= $this->mw_block_open_list( $char );

					if ( ';' == $char ) {
						# FIXME: This is dupe of code above
						if ($this->mw_block_find_colon_no_links($t, $term, $t2) !== false) {
							$t = $t2;
							$output .= $term . $this->mw_block_next_item( ':' );
						}
					}
					++$commonPrefixLength;
				}
				$lastPrefix = $pref2;
			}
			if( 0 == $prefixLength ) {
				# No prefix (not in list)--go to paragraph mode
				// XXX: use a stack for nestable elements like span, table and div
				$openmatch = preg_match('/(<table|<blockquote|<h1|<h2|<h3|<h4|<h5|<h6|<pre|<tr|<p|<ul|<li|<\\/tr|<\\/td|<\\/th)/iS', $t );
				$closematch = preg_match(
					'/(<\\/table|<\\/blockquote|<\\/h1|<\\/h2|<\\/h3|<\\/h4|<\\/h5|<\\/h6|'.
					'<td|<th|<div|<\\/div|<hr|<\\/pre|<\\/p|'. /*$this->blockUniqPrefix.'-pre|*/ '<\\/li|<\\/ul)/iS', $t );
				if ( $openmatch or $closematch ) {
					$paragraphStack = false;
					$output .= $this->mw_block_close_paragraph();
					if ( $preOpenMatch and !$preCloseMatch ) {
						$this->blockInPre = true;
					}
					if ( $closematch ) {
						$inBlockElem = false;
					} else {
						$inBlockElem = true;
					}
				} else if ( !$inBlockElem && !$this->blockInPre ) {
					if ( ' ' == $t{0} and ( $this->blockLastSection == 'pre' or trim($t) != '' ) ) {
						// pre
						if ($this->blockLastSection != 'pre') {
							$paragraphStack = false;
							$output .= $this->mw_block_close_paragraph().'<pre>';
							$this->blockLastSection = 'pre';
						}
						$t = substr( $t, 1 );
					} else {
						// paragraph
						if ( '' == trim($t) ) {
							if ( $paragraphStack ) {
								$output .= $paragraphStack.'<br />';
								$paragraphStack = false;
								$this->blockLastSection = 'p';
							} else {
								if ($this->blockLastSection != 'p' ) {
									$output .= $this->mw_block_close_paragraph();
									$this->blockLastSection = '';
									$paragraphStack = '<p>';
								} else {
									$paragraphStack = '</p><p>';
								}
							}
						} else {
							if ( $paragraphStack ) {
								$output .= $paragraphStack;
								$paragraphStack = false;
								$this->blockLastSection = 'p';
							} else if ($this->blockLastSection != 'p') {
								$output .= $this->mw_block_close_paragraph().'<p>';
								$this->blockLastSection = 'p';
							}
						}
					}
				}
			}
			// somewhere above we forget to get out of pre block (bug 785)
			if($preCloseMatch && $this->blockInPre) {
				$this->blockInPre = false;
			}
			if ($paragraphStack === false) {
				$output .= $t."\n";
			}
		}
		while ( $prefixLength ) {
			$output .= $this->mw_block_close_list( $pref2{$prefixLength-1} );
			--$prefixLength;
		}
		if ( '' != $this->blockLastSection ) {
			$output .= '</' . $this->blockLastSection . '>';
			$this->blockLastSection = '';
		}

		return $output;
	}

	function mw_block_close_paragraph()
	{
		$result = '';
		if ( '' != $this->blockLastSection ) {
			$result = '</' . $this->blockLastSection  . ">\n";
		}
		$this->blockInPre = false;
		$this->blockLastSection = '';
		return $result;
	}

	function mw_block_get_common( $st1, $st2 )
	{
		$fl = strlen( $st1 );
		$shorter = strlen( $st2 );
		if ( $fl < $shorter ) { $shorter = $fl; }

		for ( $i = 0; $i < $shorter; ++$i ) {
			if ( $st1{$i} != $st2{$i} ) { break; }
		}
		return $i;
	}

	function mw_block_open_list( $char )
	{
		$result = $this->mw_block_close_paragraph();

		if ( '*' == $char ) { $result .= '<ul><li>'; }
		else if ( '#' == $char ) { $result .= '<ol><li>'; }
		else if ( ':' == $char ) { $result .= '<dl><dd>'; }
		else if ( ';' == $char ) {
			$result .= '<dl><dt>';
			$this->blockDToopen = true;
		}
		else { $result = '<!-- ERR 1 -->'; }
		
		return $result;
	}

	function mw_block_next_item( $char )
	{
		if ( '*' == $char || '#' == $char ) { return '</li><li>'; }
		else if ( ':' == $char || ';' == $char ) {
			$close = '</dd>';
			if ( $this->blockDToopen ) { $close = '</dt>'; }
			if ( ';' == $char ) {
				$this->blockDToopen = true;
				return $close . '<dt>';
			} else {
				$this->blockDToopen = false;
				return $close . '<dd>';
			}
		}
		return '<!-- ERR 2 -->';
	}

	function mw_block_close_list( $char )
	{
		if ( '*' == $char ) { $text = '</li></ul>'; }
		else if ( '#' == $char ) { $text = '</li></ol>'; }
		else if ( ':' == $char ) {
			if ( $this->blockDToopen ) {
				$this->blockDToopen = false;
				$text = '</dt></dl>';
			} else {
				$text = '</dd></dl>';
			}
		}
		else {	return '<!-- ERR 3 -->'; }
		return $text."\n";
	}

	function mw_block_find_colon_no_links($str, &$before, &$after)
	{
		$pos = 0;
		do {
			$colon = strpos($str, ':', $pos);
				if ($colon !== false) {
				$before = substr($str, 0, $colon);
				$after = substr($str, $colon + 1);

				# Skip any ':' within <a> or <span> pairs
				$a = substr_count($before, '<a');
				$s = substr_count($before, '<span');
				$ca = substr_count($before, '</a>');
				$cs = substr_count($before, '</span>');

				if ($a <= $ca and $s <= $cs) {
					# Tags are balanced before ':'; ok
					break;
				}
				$pos = $colon + 1;
			}
		} while ($colon !== false);
		return $colon;
	}
}

?>
