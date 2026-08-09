<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$width = 1200;
$height = 630;
$paper = [239, 230, 216];
$spot = [240, 196, 0];

$canvas = imagecreatetruecolor($width, $height);
$paperColor = imagecolorallocate($canvas, ...$paper);
imagefilledrectangle($canvas, 0, 0, $width, $height, $paperColor);

$spotColor = imagecolorallocate($canvas, ...$spot);
imagefilledrectangle($canvas, 0, (int) ($height * 0.78), $width, $height, $spotColor);

$mascotPath = $root.'/public/images/marketing/hero/mascot-character.png';
$mascot = imagecreatefrompng($mascotPath);

if ($mascot === false) {
    fwrite(STDERR, "Failed to load mascot PNG\n");
    exit(1);
}

imagesavealpha($mascot, true);
imagealphablending($mascot, true);

$srcW = imagesx($mascot);
$srcH = imagesy($mascot);
$targetH = (int) ($height * 0.72);
$targetW = (int) round($srcW * ($targetH / $srcH));
$destX = (int) (($width - $targetW) / 2);
$destY = (int) (($height * 0.78 - $targetH) / 2) + 10;

imagealphablending($canvas, true);
imagecopyresampled($canvas, $mascot, $destX, $destY, 0, 0, $targetW, $targetH, $srcW, $srcH);

$outPath = $root.'/public/images/marketing/og.jpg';
imagejpeg($canvas, $outPath, 92);

echo "Wrote {$outPath} ({$width}x{$height})\n";
