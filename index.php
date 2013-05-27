<?php
/*
 * Androtransfer.com Download Center
 * Copyright (C) 2012   Daniel Bateman
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

require_once 'config.php';
require_once 'markdown.php';

$currentDeveloper = $_GET['developer'];
if(!in_array($currentDeveloper, $users))
    die("Access denied.");
$currentFolder = $_GET['folder'];
if(strpos($currentFolder, '..') !== false)
    die("Access denied.");
$totalPath = null;

$fp = fopen($baseDir."/.counts","r");
$downloadCounts = array();
if ($fp) {
    if (flock($fp, LOCK_SH)) {
        $downloadCounts = json_decode(file_get_contents($baseDir."/.counts"), true);
        flock($fp, LOCK_UN);
    }
    fclose($fp);
}
if(!$downloadCounts)
    $downloadCounts = array();

$fp = fopen($baseDir."/.md5s","r");
$fileMd5s = array();
$md5dsLoaded = false;
if ($fp) {
    if (flock($fp, LOCK_SH)) {
        $fileMd5s = json_decode(file_get_contents($baseDir."/.md5s"), true);
        flock($fp, LOCK_UN);
        if ($fileMd5s)
            $md5sLoaded = true;
    }
    fclose($fp);
}

$fileMTimes = array();

define("FILE_FILTER_FILES", 0x1);
define("FILE_FILTER_DIRS", 0x2);
define("FILE_FILTER_ALL", FILE_FILTER_DIRS | FILE_FILTER_FILES);
function getAllInFolder($folder, $filter=FILE_FILTER_ALL) {
    global $globalBlacklist;
    $handle = opendir($folder);
    $entries = array();
    if ($handle) {
        while (false !== ($entry = readdir($handle))) {
            $entryPath = $folder."/".$entry;
            if ($entry[0] == '.')
                continue;
            if (in_array($entry, $globalBlacklist))
                continue;

            if ((is_dir($entryPath) && $filter & FILE_FILTER_DIRS) ||
                (!is_dir($entryPath) && $filter & FILE_FILTER_FILES)) {
                $entries[] = $entry;
            }
        }
        closedir($handle);
    }
    return $entries;
}

function sizePretty($bytes) {
    if($bytes >= GB)
        return number_format($bytes/GB) . " GB";
    else if($bytes >= MB)
        return number_format($bytes/MB) . " MB";
    else if($bytes >= KB)
        return number_format($bytes/KB) . " KB";
    return number_format($bytes) . " bytes";
}

function md5_file_alt($file) {
    $fileContents = file_get_contents($file);
    return md5($fileContents);
}

if ($currentDeveloper) {
    $devPath = $baseDir."/".$currentDeveloper;
    $subFolders = getAllInFolder($devPath, FILE_FILTER_DIRS);
    sort($subFolders);

    if (!$currentFolder) {
        $currentFolder = '.';
    }

    if ($currentFolder) {
        $folderPath = $devPath."/".$currentFolder;
        $totalPath = $folderPath;
        $files = getAllInFolder($folderPath, FILE_FILTER_FILES);
        $handle = opendir($folderPath);
        $md5s = array();
        if (!empty($files)) {
            $folderReadme = file_get_contents($folderPath."/.readme");

            $blacklist = explode("\n", file_get_contents($folderPath."/.hide"));
            function fileFilterForBlacklist($file) {
                global $blacklist;
                if (in_array($file, $blacklist)) {
                    return false;
                }
                return true;
            }
            $files = array_filter($files, "fileFilterForBlacklist");

            if ($md5sLoaded) {
                $md5Done = false;
                foreach ($files as $file) {
                    $rp = realpath($totalPath . "/" . $file);
                    $resolvedPath = substr($rp, strpos($rp, "public_html")+strlen("public_html/"));
                    $fileMTimes[$resolvedPath] = $mtime = filemtime($rp);
                    if ((time()-$mtime) < 120) {
                        continue;
                    }
                    if (!$md5Done && (!isset($fileMd5s[$resolvedPath]) || trim($fileMd5s[$resolvedPath]) == '')) {
                        $md5 = md5_file($rp);
                        if ($md5 !== false) {
                            $fileMd5s[$resolvedPath] = $md5;
                        } else {
                            $md5 = md5_file_alt($rp);
                            if ($md5 !== false) {
                                $fileMd5s[$resolvedPath] = $md5;
                            }
                        }
                        $md5Done = true;
                    }
                }

                $fp = fopen($baseDir."/.md5s","w");
                if ($fp) {
                    $data = json_encode($fileMd5s);
                    if ($data && flock($fp, LOCK_EX)) {
                        fwrite($fp, $data);
                        flock($fp, LOCK_UN);
                    }
                    fclose($fp);
                }
            }

            $rawMD5s = explode("\n", file_get_contents($folderPath."/.md5"));
            foreach ($rawMD5s as $line) {
                if($line[0] == '#')
                    continue;
                if(trim($line) == '')
                    continue;
                $lineEnd = strpos($line, "#");
                if($lineEnd !== false)
                    $line = substr($line, 0, $lineEnd);
                $split = explode(" ", $line);
                $split = array_filter(array_map("trim", $split));
                $file = array_shift(array_values($split));
                $md5 = end($split);

                $rp = realpath($totalPath . "/" . $file);
                $resolvedPath = substr($rp, strpos($rp, "public_html")+strlen("public_html/"));
                $fileMd5s[$resolvedPath] = $md5;
            }
        }

        function test_date($x, $y) {
            global $totalPath, $fileMTimes;
            $rp = realpath($totalPath . "/" . $x);
            $resolvedPath = substr($rp, strpos($rp, "public_html")+strlen("public_html/"));
            $dateX = $fileMTimes[$resolvedPath];
            $rp = realpath($totalPath . "/" . $y);
            $resolvedPath = substr($rp, strpos($rp, "public_html")+strlen("public_html/"));
            $dateY = $fileMTimes[$resolvedPath];
            if($dateX < $dateY) return 1;
            else if($dateX > $dateY) return -1;
            return 0;
        }
        usort($files, "test_date");
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title><?= $siteName ?></title>
    <link type='text/css' rel='stylesheet' href='style.css'/>
<script type="text/javascript">

  var _gaq = _gaq || [];
  _gaq.push(['_setAccount', 'UA-23907858-2']);
  _gaq.push(['_trackPageview']);

  (function() {
    var ga = document.createElement('script'); ga.type = 'text/javascript'; ga.async = true;
    ga.src = ('https:' == document.location.protocol ? 'https://ssl' : 'http://www') + '.google-analytics.com/ga.js';
    var s = document.getElementsByTagName('script')[0]; s.parentNode.insertBefore(ga, s);
  })();

</script>
</head>
<body>
    <div id='header'>
      <div id="logo"></div>
		<div class="clear"></div>
    </div>
    <div id="advertise">
    <script type="text/javascript"><!--
google_ad_client = "ca-pub-6244853272122205";
/* Top Bar */
google_ad_slot = "6876020546";
google_ad_width = 728;
google_ad_height = 90;
//-->
</script>
<script type="text/javascript"
src="http://pagead2.googlesyndication.com/pagead/show_ads.js">
</script>
    </div>
    <div id="feature">
    <div id="f1"></div>
    <div id="f2"></div>
    <div id="f3">
      <form method="post" id="signin" action="#">
    	<p>
      <label for="username">username:</label>
      <input id="username" name="username" value="" title="username" tabindex="4" type="text">
      </p>
      <p>
        <label for="password">password:</label>
        <input id="password" name="password" value="" title="password" tabindex="5" type="password">
      </p>
      <p class="remember">
        <input id="signin_submit" value="Sign in" tabindex="6" type="submit">
        <input id="remember" name="remember_me" value="1" tabindex="7" type="checkbox">
        <label for="remember">Remember me</label>
      </p>
      <p class="forgot"> <a href="#"id="resend_password_link">Forgot your password?</a> </p>
      <p class="forgot-username"> <a id=forgot_username_link title="If you remember your password, try logging in with your email" href="#">Forgot your username?</a> </p>
    	</form>	
    </div>
    </div>
    <div id='links'>
        <h2>Select a developer</h2>
        <?php foreach($users as $user): ?>
        <li class='dev'><a href='?developer=<?= $user ?>'><?= $user ?></a></li>
        <?php endforeach ?>
        <div style='clear: both'></div>
    </div>

    <div id='page'>
        <?php if($currentDeveloper): ?>
            <div id='sidebar'>
                <div class='block'>
                    <h2><?= htmlspecialchars($currentDeveloper) ?></h2>
                    <ul>
                    <?php foreach($subFolders as $folder): ?>
                        <li class='<?= $currentFolder == $folder ? "active" : "" ?>'><a href='?developer=<?= rawurlencode($currentDeveloper) ?>&amp;folder=<?= rawurlencode($folder) ?>'><?= $folder ?></a><li>
                    <?php endforeach ?>
                    </ul>
                </div>
            </div>

            <?php if($currentFolder): ?>
                <div style='float: left; margin-left: 10px; width: 748px'>
                    <div class='block'>
                        <h2><?= htmlspecialchars($currentFolder) ?></h2>
                        <?php if (count($files) > 0): ?>
                            <table>
                                    <tr>
                                        <th align='left'>File</th>
                                        <th align='left' width='120px' style='padding-right: 50px'>Last Mod.</th>
                                        <th align='left' width='80px'>Size</th>
                                        <th align='right' width='80px'>Downloads</th>
                                    </tr>
                                    <?php foreach($files as $file): ?>
                                        <?php
                                        $rp = realpath($totalPath . "/" . $file);
                                        $resolvedPath = substr($rp, strpos($rp, "public_html")+strlen("public_html/"));
                                        $filePath = $baseDir . "/" . $resolvedPath;
                                        ?>
                                        <tr class='download'>
                                            <td>
                                                <div class='name'><a style='display: block' href='get.php?p=<?= $resolvedPath ?>'><?= $file ?></a></div>
                                                <?php if(isset($fileMd5s[$resolvedPath])): ?>
                                                    <span class='info'><strong>MD5:</strong> <span style='font-family: Courier'><?= $fileMd5s[$resolvedPath] ?></span></span>
                                                <?php endif ?>
                                            </td>
                                            <td><?= date("F dS Y", $fileMTimes[$resolvedPath]) ?></td>
                                            <td>
                                                <?= sizePretty(filesize($filePath)) ?>
                                            </td>
                                            <td style='font-size: 24px; text-align: right;'>
                                                <?= number_format(isset($downloadCounts[$resolvedPath]) ? $downloadCounts[$resolvedPath] : 0) ?>
                                            </td>
                                        </tr>
                                    <?php endforeach ?>
                            </table>
                        <?php else: ?>
                            No files here.
                        <?php endif ?>
                    </div>

                    <?php if ($folderReadme): ?>
                        <div class='block'>
                            <h2>.readme</h2>
                            <div class='readme'>
                                <?= Markdown($folderReadme) ?>
                            </div>
                        </div>
                    <?php endif ?>
                </div>
            <?php endif ?>
        <?php else: ?>
            <div id='content'>
            </div>
        <?php endif ?>
        <div style='clear: both'></div>
    </div>
<div style="text-align:center; width:100%; padding:20px 0px; margin:0px auto;"><a href="http://www.bytemark.co.uk/r/androtransfer"><img src="/images/bytemark_mono.png" style="height:40px; width:auto;" />
</a></div>
</body>
</html>
