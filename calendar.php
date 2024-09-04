<?php

require_once("lib/Mastodon.php");

$currentLocale = setlocale(LC_TIME, 0);

if( !isset($argv[1]) )
    php_die("Usage: php ".__FILE__." [year] ([month])".PHP_EOL);

if( (int)$argv[1]<1970 ) // we're using unix time, can't go any further in the past
    php_die("Invalid year: ".$argv[1]);

if( isset($argv[2]) )
{
    $selectedMonth = $argv[2]; // 1..12
    if(!(ctype_digit(strval($selectedMonth)))) // note: is_int() and $argv don't cope well
    {
        $date = date_parse($selectedMonth); // try to parse month name "January", "Jan", "01"
        if(empty($date['month']) || $date['month']<0 || $date['month']>13)
            php_die("Invalid month name using LC_TIME '".$currentLocale."': ".$selectedMonth.PHP_EOL);
        $selectedMonth = $date['month'];
    }
}



$selectedYear  = $argv[1]; // 2024

$cal = new TelechatCalendar();

if( isset( $selectedMonth ) )
{
    $cal->setup($selectedYear, $selectedMonth);
    $filename = sprintf("cache/%04d-%02d.png", $selectedYear, $selectedMonth );
    $cal->saveCalendarPng($filename);
}
else
{
    for( $i=1;$i<=12;$i++ )
    {
        $cal->setup($selectedYear, $i);
        $filename = sprintf("cache/%04d-%02d.png", $selectedYear, $i );
        $cal->saveCalendarPng($filename);
    }
    exec("cd cache && find . -maxdepth 1 -name '$selectedYear-*' -print0 | tar -cvzf Calendrier-Téléchat-$selectedYear.tar.gz --null --files-from -");
}


class TelechatCalendar
{

    //private $selectedDate;
    private $selectedYear;
    private $selectedMonth;

    private $month;
    private $prevMonthLastDayNum;

    private $monthLastDate;
    private $monthLastDayNum;
    //private $monthLastDay;


    private $monthFirstDay;
    private $monthFirstDate;
    private $dateOffset;

    //private $gd_font_path = 'GDFONTPATH=/usr/share/fonts/TTF/';
    private $angle = 0;

    private $monthNames = ['Janvrier', 'Fièvrilleux', 'Farce', 'Poivril', 'Mouais', 'Hoin', 'Julaille', 'Loûtre', 'Pestembre', 'Noctob', 'Ovembe', 'Graissambe' ];
    private $dayNames   = ['Lourdi', 'Pardi', 'Morquidi', 'Jourdi', 'Dendrevi', 'Sordi', 'Mitanche']; // 0 (for Sunday) through 6 (for Saturday)
    private $csv_file = "data/saint-objet-bot-2023-11-09.csv";
    private $monthDay = 0;

    private $monthImages = [
        "2024/duramou.png",
        "2024/raymonde-warhol.png",
        "2024/groucha.png",
        "2024/header.png",
        "2024/leguman.png",
        "2024/lola2.png",
        "2024/mikmac2.png",
        "2024/raymonde.png",
        "2024/grouchallo.png",
        "2024/gluons.png",
        "2024/lola.png",
        "2024/mikmac.png",
    ];


    private $img;
    private $cellColor;
    private $oodFillColor;
    private $dayFillColor;
    private $monthColor;
    private $prevMonthColor;
    private $quoteColor;

    private $titleHeight;

    private $cellInnerWidth = 220;
    private $cellPaddingX = 5;
    private $cellWidth;

    private $cellInnerHeight = 220;
    private $cellPaddingY = 5;
    private $cellHeight;


    private $marginX = [140,140];
    private $marginY = [1100,100];

    private $dayNumMarginX = 8;
    private $dayNumMarginY = 32;
    private $dayQuoteMarginX  = 8;
    private $dayQuoteMarginY  = 64;

    private $titleMarginY = 30;

    private $titleFontSize = 64;
    private $wmFontSize = 14;
    private $dayNameFontSize = 30;
    private $dayNumFontSize = 20;
    private $dayQuoteFontSize = 18;

