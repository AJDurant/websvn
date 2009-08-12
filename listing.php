<?php
// WebSVN - Subversion repository viewing via the web using PHP
// Copyright (C) 2004-2006 Tim Armes
//
// This program is free software; you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation; either version 2 of the License, or
// (at your option) any later version.
//
// This program is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with this program; if not, write to the Free Software
// Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
//
// --
//
// listing.php
//
// Show the listing for the given repository/path/revision

require_once('include/setup.php');
require_once('include/svnlook.php');
require_once('include/utils.php');
require_once('include/template.php');
require_once('include/bugtraq.php');

function removeURLSeparator($url) {
  return preg_replace('#(\?|&(amp;)?)$#', '', $url);
}

function fileLink($path, $file, $returnjoin = false) {
  global $config, $rep, $passrev, $passRevString, $peg;

  if ($path == '' || $path{0} != '/') {
    $ppath = '/'.$path;
  } else {
    $ppath = $path;
  }

  if ($ppath{strlen($ppath)-1} != '/') {
    $ppath .= '/';
  }

  if ($file{0} == '/') {
    $pfile = substr($file, 1);
  } else {
    $pfile = $file;
  }

  if ($returnjoin) {
    return $ppath.$pfile;
  }

  $isDir = $pfile{strlen($pfile) - 1} == '/';
  if ($isDir) {
    $url = $config->getURL($rep, $ppath.$pfile, 'dir').$passRevString;
    if ($config->treeView) {
      // XHTML doesn't allow slashes in IDs and must begin with a letter
      $id = str_replace('/', '_', 'path'.$ppath.$pfile);
      $url .= '#'.$id.'" id="'.$id;
    }
  } else {
    $url = $config->getURL($rep, $ppath.$pfile, 'file').$passRevString;
  }
  // NOTE: If it's a directory in tree view, this also injects an "id" attribute
  return '<a href="'.removeURLSeparator($url).'">'.$pfile.'</a>';
}

