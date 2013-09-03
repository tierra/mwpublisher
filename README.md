# MediaWiki Publisher

**This project has been retired, use at your own risk!**

MediaWiki Publisher (MWP) is a simple, easy to use tool for automating the task
of publishing documentation or information maintained by multiple authors
inside any installation of [MediaWiki](http://www.mediawiki.org/). It can be
used to export full articles (including images and link information) to
multiple markup formats, and is released under the GNU GPL v2.

Currently, the only supported formats are browsable XHTML, and
[HelpBlocks](http://www.helpblocks.com/) project files (for generating Windows
CHM and cross-platform help formats), however, MWP sports a full API (written
in PHP) for writing up additional formats in as little as 100 lines of code
depending on how much customization is needed.

## Getting Started

MWP requires at least a minimal knowledge of PHP even for simple use. So if you
aren't familiar with the language, it's recommended that you start yourself off
on a few PHP tutorials. If you just plan on using existing MWP parsers, you
will only need to touch the
[config.php](https://github.com/tierra/mwpublisher/raw/master/config.php.dist)
file. Writing your own parser does require a good knowledge of how abstract
classes work in PHP and how to override base class functionality.

You may want to check that you meet the minimum requirements before downloading
MWP.

## Introduction

MediaWiki Publisher (MWP) is a PHP script written for the purpose of exporting
articles from [MediaWiki](http://www.mediawiki.org/) into other various output
formats.  Output formats can come in many different flavors from browsable,
static XHTML-only files (for both offline and online viewing) to file formats
built for use inside other publication tools. The whole process can be
automated to generate up-to-date 'builds' of the information being published.

MWP uses the already built-in export and image information features of
MediaWiki, so there's no dependancy on access to the database that powers the
target wiki.  This means that MWP can be setup to publish articles from any
publically (or network) accessible MediaWiki installation without the need for
account information.

MWP does more than just serve as a repository of MediaWiki parsers.  It
contains a full API for writing additional custom parsers that is easy to use
with some basic knowledge of programming with PHP.  Don't be let down if your
desired output format is not listed as a supported output format.

## Requirements

As a PHP script, MediaWiki Publisher needs to be installed on a webserver with
PHP installed. Running MWP from CLI PHP is not supported at this time.

General Requirements:

* PHP 4.3.0+ (PHP 5 is also supported)
* Target Wiki: MediaWiki 1.5+ (tested through 1.8)

MediaWiki 1.6 requires PHP 4.3.2, and 1.7+ requires PHP 5, so if you are
installing MWP on the same server as MediaWiki (recommended for best
performance), your requirements should already be met.

## Supported Output Formats

This is a list of all official output formats.  Additional custom formats are
easy to write, so don't be discouraged if your desired format is not listed.

* **HelpBlocks Parser** - Outputs pages with links formatted for use within
  [HelpBlocks](http://www.helpblocks.com/) making generation of Windows CHM
  and cross-platform HTB formats quick and painless.
* **XHTML Parser** - Outputs standard XHTML 1.0 pages which can be used for
  both offline and online viewing outside of MediaWiki.

## License

Copyright © 2006 Bryan Petty et al [MediaWiki Publisher](http://mwpublisher.org/)

Portions of mwpGenericParser and the Sanitizer classes are Copyright ©
2002-2005 Brion Vibber.  Please see individual files for additional copyright
and license information.

MediaWiki Publisher is free software; you can redistribute it and/or modify it
under the terms of version 2 of the GNU General Public License as published by
the Free Software Foundation. A copy of the license is included in the LICENSE
file.

MediaWiki Publisher is distributed in the hope that it will be useful, but
WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
FITNESS FOR A PARTICULAR PURPOSE.  See the GNU General Public License for more
details.