    private $dayNumFont = 'assets/fonts/DejaVuSans.ttf';
    private $dayQuoteFont  = 'assets/fonts/Baby Doll.ttf';
    private $monthNameFont = 'assets/fonts/Baby Doll.ttf';
    private $dayNameFont   = 'assets/fonts/Baby Doll.ttf';
    private $imgTitleFont  = 'assets/fonts/DejaVuSansCondensed-Bold.ttf';


    private $calWidth, $calHeight;
    private $width, $height;


    private $calendar;

    private $debug = false;


    public function __construct()
    {

    }



    public function setup(int $year, int $month)
    {
        $this->selectedYear  = $year; // 2024
        $this->selectedMonth = $month; // 0..13
        $this->monthFirstDate = strtotime("$this->selectedYear-$this->selectedMonth-1");

        // some sanity check

        if(count($this->monthImages) != 12 )
            php_die("Month images count mismatch, expecting 12, got: ".count($this->monthImages).PHP_EOL);
        if( date("Y", $this->monthFirstDate) != $year )
            php_die("Invalid date: $this->selectedYear-$this->selectedMonth-01".PHP_EOL);

        $this->month = date("n", $this->monthFirstDate);

        $this->monthLastDayNum = getLastDayOfMonth($this->month, $this->selectedYear);

        $this->monthLastDate = strtotime("$this->selectedYear-$this->selectedMonth-$this->monthLastDayNum");

        $this->prevMonthLastDayNum = getLastDayOfMonth($this->month - 1, $this->selectedYear);

        $this->monthFirstDay = date("w", $this->monthFirstDate);

        // consequence of using monday as the first day of the week: sunday must be shifted
        if( $this->monthFirstDay==0 ) $this->monthFirstDay=7;

        $this->dateOffset = $this->monthFirstDay;

        //putenv($this->gd_font_path);

        $this->cellWidth  = $this->cellInnerWidth  + $this->cellPaddingX*2;
        $this->cellHeight = $this->cellInnerHeight + $this->cellPaddingY*2;

        $this->calWidth  = 7*$this->cellWidth;
        $this->calHeight = 6*$this->cellHeight;

        $this->width  = $this->calWidth  + $this->marginX[0] + $this->marginX[1];
        $this->height = $this->calHeight + $this->marginY[0] + $this->marginY[1];

        $monthName = $this->monthNames[$this->month-1];

        if( $this->debug )
        {
            echo "Month name: '$monthName' (".date('Y-m', $this->monthFirstDate).")".PHP_EOL;
            echo "$monthName $year has $this->monthLastDayNum days".PHP_EOL;
            echo "Prev month had $this->prevMonthLastDayNum days".PHP_EOL;
            echo "First day of $monthName $year is a ".date('l', $this->monthFirstDate).PHP_EOL;
            echo "Last day of $monthName $year is a ".date('l', $this->monthLastDate).PHP_EOL;
            echo "Canvas dimensions: $this->width x $this->height".PHP_EOL;
        }

    }



    public function saveCalendarPng( string $path )
    {
        $dir = dirname($path);
        if(!is_dir($dir)) {
            mkdir($dir, 0777, true) or php_die("Unable to create dir $dir");
        }
        $this->drawCalendar();
        ob_start();
        imagepng($this->img);
        $png = ob_get_clean();
        imagedestroy($this->img);
        file_put_contents($path, $png) or php_die("Unable to save png at $path");
        echo "Created $path".PHP_EOL;
    }


