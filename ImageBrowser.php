<?php

# Copyright (c) 2008-2014 Yorick Phoenix, All Rights Reserved.
# yp+github@Scribblings.com
# about.me/yorickphoenix

# One file Simple databaseless image browser.
# ===========================================
#
# Use: Stick it in the root level of a hierarcy of photos and call it up.
#
# If an image file has a thumbnail in it then extract it and display it.
# If it doesn't then generate a thumbnail and cache it.
# By forcing the display of the image with a <img> tag to go via this file we can
# protect the directories that the image files are in, scale them, watermark them,
# restrict them accordingly.
# 
# http:.../ImageBrowser.php/?p=<sub_directory_path> - display thumbnails in directory
#
# Internally it uses: 
#
# http:.../?p=<sub_directory_path>&i=<image_file>    - display image thumbnail
# http:.../?p=<sub_directory_path>&i=<image_file>&f  - display image preview page.
# http:.../?p=<sub_directory_path>&i=<image_file>&fi - display image preview (callback from <img> tag).
#
# optional arg: &noexif - will cause all EXIF thumbnails to be ignored.
#
# To avoid the display of the PHP filename you can map index.php to it in your .htaccess file.

# ---

# To remove all the generated image thumbs
# sudo find . -name ".thumbs" -exec rm -r -v \{\} \;

/**
 * Various web servers mangle parts of a URL when passed as part of a GET argument
 * These two functions work around the mangling of '&', '#', '/' & '%'.
 * The PHP Encode functions don't handle these cases
 *
 * @param String $sArg - string to be encoded
 *
 * @return String - encoded string
 */
 
function EncodeArg($sArg)
{
	$sArg = rawurlencode($sArg);
	$sArg = str_replace('%2F', '/', $sArg);
	$sArg = str_replace('*2', '*12', $sArg);
	$sArg = str_replace('*3', '*13', $sArg);
	$sArg = str_replace('%26', '&', $sArg);
	$sArg = str_replace('&', '*2', $sArg);
	$sArg = str_replace('%23', '#', $sArg);
	$sArg = str_replace('#', '*3', $sArg);
	
	return ($sArg);
}

/**
 * Opersite of EncodeArg
 *
 * @param String - encoded string
 *
 * @return String - decoded string
 */

function DecodeArg($sArg)
{
	if (get_magic_quotes_gpc())
	{
		$sArg = stripslashes($sArg);
	}

	$sArg = str_replace('*2', '&', $sArg);
	$sArg = str_replace('*3', '#', $sArg);
	$sArg = str_replace('*12', '*2', $sArg);
	$sArg = str_replace('*13', '*3', $sArg);
	
	return ($sArg);
}

 /**
  * Given a path, populate the $aDirs array with sub-directories
  * Populate the $aFiles array with image files
  * Return the Open directory handle.
  */

function ReadDirectory($sPath, &$aDirs, &$aFiles)
{
	$dirH = opendir($sPath);
	
	if ($dirH != FALSE)
	{
		while (($sFile = readdir($dirH)) != false)
		{
			if ($sFile[0] == '.')
			{
				continue;
			}
	
			// Skip common Mac File System hidden Folders

			if ($sFile == 'TheFindByContentFolder' || $sFile == 'TheVolumeSettingsFolder')
			{
				continue;
			}
	
			$sFullPath = $sPath . '/' . $sFile;
		
			if (is_readable($sFullPath))
			{
				if (is_dir($sFullPath))
				{
					$aDirs[] = $sFile;
				}
				else
				{
					if (@exif_imagetype($sFullPath) == FALSE)
					{
						continue;
					}
			
					$aFiles[] = $sFile;
				}
			}
		}
	}
	
	return ($dirH);
}

/**
 * Simple HTML with inline CSS for the header.
 */

