<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en" lang="en">
<head>
<?php

$title = '{TOPIC}';
$prev_link = '{PREV_PAGE}';
$prev_title = '{PREV_PAGE_TITLE}';
$next_link = '{NEXT_PAGE}';
$next_title = '{NEXT_PAGE_TITLE}';

$nav = '<div class="navigation">';
$nav .= '<table cellspacing="2" cellpadding="0" width="100%"><tr>' . "\n";

if($prev_link != '')
	$nav .= "\t<td><a href=\"$prev_link\" title=\"Previous: $prev_title\"><img alt=\"Previous: " .
		$prev_title . '" border="0" src="icons/previous.gif" width="32" height="32" /></a></td>';
else
	$nav .= "\t".'<td><img alt="" border="0" src="icons/blank.gif" width="32" height="32" /></td>';

$nav .= "\n\t".'<td><a href="contents.php" title="Contents"><img alt="Contents" border="0" ' .
	'src="icons/contents.gif" width="32" height="32" /></a></td>'."\n";

if($next_link != '')
	$nav .= "\t<td><a href=\"$next_link\" title=\"Next: $next_title\"><img alt=\"Next: " .
		$next_title . '" border="0" src="icons/next.gif" width="32" height="32" /></a></td>';
else
	$nav .= "\t".'<td><img alt="" border="0" src="icons/blank.gif" width="32" height="32" /></td>';

$nav .= "\n\t<td class=\"navtitle\" align=\"center\">$title - MediaWiki Publisher User Manual</td>\n";
$nav .= "</tr></table></div>\n";

?>
	<meta content="text/html; charset=utf-8" http-equiv="content-type" />
	<title><?= $title ?> - MediaWiki Publisher User Manual</title>
<?php
if($prev_link != '') echo "\t<link href=\"$prev_link\" title=\"$prev_title\" rel=\"prev\" />\n";
if($next_link != '') echo "\t<link href=\"$next_link\" title=\"$next_title\" rel=\"next\" />\n";
?>
	<style type="text/css">
		body { font-family: Verdana, sans-serif; }
		h1, h2, h3, h4 { font-family: "Trebuchet MS", Verdana, sans-serif; }
		.navigation { clear: both; }
		.navigation td {
			background-color: #669933;
			color: #ffffcc;
			font-weight: bold;
			font-family: "Trebuchet MS", Verdana, sans-serif;
			font-size: 110%;
		}
		.navigation img { border-width: 0px; }
		.navtitle { width: 100%; }
		#content { margin: 15px; }
	</style>
	</head>
<body>

<?= $nav ?>

<div id="content">