    public function drawCalendar()
    {
        if( empty( $this->calendar ) )
        {
            $this->calendar = getCSVData($this->csv_file);
        }
        $this->img = imagecreateTrueColor($this->width, $this->height);
        $this->cellColor = imagecolorallocate($this->img, 0xff, 0xff, 0xff);
        $this->oodFillColor = imagecolorallocate($this->img, 0xdd, 0xdd, 0xdd);
        $this->dayFillColor = imagecolorallocate($this->img, 0xf0, 0xf0, 0xf0);
        $this->monthColor = imagecolorallocate($this->img, 0x20, 0x20, 0x20);
        $this->prevMonthColor = imagecolorallocate($this->img, 0x90, 0x90, 0x90);
        $this->quoteColor = imagecolorallocate($this->img, 0x44, 0x44, 0x44 );
        $white = imagecolorallocatealpha($this->img, 0xff, 0xff, 0xff, 64);
        $black = imagecolorallocate($this->img, 0x20, 0x20, 0x20);
        imagefill($this->img, 0, 0, $this->cellColor);
        //imagerectangle($this->img, $this->marginX[0], $this->marginY[0], $this->marginX[0]+$this->calWidth, $this->marginY[0]+$this->calHeight, $black );

        { // top title  (month-name / year)
            $title_box = imagettfbbox($this->titleFontSize, $this->angle, $this->monthNameFont, $this->monthNames[$this->month-1]." ".$this->selectedYear);
            list($title_width, $title_height) = getBoxDimensions($title_box);
            $this->titleHeight = $title_height+$this->titleMarginY*2;
            $title_x = $this->marginX[0]+ceil(($this->calWidth - $title_width) / 2);
            $title_y = $this->titleHeight/2 + $title_height/2;
            //imagerectangle($this->img, $title_x, 0, $title_x+$title_width, $this->titleHeight, $black );
            //imagerectangle($this->img, $title_x, $title_y, $title_x+$title_width, $title_y-$title_height, $black );
            $title = $this->monthNames[$this->month-1]." ".$this->selectedYear;
            imagettftext($this->img, $this->titleFontSize, $this->angle, $title_x, $title_y, $this->monthColor, $this->monthNameFont, $title );
        }


        { // illustration (image of the month)
            $monthImage = $this->monthImages[$this->month-1];
            $bg = imagecreatefrompng("assets/".$monthImage);
            $bg_w = $this->calWidth; //-$this->cellPaddingX;
            $bg_h = ($this->marginY[0] - ($title_height+$this->dayNameFontSize*1.5)) - ($title_y+$this->dayNameFontSize*1.5);
            $bg_x = $this->marginX[0];
            $bg_y = $title_y+$title_height;
            $bg_resized = imageresize_alpha($bg, $bg_w, $bg_h );
            imagecopymerge_alpha($this->img, $bg_resized, $bg_x, $bg_y, 0, 0, $bg_w, $bg_h, 100);
        }

        { // watermark (overlaying image of the month)
            $wmAngle = 90;
            $watermark = "Calendrier Téléchat ".$this->selectedYear;
            $watermark_box = imagettfbbox($this->wmFontSize, $wmAngle, $this->imgTitleFont, $watermark);
            list($w, $h) = getBoxDimensions($watermark_box);
            $x = $this->marginX[0] + $this->calWidth-$w;
            $y = ($bg_y+$bg_h)-($this->wmFontSize);


            imagettfstroketext($this->img, $this->wmFontSize, $wmAngle, $x, $y, $black, $white, $this->imgTitleFont, $watermark, 1);
        }

        { // day names (top of the calendar)
            $y = $this->marginY[0] - $this->dayNameFontSize/2;

            for($i=0; $i<7; ++$i)
            {
                $x_offset = $this->marginX[0] + $this->cellWidth * $i;
                $dayname_box = imagettfbbox($this->dayNameFontSize, $this->angle, $this->dayNameFont, $this->dayNames[$i]);
                list($width, $height) = getBoxDimensions($dayname_box);
                $x = $x_offset + 2 + ceil(($this->cellWidth - $width) / 2); // center text
                imagettftext($this->img, $this->dayNameFontSize, $this->angle, $x, $y, $this->monthColor, $this->dayNameFont, $this->dayNames[$i] );
            }
        }

        // calendar cells
        for($j = 0; $j < 6; ++$j) // foreach weeks of the grid (vertically)
        {
            for($i=1; $i<=7; ++$i) // foreach days of the week (horizontally, starting on monday)
            {
                $this->drawDay($i, $j);
            }
        }
    }