function DisplayHTMLPageHeader()
{
	echo '<html><head><style type="text/css">
body { background-color: black; }
table { border: none; width: 100%; }
td { text-align: center; vertical-align: bottom; }
img { margin: 5px; }
.ttxt {
  font-family: tahoma,sans-serif;
  font-size: 12px;
  color: #999;
}
.dtxt {
  font-family: tahoma,sans-serif;
  font-size: 13px;
}
.dtxt:visited, .dtxt:link { color: #000; text-decoration: none; }
.dtxt:hover { color: #00f; text-decoration: underline }
#pathHeading { background-color: white; text-align: center; vertical-align: top; padding: 3px; border: 2px solid black; }
#ximg img:visited, #ximg img:link, #ximg img { padding: 1px; border: 2px solid #000000; }
#ximg img:hover { padding: 1px; border: 2px solid #2100ff; }
#folders { background-color: #fff; padding-left: 5px; padding-top: 5px; padding-bottom: 5px; float: left; width: 175px; margin-top: 2; }
#pics { padding-left: 2em; margin-top: 0; padding-top: 0; padding-left: 190px; }
</style></head><body>';
}

/**
 * End of page footer
 */

function DisplayHTMLPageFooter()
{
	echo '</body></html>';
}

/**
 * Function to remove $upLevels levels of directories from a URL.
 */

function StripDirs($upLevels, $URL)
{
	$paths = explode('/', $URL);

	$newpath = '';
	
	for ($idx = 0, $size = count($paths); $idx < ($size - ($upLevels)); $idx++)
	{
		if ($idx != 0)
		{
			$newpath .= '/';
		}	

		$newpath .= $paths[$idx];
	}
	
	return ($newpath);
}

/**
 * Calculates the width and height of a thumbnail image based on
 * the original images dimensions and the maximum size you want
 * the largest dimension of the thumbnail to be.
 * Existing dimensions are passed in by reference and updated.
 */

function CalcThumbSize($thumb_width, &$new_width, &$new_height)
{
	$max_dim = max($new_width, $new_height);
	
	$scale = (float) $max_dim / $thumb_width;
	
	if ($new_width == $max_dim)
	{
		$new_width = $thumb_width;
		if ($new_height == $max_dim)
		{
			$new_height = $thumb_width;
		}
		else
		{
			$new_height = round($new_height / $scale);
		}
	}
	else
	{
		$new_height = $thumb_width;
		if ($new_width == $max_dim)
		{
			$new_width = $thumb_width;
		}
		else
		{
			$new_width = round($new_width / $scale);
		}
	}
}

/**
 * Display an image preview page. Entire HTML / CSS for the page is generated here.
 * Generated URL of the <IMG> is of the format: <http://..../?p=<sub_directory_path>&i=<FileName>&fi
 * <IMG> is restricted by a height="nnn" or width="nnn" depending on the orientation of the image
 * The &fi is picked up when this code is re-entered to process the <IMG> request to send the image data.
 */

function DisplayImage($sSubPath, $sFile, $sImgPath)
{
	$sURLPath = '';

	if ($sSubPath != '')
	{
		$sURLPath = EncodeArg($sSubPath);
	}

	$sURLFile = EncodeArg($sFile);

	$sURL = '';

	if ($sURLPath != '')
	{
		$sURL = '?p=' . $sURLPath;
	}

	$sURL .= $sURL != '' ? '&' : '?';
	
	$sURL .= 'i=' . $sURLFile . '&fi';
	
	$width = $height = 0;
	
	list($width, $height, $image_type) = @getimagesize($sImgPath);

	$iWidth  = min($width, 800);
	$iHeight = min($height, 800);
	
	$sOrient = $width < $height ? 'height="'.$iHeight.'"' : 'width="'.$iWidth.'"';

echo '<html><head>
<title>' . $sURLFile . '</title>
<style type="text/css">
<!--
img {
	border: 10px solid white;
	padding: 1px;
	margin: 20px;
}
body { background-color: black; }
-->
</style>
</head>
<body>
<center><img src="' . $sURL . '" ' . $sOrient . '></center>
</body>
</html>
';
}

/**
 * Resize an existing image in file $img to $thumb_width and save in $newfilename
 * Returns the newly created image
 */

function image_resize($img, $thumb_width, $newfilename) 
{
# 	$max_width = $thumb_width;

	//Check if GD extension is loaded
	if (!extension_loaded('gd') && !extension_loaded('gd2')) 
	{
		trigger_error("GD is not loaded", E_USER_WARNING);

		return false;
	}
	
	//Get Image size info
	list($orig_width, $orig_height, $image_type) = getimagesize($img);

	switch ($image_type) 
	{
		case IMAGETYPE_GIF:
			$im = imagecreatefromgif($img);
			break;

		case IMAGETYPE_JPEG:
			$im = imagecreatefromjpeg($img);
			break;
			
		case IMAGETYPE_PNG:
			$im = imagecreatefrompng($img);
			break;
			
		case IMAGETYPE_TIFF_II:
		case IMAGETYPE_TIFF_MM:
		default:
			$im = FALSE;
			/* trigger_error('Unsupported filetype!', E_USER_WARNING); */
			break;
	}
	
	$new_width  = $orig_width;
	$new_height = $orig_height;
	
	CalcThumbSize($thumb_width, $new_width, $new_height);
	
	$newImg = imagecreatetruecolor($new_width, $new_height);
	
	/* Check if this image is PNG or GIF, then set if Transparent*/ 

	if (($image_type == IMAGETYPE_GIF) || ($image_type==IMAGETYPE_PNG))
	{
		imagealphablending($newImg, false);
		imagesavealpha($newImg,true);
		$transparent = imagecolorallocatealpha($newImg, 255, 255, 255, 127);
		imagefilledrectangle($newImg, 0, 0, $new_width, $new_height, $transparent);
	}

	if ($im != FALSE)
	{
		# imagecopyresampled($newImg, $im, 0, 0, 0, 0, $new_width, $new_height, $orig_width, $orig_height);
		
		imagecopyresized($newImg, $im, 0, 0, 0, 0, $new_width, $new_height, $orig_width, $orig_height);
		
		//Generate the file, and rename it to $newfilename

		switch ($image_type) 
		{
			case IMAGETYPE_GIF:
				imagegif($newImg, $newfilename);
				break;
				
			case IMAGETYPE_JPEG:
				imagejpeg($newImg, $newfilename);
				break;
				
			case IMAGETYPE_PNG:
				imagepng($newImg, $newfilename);
				break;

			case IMAGETYPE_TIFF_II:
			case IMAGETYPE_TIFF_MM:
			default:
				/* trigger_error('Failed resize image!', E_USER_WARNING); */
				break;
		}
	}
	
	return $newImg;
}

/**
 * Read a image file from disk, send the appropiate HTTP header and file contents, receiving Browser
 * will display the image.
 */

function DisplayFullImage($sPath)
{
	$type = exif_imagetype($sPath);

	if ($type == FALSE)
	{
		$type = IMAGETYPE_JPEG;
	}
	
	header('Content-Type: ' . image_type_to_mime_type($type));
    header('Content-Transfer-Encoding: binary');

	ob_start();
	header('Content-Length: '.readfile($sPath));
	ob_end_flush();
	flush();
}

/**
 * Callback from an <img> tag to display a thumbnail image.
 * Thumbnails are used in the following order:
 *	[1] Cached
 *	[2] exif thumbnail
 *	[3] created and cached.
 *
 * $bSkipEXif=true skips the check for an EXIF thumbnail forcing one to be created in the cache directory
 * $bJustCheck=true tests to see if it exists (creating if needed)
 */
 
function DisplayThumbImage($sPath, $bJustCheck=false, $bSkipExif=false)
{
	$sThumbDir = GetThumbDir();

	$width = $height = 0;
	
	$sThumb = GetThumbPath($sPath);
	
	$img = FALSE;
	$exif_img = FALSE;
	$type = FALSE;

	if (!file_exists($sThumb))
	{
		if (!$bSkipExif)
		{
			$exif_img = @exif_thumbnail($sPath, $width, $height, $type);
		}
		
		if ($exif_img == FALSE)
		{
			CreateThumbDir($sPath);

			$img = CreateThumb($sPath);
		}
	}

	if (!$bJustCheck)
	{
		if ($type == FALSE)
		{
			$type = exif_imagetype($sPath);
		}

		if ($type == FALSE)
		{
			$type = IMAGETYPE_JPEG;
		}
		
		header('Content-Type: ' . image_type_to_mime_type($type));

		if ($exif_img != FALSE)
		{
			echo $exif_img;
		}
		else
		if ($img != FALSE)
		{
			switch ($type) 
			{
				case IMAGETYPE_GIF:
					imagegif($img);
					break;
					
				case IMAGETYPE_JPEG:
					imagejpeg($img);
					break;
					
				case IMAGETYPE_PNG:
					imagepng($img);
					break;

				case IMAGETYPE_TIFF_II:
				case IMAGETYPE_TIFF_MM:
				default:
					/* trigger_error('Failed resize image!', E_USER_WARNING); */
					break;
			}			
		}
		else
		if (file_exists($sThumb))
		{
			readfile($sThumb);
		}
	}
}

/*
 * Directory where cached thumb images reside (this is relavtive to the directory the image is in)
 */

function GetThumbDir()
{
	return ('/.thumbs');
}

function GetThumbWidth()
{
	return (160);
}

/**
 * Given a path /a/b/c/d/e.ext returns /a/b/c/d/.thumbs/e.ext
 */

function GetThumbPath($sPath)
{
	$sThumbDir = GetThumbDir();

	$sThumbPath = dirname($sPath) . $sThumbDir . '/' . basename($sPath);

	return ($sThumbPath);
}

function CreateThumbDir($sPath)
{
	$sThumbDir = GetThumbDir();

	if (!file_exists(dirname($sPath) . $sThumbDir))
		@mkdir(dirname($sPath) . $sThumbDir);
}

/**
 * Check to see if a thumbnail image exists, optionally create it.
 *
 * aInfo        - is populated with thumbnail image size and type
 * bCreateThumb - create the thumb if it doesn't exist
 * bSkipExif    - ignore EXIF data
 */

function PreflightImage($sPath, &$aInfo, $bCreateThumb=false, $bSkipExif=false)
{
	// First check for a Thumbnail, use that if it exists
	// If no thumbnail then check for EXIF thumbnail unless we are ignoring them
	
	$sThumbPath = GetThumbPath($sPath);

	if (!file_exists($sThumbPath))
	{
		if (!$bSkipExif)
		{
			if ((@exif_thumbnail($sPath, $width, $height, $type)) != FALSE)
			{
				$aInfo[0] = $width;
				$aInfo[1] = $height;
				$aInfo[2] = $type;
				
				return;
			}
		}

		if ($bCreateThumb)
		{
			// Create the thumb directory if needed
			
			CreateThumbDir($sPath);

			CreateThumb($sPath);
		}		
	}

	@getimagesize($sThumbPath, $aInfo);
}

/*
 * Create a thumbnail image, saving it in the cache directory and returning the image.
 */
 
function CreateThumb($sPath)
{
	$width = GetThumbWidth();
	
	$sThumbPath = GetThumbPath($sPath);

	$img = image_resize($sPath, $width, $sThumbPath);
	
	return ($img);
}

/**
 * Generate the HTML to display a thumbnail so that it can be clicked on to display a preview
 * and a border is displayed when you roll over it.
 *
 * $sPath = full path to directory the file is in [filesystem]
 * $sSubPath = path from root of the photo's directory
 * $sFile = filename
 * $bSkipExif
 */

function DisplayFile($sPath, $sSubPath, $sFile, $bSkipExif=false)
{
	$sFullPath = $sPath . '/' . $sFile;

	$sURLPath = '';

	if ($sSubPath != '')
	{
		$sURLPath = EncodeArg($sSubPath);
	}

	$sURLFile = EncodeArg($sFile);

	$aInfo = array();

	// Get information about the thumbnail, create it if needed

	PreflightImage($sFullPath, $aInfo, TRUE, $bSkipExif);

	echo '<a href="?p=';

	if ($sURLPath != '')
	{
		echo $sURLPath;
	}

	echo '&i=' . $sURLFile . '&f" target="_blank">';

	echo '<span id="ximg"><img src="?p=' . $sURLPath . '&i=' . $sURLFile . '"';
	
	if (@$aInfo[0] != 0)
	{
		echo ' width="' . $aInfo[0] . '"';
	}

	if (@$aInfo[1] != 0)
	{
		echo ' height="' . $aInfo[1] . '"';
	}

	echo '></span>' ;
	echo '</a><br>';

	echo '<span class="ttxt">' . htmlentities($sFile,ENT_QUOTES,'UTF-8') . '</span>';
}

/**
 *
 * ImageBrowser.php - MAIN ENTRY POINT
 *
 */

$sPath = __FILE__;
if ($sPath != '')
{
	$sPathInfo = pathinfo($sPath);
	$sPath = $sPathInfo['dirname'];
}

$sSubPath = @$_GET['p'];

$sSubPath = DecodeArg($sSubPath);

if ($sSubPath != '')
{
	$sPath .= '/' . $sSubPath;
}

$sFlatPath = realpath($sPath);

if ($sFlatPath != FALSE)
{
	$sPath = $sFlatPath;
}

// Display: Thumbnail, HTML for Preview or Preview Image

if (isset($_GET['i']))
{
	$imgFile = $_GET['i'];
	
	$imgFile = DecodeArg($imgFile);

	$imgPath = $sPath . '/' . $imgFile;
	
	if (isset($_GET['f'])) // Display HTML
	{
		DisplayImage($sSubPath, $imgFile, $imgPath);
	}
	else
	if (isset($_GET['fi']))
	{
		DisplayFullImage($imgPath);  // Display Image (callback from <img> tag)
	}
	else
	{
		DisplayThumbImage($imgPath); // Display Thumbnail (callback from <img> tag)
	}
}
else // Display browser of images and directories
{
	DisplayHTMLPageHeader();

	echo '<div id="folders">';
	
	if ($sSubPath != '') // first link to parent
	{
		echo '<a class="dtxt" href="?p=';
		$sURLPath = StripDirs(1, $sSubPath);
		$sURLPath = EncodeArg($sURLPath);
		echo htmlentities($sURLPath);
		echo '">UP</a><br><br>';
	}
	
	$aDirs = array();
	$aFiles = array();

	$dirH = ReadDirectory($sPath, $aDirs, $aFiles);
	
	natcasesort($aDirs); // Sort by Name, what about by date!

	$sURLPath = '';

	if ($sSubPath != '')
	{
		$sURLPath = EncodeArg($sSubPath);
	}
	
	foreach ($aDirs as $sDir)
	{
		$sURLDir = EncodeArg($sDir);

		echo '<a class="dtxt" href="?p=';
		if ($sURLPath != '')
		{
			echo $sURLPath . '/';
		}
		echo $sURLDir .'">';
		echo htmlentities($sDir, ENT_QUOTES, 'UTF-8');
		echo '</a><br>';
	}
	
	echo '</div>';
	
	echo '<div id="pics">';
	echo '<table id="pathHeading">'
		.	'<tr>'
		.		'<td class="dtxt">/'
		. 			htmlentities($sSubPath,ENT_QUOTES,'UTF-8')
		.		'</td>'
		.	'</tr>'
		. '</table><br>';

	echo '<table>';
	
	$cols = 5;

	$bSkipExif=isset($_GET['noexif']);
	
	natcasesort($aFiles);

	$idx = 1;

	foreach ($aFiles as $sFile)
	{
		if ($idx == 1)
		{
			echo '<tr>';
		}
		
		echo '<td>';
			displayFile($sPath, $sSubPath, $sFile, $bSkipExif);
		echo '</td>';
		
		$idx++;
		
		if ($idx > $cols)
		{
			echo '</tr>';
			$idx = 1;
		}
		
		flush(); // each time we complete a row get the HTML out to the browser
	}


	// pad cells not used on last row.

	while ($idx < ($cols - 1))
	{
		if ($idx == 1)
		{
			echo '<tr>';
		}

		echo '<td>&nbsp;</td>';
		$idx++;
	}
	
	echo 	'</tr>'
		. '</table>'
		. '</div>';

	DisplayHTMLPageFooter();	
}