function showDirFiles($svnrep, $subs, $level, $limit, $rev, $listing, $index, $treeview = true) {
  global $config, $lang, $rep, $passrev, $passRevString;

  $path = '';

  if (!$treeview) {
    $level = $limit;
  }

  // TODO: Fix node links to use the path and number of peg revision (if exists)
  // This applies to file detail, log, and RSS -- leave the download link as-is
  for ($n = 0; $n <= $level; $n++) {
    $path .= $subs[$n].'/';
  }

  $logList = $svnrep->getList($path, $rev);

  // List each file in the current directory
  $loop = 0;
  $last_index = 0;
  $accessToThisDir = $rep->hasReadAccess($path, false);
  
  $downloadRevString = ($rev) ? 'rev='.$rev.'&amp;peg='.$rev : '';
  
  $openDir = false;
  foreach ($logList->entries as $entry) {
    $isDir = $entry->isdir;
    if (!$isDir && $level != $limit) {
      continue; // Skip any files outside the current directory
    }
    $file = $entry->file;
    $urlPartIsDir = ($isDir) ? 'isdir=1' : '';
    
    // Only list files/directories that are not designated as off-limits
    $access = ($isDir) ? $rep->hasReadAccess($path.$file, true)
                       : $accessToThisDir;
    if ($access) {
      $listing[$index]['rowparity'] = $index % 2;
      
      if ($isDir) {
        $listing[$index]['filetype'] = ($openDir) ? 'diropen' : 'dir';
        $openDir = isset($subs[$level+1]) && (!strcmp($subs[$level+1].'/', $file) ||
                                              !strcmp($subs[$level+1], $file));
      } else {
        $listing[$index]['filetype'] = strtolower(strrchr($file, '.'));
        $openDir = false;
      }
      $listing[$index]['isDir'] = $isDir;
      $listing[$index]['openDir'] = $openDir;
      $listing[$index]['level'] = ($treeview) ? $level : 0;
      $listing[$index]['node'] = 0; // t-node
      $listing[$index]['filelink'] = fileLink($path, $file);
      $listing[$index]['logurl'] = $config->getURL($rep, $path.$file, 'log').$passRevString.$urlPartIsDir;
      
      if ($treeview) {
        $listing[$index]['compare_box'] = '<input type="checkbox" name="compare[]" value="'.fileLink($path, $file, true).'@'.$passrev.'" onclick="checkCB(this)" />';
      }
      if ($config->showLastMod) {
        $listing[$index]['committime'] = $entry->committime;
        $listing[$index]['revision'] = $entry->rev;
        $listing[$index]['author'] = $entry->author;
        $listing[$index]['age'] = $entry->age;
        $listing[$index]['date'] = $entry->date;
        // Revisions are repository-wide, so don't include path
        $listing[$index]['revurl'] = $config->getURL($rep, '', 'revision').'rev='.$entry->rev;
      }
      if ($rep->isDownloadAllowed($path.$file)) {
        $downloadurl = $config->getURL($rep, $path.$file, 'dl').$downloadRevString;
        if ($isDir) {
          $listing[$index]['downloadurl'] = $downloadurl.'&amp;isdir=1';
          $listing[$index]['downloadplainurl'] = '';
        } else {
          $listing[$index]['downloadplainurl'] = $downloadurl;
          $listing[$index]['downloadurl'] = '';
        }
      } else {
        $listing[$index]['downloadplainurl'] = '';
        $listing[$index]['downloadurl'] = '';
      }
      if ($rep->getHideRss()) {
        $rssurl = $config->getURL($rep, $path.$file, 'rss');
        // RSS should always point to the latest revision, so don't include rev
        $listing[$index]['rssurl'] = $rssurl.$urlPartIsDir;
      }

      $loop++;
      $index++;
      $last_index = $index;

      if ($isDir && ($level != $limit)) {
        if (isset($subs[$level + 1]) && !strcmp(htmlentities($subs[$level + 1],ENT_QUOTES).'/', htmlentities($file))) {
          $listing = showDirFiles($svnrep, $subs, $level + 1, $limit, $rev, $listing, $index);
          $index = count($listing);
        }
      }
    }
  }

  // For an expanded tree, give the last entry an "L" node to close the grouping
  if ($treeview && $last_index != 0) {
    $listing[$last_index - 1]['node'] = 1; // l-node
  }

  return $listing;
}

function showTreeDir($svnrep, $path, $rev, $listing) {
  global $vars, $config;

  $subs = explode('/', $path);

  // For directory, the last element in the subs is empty.
  // For file, the last element in the subs is the file name.
  // Therefore, it is always count($subs) - 2
  $limit = count($subs) - 2;

  for ($n = 0; $n < $limit; $n++) {
    $vars['last_i_node'][$n] = FALSE;
  }
  
  $vars['compare_box'] = ''; // Set blank once in case tree view is not enabled.
  return showDirFiles($svnrep, $subs, 0, $limit, $rev, $listing, 0, $config->treeView);
}