    private function drawDayNumber(int $dayNumber, int $x_offset, int $y_offset, bool $drawQuote )
    {
        $white = imagecolorallocatealpha($this->img, 0xff, 0xff, 0xff, 64);

        $x = $x_offset+$this->dayNumMarginX;
        $y = $y_offset+$this->dayNumMarginY;

        $dayNumColor = $drawQuote ? $this->monthColor : $this->prevMonthColor;
        //imagettftext($this->img, $this->dayNumFontSize, $this->angle, $x, $y, $text_color, $this->dayNumFont, $dayNumber );
        imagettfstroketext($this->img, $this->dayNumFontSize, $this->angle, $x, $y, $dayNumColor, $white, $this->dayNumFont, $dayNumber, 1);

        if( $drawQuote )
        {
            $template = "%s %s";
            $day = strtotime("$this->selectedYear-$this->selectedMonth-$dayNumber");
            $quote_ary = getQuoteData($day, $this->calendar);
            $quote = sprintf( $template, $quote_ary[4]=='f'?'Ste':'St', ucwords( $quote_ary[2] ) );
            $quote = str_replace("-", " ", $quote ); // remove strokes as they mess up with wordwrap
            $quote = trim($quote);
            $max_w = $this->cellInnerWidth-$this->dayQuoteMarginX*2;
            $spacing = 3;
            $line_height = 1.4;

            $l = mb_strlen($quote);
            $qt = $quote;

            // apply word wrap if necessary
            while($l>3)
            {
                $tb = imagettfbbox($this->dayQuoteFontSize, $this->angle, $this->dayQuoteFont, $qt);
                list($width, $height) = getBoxDimensions($tb);
                if( $width<=$max_w ) // if it fits, it sits
                {
                    $quote = $qt;
                    break;
                }
                $l--;
                $qt = mb_wordwrap($quote, $l);
            }

            $x = $x_offset+$this->dayQuoteMarginX;
            $y = $y_offset+$this->dayQuoteMarginY;

            //imagettftext($this->img, $this->dayQuoteFontSize, $this->angle, $x, $y, $text_color, $this->dayQuoteFont, $quote );
            imagettfstroketext($this->img, $this->dayQuoteFontSize, $this->angle, $x, $y, $this->quoteColor, $white, $this->dayQuoteFont, $quote, 1);
        }
    }


    private function drawDay(int $i, int $j)
    {

        $x_offset = ($this->marginX[0] + $this->cellWidth*($i-1))+$this->cellPaddingX;
        $y_offset = ($this->marginY[0] + $this->cellHeight*$j)+$this->cellPaddingY;

        $drawQuote = false;

        if ($j == 0) // First week
        {
            if ($i < $this->monthFirstDay)
            {
                $dayNum   = $this->prevMonthLastDayNum - ($this->dateOffset - $i) + 1;
                $dayBgCol = $this->oodFillColor;
            }
            else if ($i == $this->monthFirstDay)
            {
                $this->monthDay = 1;
                $dayNum   =  date('j', $this->monthFirstDate) + ($this->dateOffset - $i);
                $drawQuote = true;
                $dayBgCol = $this->dayFillColor;
            }
            else
            {
                ++$this->monthDay;
                $dayNum   = $this->monthDay;
                $drawQuote = true;
                $dayBgCol = $this->dayFillColor;
            }
        }
        else if ($this->monthLastDayNum <= $this->monthDay) // Last weeks
        {
            ++$this->monthDay;
            $dayNum   = $this->monthDay - $this->monthLastDayNum;
            $dayBgCol = $this->oodFillColor;
        }
        else // Other weeks
        {
            ++$this->monthDay;
            $dayNum   = $this->monthDay;
            $drawQuote = true;
            $dayBgCol = $this->dayFillColor;
        }

        //imagefilledrectangle($this->img, $x_offset, $y_offset, $x_offset+$this->cellInnerWidth, $y_offset+$this->cellInnerHeight, $dayBgCol );
        imagefilledroundrectangle($this->img, $x_offset, $y_offset, $x_offset+$this->cellInnerWidth, $y_offset+$this->cellInnerHeight, 8, $dayBgCol);
        $this->drawDayNumber($dayNum, $x_offset, $y_offset, $drawQuote );
    }



};



// helper functions


function getBoxDimensions( array $box ) : array
{
    $min_x = min( array($box[0], $box[2], $box[4], $box[6]) );
    $max_x = max( array($box[0], $box[2], $box[4], $box[6]) );
    $min_y = min( array($box[1], $box[3], $box[5], $box[7]) );
    $max_y = max( array($box[1], $box[3], $box[5], $box[7]) );
    $width  = ( $max_x - $min_x );
    $height = ( $max_y - $min_y );
    return [$width, $height];
}



