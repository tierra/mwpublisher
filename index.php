<?php

/** \file Interface for using MediaWiki Publisher.
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

require_once "config.php";
require_once "general.php";

?>

<html>
<head>

<title>MediaWiki Publisher</title>

<style type="text/css">
body {
	margin: 10px 10px 30px 10px;
}
body, p, td, h1, h2 {
	font-family: Verdana, Arial, Helvetica, sans-serif;
}
body, p, td {
	font-size: 90%;
}
tr.offset {
	background: #ddd;
}
td {
	padding: 2px 10px 2px 10px;
	background: none;
}
h1 {
	margin: 20px;
	font-size: 130%;
	text-align: center;
}
h2 {
	margin: 10px;
	font-size: 100%;
}
p {
	margin: 4px 4px 4px 20px;
}
.mwp_notice, .mwp_warning, .mwp_error, .mwp_debug {
}
.mwp_notice {
}
.mwp_warning {
	color: #ff8000;
}
.mwp_error {
	color: #f00;
}
.mwp_debug {
	color: #888;
}
</style>

</head>
<body>

<h1>MediaWiki Publisher</h1>

<?php

if(!isset($_POST['publish']))
{
?>	<div style="margin: 0 auto; width: 300;">
		<form method="post" action="<?= $_SERVER['PHP_SELF'] ?>">
		<p>Select parsers to run:</p>
		<p><select name="parsers[]" size="8" multiple style="width: 100%;">
<?php
	foreach($mwpInstalledParsers as $parser)
		echo "\t\t\t<option value=\"{$parser->id}\">{$parser->name}</option>\n";
?>		</select></p>
		<p style="text-align: center; padding: 3px;"><input type="submit" name="publish" value="Publish"/></p>
		</form>
	</div>
<?php
}
else
{
	foreach($mwpInstalledParsers as $parser)
	{
		if(isset($_POST['parsers']) && in_array($parser->get_id(), $_POST['parsers']))
		{
			echo "<h2>Running " . $parser->get_name() . " Parser...</h2>\n\n";
			$parser->parse();
		}
	}
}

?>

</body>
</html>