// Make sure that we have a repository
if ($rep) {
$svnrep = new SVNRepository($rep);

// Revision info to pass along chain
$passrev = $rev;
$passRevString = ($passrev) ? 'rev='.$passrev.'&amp;' : '';
if ($peg)
  $passRevString .= 'peg='.$peg.'&amp;';

// If there's no revision info, go to the lastest revision for this path
$history = $svnrep->getLog($path, $passrev, '', false, 2, $peg);
if (is_string($history)) {
  $vars['error'] = $history;
} else {
if (!empty($history->entries[0])) {
  $youngest = $history->entries[0]->rev;
} else {
  $youngest = -1;
}

// Unless otherwise specified, we get the log details of the latest change
if (empty($rev)) {
  $logrev = $youngest;
} else {
  $logrev = $passrev;
}

if ($logrev != $youngest) {
  $logEntry = $svnrep->getLog($path, $logrev, $logrev, false);
  if (is_string($logEntry)) {
    echo $logEntry;
    exit;
  }
  $logEntry = isset($logEntry->entries[0]) ? $logEntry->entries[0] : false;
} else {
  $logEntry = isset($history->entries[0]) ? $history->entries[0] : false;
}

$headlog = $svnrep->getLog('/', '', '', true, 1);
if (is_string($headlog)) {
  echo $headlog;
  exit;
}
$headrev = isset($headlog->entries[0]) ? $headlog->entries[0]->rev : 0;

// If we're not looking at a specific revision, get the HEAD revision number
// (the revision of the rest of the tree display)

if (empty($rev)) {
  $rev = $headrev;
}

if ($path == '' || $path{0} != '/') {
  $ppath = '/'.$path;
} else {
  $ppath = $path;
}

if ($passrev != 0 && $passrev != $headrev && $youngest != -1) {
  $vars['goyoungesturl'] = $config->getURL($rep, $path, 'dir');
} else {
  $vars['goyoungesturl'] = '';
}

$bugtraq = new Bugtraq($rep, $svnrep, $ppath);

$vars['action'] = '';
$vars['rev'] = $rev;
$vars['path'] = htmlentities($ppath, ENT_QUOTES, 'UTF-8');
$vars['lastchangedrev'] = $logrev;
$vars['date'] = $logEntry ? $logEntry->date : '';
$vars['author'] = $logEntry ? $logEntry->author : '';
$vars['log'] = $logEntry ? nl2br($bugtraq->replaceIDs(create_anchors($logEntry->msg))) : '';

$revString = '';
if ($passrev) {
  $revString = 'rev='.$passrev;
  if ($peg && $path != '/')
    $revString .= '&amp;peg='.$peg;
}
$vars['changesurl'] = $config->getURL($rep, $path, 'revision').$revString;
if (sizeof($history->entries) > 1) {
  $vars['compareurl'] = $config->getURL($rep, '', 'comp').'compare[]='.urlencode($history->entries[1]->path).'@'.$history->entries[1]->rev. '&amp;compare[]='.urlencode($history->entries[0]->path).'@'.$history->entries[0]->rev;
}

createDirLinks($rep, $ppath, $passrev, $peg);

$logurl = $config->getURL($rep, $path, 'log');
$vars['logurl'] = $logurl.$passRevString.'isdir=1';

$vars['indexurl'] = $config->getURL($rep, '', 'index');

if ($rep->getHideRss()) {
  $vars['rssurl'] = $config->getURL($rep, $path, 'rss').'isdir=1';
}

// Set up the tarball link

$subs = explode('/', $path);
$level = count($subs) - 2;
if ($rep->isDownloadAllowed($path)) {
  $vars['downloadurl'] = $config->getURL($rep, $path, 'dl').$passRevString.'isdir=1';
}

$url = $config->getURL($rep, '/', 'comp');

$hidden = ($config->multiViews) ? '<input type="hidden" name="op" value="comp" />' : '';
$vars['compare_form'] = '<form action="'.$url.'" method="post">'.$hidden;
$vars['compare_submit'] = '<input type="submit" value="'.$lang['COMPAREPATHS'].'" />';
$vars['compare_endform'] = '</form>';

$vars['showlastmod'] = $config->showLastMod;
$vars['showageinsteadofdate'] = $config->showAgeInsteadOfDate;

$listing = showTreeDir($svnrep, $path, $rev, array());
}
$vars['repurl'] = $config->getURL($rep, '', 'dir');

if (!$rep->hasReadAccess($path, true)) {
  $vars['error'] = $lang['NOACCESS'];
}
$vars['restricted'] = !$rep->hasReadAccess($path, false);
}

if (isset($vars['error'])) {
  $listing = array();
}

$vars['template'] = 'directory';
$template = ($rep) ? $rep->getTemplatePath() : $config->templatePath;
parseTemplate($template.'header.tmpl', $vars, $listing);
parseTemplate($template.'directory.tmpl', $vars, $listing);
parseTemplate($template.'footer.tmpl', $vars, $listing);