function getLastDayOfMonth( int $month, int $year) : int
{
    switch ($month)
    {
        case 0 : // prevents error when checking for previous month in jan
        case 1 :
        case 3 :
        case 5 :
        case 7 :
        case 8 :
        case 10:
        case 12:
        case 13: // prevents error when checking for next month in december
            return 31;
            break;
        case 4 :
        case 6 :
        case 9 :
        case 11:
            return 30;
            break;
        case 2 :
            if( ( ($year % 4 == 0) && ( $year % 100 != 0) ) || ($year % 400 == 0) )
                return 29;
            else
                return 28;
        break;

    }
    return -1;
}



function imagecopymerge_alpha(GdImage $dst_im, GdImage $src_im, int $dst_x, int $dst_y, int $src_x, int $src_y, int $src_w, int $src_h, int $pct)
{
    // creating a cut resource
    $cut = imagecreatetruecolor($src_w, $src_h);
    // copying relevant section from background to the cut resource
    imagecopy($cut, $dst_im, 0, 0, $dst_x, $dst_y, $src_w, $src_h);
    // copying relevant section from watermark to the cut resource
    imagecopy($cut, $src_im, 0, 0, $src_x, $src_y, $src_w, $src_h);
    // insert cut resource to destination image
    imagecopymerge($dst_im, $cut, $dst_x, $dst_y, 0, 0, $src_w, $src_h, $pct);
}


function imageresize_alpha(GdImage $image, int $newWidth, int $newHeight) : GDImage
{
    $newImg = imagecreatetruecolor($newWidth, $newHeight);
    imagealphablending($newImg, false);
    imagesavealpha($newImg, true);
    $transparent = imagecolorallocatealpha($newImg, 255, 255, 255, 127);
    imagefilledrectangle($newImg, 0, 0, $newWidth, $newHeight, $transparent);
    $src_w = imagesx($image);
    $src_h = imagesy($image);
    imagecopyresampled($newImg, $image, 0, 0, 0, 0, $newWidth, $newHeight, $src_w, $src_h);
    return $newImg;
}

function imagefilledroundrectangle(GdImage &$im, int $x1, int $y1, int $x2, int $y2, float $radius, int $color)
{
    // draw rectangle without corners
    imagefilledrectangle($im, $x1+$radius, $y1, $x2-$radius, $y2, $color);
    imagefilledrectangle($im, $x1, $y1+$radius, $x2, $y2-$radius, $color);
    // draw circled corners
    imagefilledellipse($im, $x1+$radius, $y1+$radius, $radius*2, $radius*2, $color);
    imagefilledellipse($im, $x2-$radius, $y1+$radius, $radius*2, $radius*2, $color);
    imagefilledellipse($im, $x1+$radius, $y2-$radius, $radius*2, $radius*2, $color);
    imagefilledellipse($im, $x2-$radius, $y2-$radius, $radius*2, $radius*2, $color);
}


function imagettfstroketext(GdImage &$image, int $size, int $angle, int $x, int $y, int &$textcolor, int &$strokecolor, string $fontfile, string $text, int $px) : array
{
    for($c1 = ($x-abs($px)); $c1 <= ($x+abs($px)); $c1++)
        for($c2 = ($y-abs($px)); $c2 <= ($y+abs($px)); $c2++)
            $bg = imagettftext($image, $size, $angle, $c1, $c2, $strokecolor, $fontfile, $text);
    return imagettftext($image, $size, $angle, $x, $y, $textcolor, $fontfile, $text);
}


function mb_wordwrap($str, $width = 75, $break = "\n", $cut = false) {
    $lines = explode($break, $str);
    foreach ($lines as &$line) {
        $line = rtrim($line);
        if (mb_strlen($line) <= $width)
            continue;
        $words = explode(' ', $line);
        $line = '';
        $actual = '';
        foreach ($words as $word) {
            if (mb_strlen($actual.$word) <= $width)
                $actual .= $word.' ';
            else {
                if ($actual != '')
                    $line .= rtrim($actual).$break;
                $actual = $word;
                if ($cut) {
                    while (mb_strlen($actual) > $width) {
                        $line .= mb_substr($actual, 0, $width).$break;
                        $actual = mb_substr($actual, $width);
                    }
                }
                $actual .= ' ';
            }
        }
        $line .= trim($actual);
    }
    return implode($break, $lines);
}
