<?php
/*******************************************************************************
* FPDF                                                                         *
* Version: 1.86                                                                *
* License: Freeware                                                            *
*******************************************************************************/

define('FPDF_VERSION', '1.86');

class FPDF
{
    /** @var int */
    protected $page;
    /** @var int */
    protected $n;
    /** @var array */
    protected $offsets;
    /** @var string */
    protected $buffer;
    /** @var array */
    protected $pages;
    /** @var int */
    protected $state;
    /** @var bool */
    protected $compress;
    /** @var float */
    protected $k;
    /** @var string */
    protected $DefOrientation;
    /** @var string */
    protected $CurOrientation;
    /** @var array */
    protected $StdPageSizes;
    /** @var array */
    protected $DefPageSize;
    /** @var array */
    protected $CurPageSize;
    /** @var int */
    protected $CurRotation;
    /** @var array */
    protected $PageInfo;
    /** @var float */
    protected $wPt;
    /** @var float */
    protected $hPt;
    /** @var float */
    protected $w;
    /** @var float */
    protected $h;
    /** @var float */
    protected $lMargin;
    /** @var float */
    protected $tMargin;
    /** @var float */
    protected $rMargin;
    /** @var float */
    protected $bMargin;
    /** @var float */
    protected $cMargin;
    /** @var float */
    protected $x;
    /** @var float */
    protected $y;
    /** @var float */
    protected $lasth;
    /** @var float */
    protected $LineWidth;
    /** @var string */
    protected $fontpath;
    /** @var array */
    protected $CoreFonts;
    /** @var array */
    protected $fonts;
    /** @var array */
    protected $FontFiles;
    /** @var array */
    protected $encodings;
    /** @var array */
    protected $cmaps;
    /** @var string */
    protected $FontFamily;
    /** @var string */
    protected $FontStyle;
    /** @var bool */
    protected $underline;
    /** @var array */
    protected $CurrentFont;
    /** @var float */
    protected $FontSizePt;
    /** @var float */
    protected $FontSize;
    /** @var string */
    protected $DrawColor;
    /** @var string */
    protected $FillColor;
    /** @var string */
    protected $TextColor;
    /** @var bool */
    protected $ColorFlag;
    /** @var bool */
    protected $WithAlpha;
    /** @var float */
    protected $ws;
    /** @var array */
    protected $images;
    /** @var array */
    protected $PageLinks;
    /** @var array */
    protected $links;
    /** @var bool */
    protected $AutoPageBreak;
    /** @var float */
    protected $PageBreakTrigger;
    /** @var bool */
    protected $InHeader;
    /** @var bool */
    protected $InFooter;
    /** @var string */
    protected $AliasNbPages;
    /** @var string */
    protected $ZoomMode;
    /** @var string */
    protected $LayoutMode;
    /** @var array */
    protected $metadata;
    /** @var string */
    protected $PDFVersion;

    /**
     * @param string $orientation
     * @param string $unit
     * @param string|array $size
     */
    function __construct($orientation = 'P', $unit = 'mm', $size = 'A4')
    {
        $this->_dochecks();
        $this->state = 0;
        $this->page = 0;
        $this->n = 2;
        $this->buffer = '';
        $this->pages = array();
        $this->PageInfo = array();
        $this->fonts = array();
        $this->FontFiles = array();
        $this->encodings = array();
        $this->cmaps = array();
        $this->images = array();
        $this->links = array();
        $this->PageLinks = array();
        $this->metadata = array();
        $this->InHeader = false;
        $this->InFooter = false;
        $this->AliasNbPages = '{nb}';
        $this->FontFamily = '';
        $this->FontStyle = '';
        $this->FontSizePt = 12;
        $this->underline = false;
        $this->DrawColor = '0 G';
        $this->FillColor = '0 g';
        $this->TextColor = '0 g';
        $this->ColorFlag = false;
        $this->WithAlpha = false;
        $this->ws = 0;
        $this->lasth = 0;
        $this->CoreFonts = array('courier', 'helvetica', 'times', 'symbol', 'zapfdingbats');

        if ($unit == 'pt') $this->k = 1;
        elseif ($unit == 'mm') $this->k = 72 / 25.4;
        elseif ($unit == 'cm') $this->k = 72 / 2.54;
        elseif ($unit == 'in') $this->k = 72;
        else $this->Error('Incorrect unit: ' . $unit);

        $this->StdPageSizes = array(
            'a3' => array(841.89, 1190.55), 'a4' => array(595.28, 841.89), 'a5' => array(420.94, 595.28),
            'letter' => array(612, 792), 'legal' => array(612, 1008)
        );
        $size = $this->_getpagesize($size);
        $this->DefPageSize = $size;
        $this->CurPageSize = $size;

        $orientation = strtolower($orientation);
        if ($orientation == 'p' || $orientation == 'portrait') {
            $this->DefOrientation = 'P';
            $this->w = $size[0];
            $this->h = $size[1];
        } elseif ($orientation == 'l' || $orientation == 'landscape') {
            $this->DefOrientation = 'L';
            $this->w = $size[1];
            $this->h = $size[0];
        } else $this->Error('Incorrect orientation: ' . $orientation);

        $this->CurOrientation = $this->DefOrientation;
        $this->wPt = $this->w * $this->k;
        $this->hPt = $this->h * $this->k;

        $this->CurRotation = 0;
        $margin = 28.35 / $this->k;
        $this->SetMargins($margin, $margin);
        $this->cMargin = $margin / 10;
        $this->LineWidth = .567 / $this->k;
        $this->SetAutoPageBreak(true, 2 * $margin);
        $this->SetDisplayMode('default');
        $this->SetCompression(true);
        $this->PDFVersion = '1.3';
        $this->images = array();
    }

    /**
     * @param float $left
     * @param float $top
     * @param float|null $right
     */
    function SetMargins($left, $top, $right = null)
    {
        $this->lMargin = $left;
        $this->tMargin = $top;
        if ($right === null) $right = $left;
        $this->rMargin = $right;
    }

    /**
     * @param float $margin
     */
    function SetLeftMargin($margin)
    {
        $this->lMargin = $margin;
        if ($this->page > 0 && $this->x < $margin) $this->x = $margin;
    }

    /**
     * @param float $margin
     */
    function SetTopMargin($margin)
    {
        $this->tMargin = $margin;
    }

    /**
     * @param float $margin
     */
    function SetRightMargin($margin)
    {
        $this->rMargin = $margin;
    }

    /**
     * @param bool $auto
     * @param float $margin
     */
    function SetAutoPageBreak($auto, $margin = 0)
    {
        $this->AutoPageBreak = $auto;
        $this->bMargin = $margin;
        $this->PageBreakTrigger = $this->h - $margin;
    }

    /**
     * @param string $zoom
     * @param string $layout
     */
    function SetDisplayMode($zoom, $layout = 'default')
    {
        $this->ZoomMode = $zoom;
        $this->LayoutMode = $layout;
    }

    /**
     * @param bool $compress
     */
    function SetCompression($compress)
    {
        if (function_exists('gzcompress')) $this->compress = $compress;
        else $this->compress = false;
    }

    /**
     * @param string $s
     * @return string
     */
    protected function _utf8_convert(string $s): string
    {
        if (function_exists('mb_convert_encoding')) {
            return mb_convert_encoding($s, 'UTF-8', 'ISO-8859-1');
        }
        return $s;
    }

    /**
     * @param string $title
     * @param bool $isUTF8
     */
    function SetTitle($title, $isUTF8 = false)
    {
        $this->metadata['Title'] = $isUTF8 ? $title : $this->_utf8_convert($title);
    }

    /**
     * @param string $author
     * @param bool $isUTF8
     */
    function SetAuthor($author, $isUTF8 = false)
    {
        $this->metadata['Author'] = $isUTF8 ? $author : $this->_utf8_convert($author);
    }

    /**
     * @param string $subject
     * @param bool $isUTF8
     */
    function SetSubject($subject, $isUTF8 = false)
    {
        $this->metadata['Subject'] = $isUTF8 ? $subject : $this->_utf8_convert($subject);
    }

    /**
     * @param string $keywords
     * @param bool $isUTF8
     */
    function SetKeywords($keywords, $isUTF8 = false)
    {
        $this->metadata['Keywords'] = $isUTF8 ? $keywords : $this->_utf8_convert($keywords);
    }

    /**
     * @param string $msg
     */
    function Error($msg)
    {
        throw new Exception('FPDF error: ' . $msg);
    }

    function Open()
    {
        $this->state = 1;
    }

    function Close()
    {
        if ($this->state == 3) return;
        if ($this->page == 0) $this->AddPage();
        $this->InFooter = true;
        $this->Footer();
        $this->InFooter = false;
        $this->_endpage();
        $this->_enddoc();
    }

    /**
     * @param string $orientation
     * @param string|array $size
     * @param int $rotation
     */
    function AddPage($orientation = '', $size = '', $rotation = 0)
    {
        if ($this->state == 0) $this->Open();
        $family = $this->FontFamily;
        $style = $this->FontStyle . ($this->underline ? 'U' : '');
        $fontsize = $this->FontSizePt;
        $lw = $this->LineWidth;
        $dc = $this->DrawColor;
        $fc = $this->FillColor;
        $tc = $this->TextColor;
        $cf = $this->ColorFlag;

        if ($this->page > 0) {
            $this->InFooter = true;
            $this->Footer();
            $this->InFooter = false;
            $this->_endpage();
        }

        $this->_beginpage($orientation, $size, $rotation);
        $this->_out('2 J');
        $this->LineWidth = $lw;
        $this->_out(sprintf('%.2F w', $lw * $this->k));
        if ($family) $this->SetFont($family, $style, $fontsize);
        $this->DrawColor = $dc;
        if ($dc != '0 G') $this->_out($dc);
        $this->FillColor = $fc;
        if ($fc != '0 g') $this->_out($fc);
        $this->TextColor = $tc;
        $this->ColorFlag = $cf;

        $this->InHeader = true;
        $this->Header();
        $this->InHeader = false;
        if ($this->LineWidth != $lw) {
            $this->LineWidth = $lw;
            $this->_out(sprintf('%.2F w', $lw * $this->k));
        }
        if ($family) $this->SetFont($family, $style, $fontsize);
        if ($this->DrawColor != $dc) {
            $this->DrawColor = $dc;
            $this->_out($dc);
        }
        if ($this->FillColor != $fc) {
            $this->FillColor = $fc;
            $this->_out($fc);
        }
        $this->TextColor = $tc;
        $this->ColorFlag = $cf;
    }

    function Header() {}
    function Footer() {}

    /**
     * @return int
     */
    function PageNo()
    {
        return $this->page;
    }

    /**
     * @return bool
     */
    function AcceptPageBreak()
    {
        return $this->AutoPageBreak;
    }

    /**
     * @param float $r
     * @param float|null $g
     * @param float|null $b
     */
    function SetDrawColor($r, $g = null, $b = null)
    {
        if (($r == 0 && $g == 0 && $b == 0) || $g === null) $this->DrawColor = sprintf('%.3F G', $r / 255);
        else $this->DrawColor = sprintf('%.3F %.3F %.3F RG', $r / 255, $g / 255, $b / 255);
        if ($this->page > 0) $this->_out($this->DrawColor);
    }

    /**
     * @param float $r
     * @param float|null $g
     * @param float|null $b
     */
    function SetFillColor($r, $g = null, $b = null)
    {
        if (($r == 0 && $g == 0 && $b == 0) || $g === null) $this->FillColor = sprintf('%.3F g', $r / 255);
        else $this->FillColor = sprintf('%.3F %.3F %.3F rg', $r / 255, $g / 255, $b / 255);
        $this->ColorFlag = ($this->FillColor != $this->TextColor);
        if ($this->page > 0) $this->_out($this->FillColor);
    }

    /**
     * @param float $r
     * @param float|null $g
     * @param float|null $b
     */
    function SetTextColor($r, $g = null, $b = null)
    {
        if (($r == 0 && $g == 0 && $b == 0) || $g === null) $this->TextColor = sprintf('%.3F g', $r / 255);
        else $this->TextColor = sprintf('%.3F %.3F %.3F rg', $r / 255, $g / 255, $b / 255);
        $this->ColorFlag = ($this->FillColor != $this->TextColor);
    }

    /**
     * @param string $s
     * @return float
     */
    function GetStringWidth($s)
    {
        $s = (string)$s;
        $cw = &$this->CurrentFont['cw'];
        $w = 0;
        $l = strlen($s);
        for ($i = 0; $i < $l; $i++) $w += $cw[$s[$i]];
        return $w * $this->FontSize / 1000;
    }

    /**
     * @param float $size
     */
    function SetFontSize($size)
    {
        $this->FontSizePt = $size;
        $this->FontSize = $size / $this->k;
        if ($this->page > 0) $this->_out(sprintf('BT /F%d %.2F Tf ET', $this->CurrentFont['i'], $this->FontSizePt));
    }

    /**
     * @param string $family
     * @param string $style
     * @param float $size
     */
    function SetFont($family, $style = '', $size = 0)
    {
        if ($family == '') $family = $this->FontFamily;
        else $family = strtolower($family);
        if ($family == 'arial') $family = 'helvetica';
        $style = strtoupper($style);
        if (strpos($style, 'U') !== false) {
            $this->underline = true;
            $style = str_replace('U', '', $style);
        } else $this->underline = false;
        if ($style == 'IB') $style = 'BI';
        if ($size == 0) $size = $this->FontSizePt;

        if ($this->FontFamily == $family && $this->FontStyle == $style && $this->FontSizePt == $size) return;

        $fontkey = $family . $style;
        if (!isset($this->fonts[$fontkey])) {
            if (in_array($family, $this->CoreFonts)) {
                if ($family == 'symbol' || $family == 'zapfdingbats') $style = '';
                $fontkey = $family . $style;
                if (!isset($this->fonts[$fontkey])) $this->_loadfont($fontkey);
            } else $this->Error('Undefined font: ' . $family . ' ' . $style);
        }
        $this->FontFamily = $family;
        $this->FontStyle = $style;
        $this->FontSizePt = $size;
        $this->FontSize = $size / $this->k;
        $this->CurrentFont = &$this->fonts[$fontkey];
        if ($this->page > 0) $this->_out(sprintf('BT /F%d %.2F Tf ET', $this->CurrentFont['i'], $this->FontSizePt));
    }

    /**
     * @param float $x1
     * @param float $y1
     * @param float $x2
     * @param float $y2
     */
    function Line($x1, $y1, $x2, $y2)
    {
        $this->_out(sprintf('%.2F %.2F m %.2F %.2F l S', $x1 * $this->k, ($this->h - $y1) * $this->k, $x2 * $this->k, ($this->h - $y2) * $this->k));
    }

    /**
     * @param float $x
     * @param float $y
     * @param float $w
     * @param float $h
     * @param string $style
     */
    function Rect($x, $y, $w, $h, $style = '')
    {
        if ($style == 'F') $op = 'f';
        elseif ($style == 'FD' || $style == 'DF') $op = 'B';
        else $op = 'S';
        $this->_out(sprintf('%.2F %.2F %.2F %.2F re %s', $x * $this->k, ($this->h - $y) * $this->k, $w * $this->k, -$h * $this->k, $op));
    }

    /**
     * @param float $x
     * @param float $y
     * @param float $w
     * @param float $h
     * @param string|int $link
     */
    function Link($x, $y, $w, $h, $link)
    {
        $this->PageLinks[$this->page][] = array($x * $this->k, $this->hPt - $y * $this->k, $w * $this->k, $h * $this->k, $link);
    }

    /**
     * @param float $w
     * @param float $h
     * @param string $txt
     * @param mixed $border
     * @param int $ln
     * @param string $align
     * @param bool $fill
     * @param string|int $link
     */
    function Cell($w, $h = 0, $txt = '', $border = 0, $ln = 0, $align = '', $fill = false, $link = '')
    {
        $k = $this->k;
        if ($this->y + $h > $this->PageBreakTrigger && !$this->InHeader && !$this->InFooter && $this->AcceptPageBreak()) {
            $x = $this->x;
            $ws = $this->ws;
            if ($ws > 0) {
                $this->ws = 0;
                $this->_out('0 Tw');
            }
            $this->AddPage($this->CurOrientation, $this->CurPageSize, $this->CurRotation);
            $this->x = $x;
            if ($ws > 0) {
                $this->ws = $ws;
                $this->_out(sprintf('%.3F Tw', $ws * $k));
            }
        }

        if ($w == 0) $w = $this->w - $this->rMargin - $this->x;
        $s = '';
        if ($fill || $border == 1) {
            if ($fill) $op = ($border == 1) ? 'B' : 'f';
            else $op = 'S';
            $s = sprintf('%.2F %.2F %.2F %.2F re %s ', $this->x * $k, ($this->h - $this->y) * $k, $w * $k, -$h * $k, $op);
        }
        if (is_string($border)) {
            $x = $this->x;
            $y = $this->y;
            if (strpos($border, 'L') !== false) $s .= sprintf('%.2F %.2F m %.2F %.2F l S ', $x * $k, ($this->h - $y) * $k, $x * $k, ($this->h - ($y + $h)) * $k);
            if (strpos($border, 'T') !== false) $s .= sprintf('%.2F %.2F m %.2F %.2F l S ', $x * $k, ($this->h - $y) * $k, ($x + $w) * $k, ($this->h - $y) * $k);
            if (strpos($border, 'R') !== false) $s .= sprintf('%.2F %.2F m %.2F %.2F l S ', ($x + $w) * $k, ($this->h - $y) * $k, ($x + $w) * $k, ($this->h - ($y + $h)) * $k);
            if (strpos($border, 'B') !== false) $s .= sprintf('%.2F %.2F m %.2F %.2F l S ', $x * $k, ($this->h - ($y + $h)) * $k, ($x + $w) * $k, ($this->h - ($y + $h)) * $k);
        }
        if ($txt !== '') {
            if ($align == 'R') $dx = $w - $this->cMargin - $this->GetStringWidth($txt);
            elseif ($align == 'C') $dx = ($w - $this->GetStringWidth($txt)) / 2;
            else $dx = $this->cMargin;
            if ($this->ColorFlag) $s .= 'q ' . $this->TextColor . ' ';
            $s .= sprintf('BT %.2F %.2F Td (%s) Tj ET', ($this->x + $dx) * $k, ($this->h - ($this->y + .5 * $h + .3 * $this->FontSize)) * $k, $this->_escape($txt));
            if ($this->underline) $s .= ' ' . $this->_dounderline($this->x + $dx, $this->y + .5 * $h + .3 * $this->FontSize, $txt);
            if ($this->ColorFlag) $s .= ' Q';
            if ($link) $this->Link($this->x + $dx, $this->y + .5 * $h - .5 * $this->FontSize, $this->GetStringWidth($txt), $this->FontSize, $link);
        }
        if ($s) $this->_out($s);
        $this->lasth = $h;
        if ($ln > 0) {
            $this->y += $h;
            if ($ln == 1) $this->x = $this->lMargin;
        } else $this->x += $w;
    }

    /**
     * @param float $w
     * @param float $h
     * @param string $txt
     * @param mixed $border
     * @param string $align
     * @param bool $fill
     */
    function MultiCell($w, $h, $txt, $border = 0, $align = 'J', $fill = false)
    {
        $cw = &$this->CurrentFont['cw'];
        if ($w == 0) $w = $this->w - $this->rMargin - $this->x;
        $wmax = ($w - 2 * $this->cMargin) * 1000 / $this->FontSize;
        $s = str_replace("\r", '', $txt);
        $nb = strlen($s);
        if ($nb > 0 && $s[$nb - 1] == "\n") $nb--;
        $b = 0;
        $b2 = '';
        if ($border) {
            if ($border == 1) {
                $border = 'LRTB';
                $b = 'LRT';
                $b2 = 'LRB';
            } else {
                $b2 = '';
                if (strpos($border, 'L') !== false) $b2 .= 'L';
                if (strpos($border, 'R') !== false) $b2 .= 'R';
                if (strpos($border, 'B') !== false) $b2 .= 'B';
                $b = strpos($border, 'T') !== false ? $b2 . 'T' : $b2;
            }
        }
        $sep = -1;
        $i = 0;
        $j = 0;
        $l = 0;
        $ns = 0;
        $nl = 1;
        while ($i < $nb) {
            $c = $s[$i];
            if ($c == "\n") {
                $this->Cell($w, $h, substr($s, $j, $i - $j), $b, 2, $align, $fill);
                $i++;
                $sep = -1;
                $j = $i;
                $l = 0;
                $ns = 0;
                $nl++;
                if ($border && $nl == 2) $b = $b2;
                continue;
            }
            if ($c == ' ') {
                $sep = $i;
                $ns++;
            }
            $l += isset($cw[$c]) ? $cw[$c] : 500;
            if ($l > $wmax) {
                if ($sep == -1) {
                    if ($i == $j) $i++;
                    $this->Cell($w, $h, substr($s, $j, $i - $j), $b, 2, $align, $fill);
                } else {
                    $this->Cell($w, $h, substr($s, $j, $sep - $j), $b, 2, $align, $fill);
                    $i = $sep + 1;
                }
                $sep = -1;
                $j = $i;
                $l = 0;
                $ns = 0;
                $nl++;
                if ($border && $nl == 2) $b = $b2;
            } else $i++;
        }
        $this->Cell($w, $h, substr($s, $j, $i - $j), $b, 2, $align, $fill);
        $this->x = $this->lMargin;
    }

    /**
     * @param float|null $h
     */
    function Ln($h = null)
    {
        $this->x = $this->lMargin;
        if ($h === null)
            $this->y += $this->lasth;
        else
            $this->y += $h;
    }

    /**
     * @return float
     */
    function GetX()
    {
        return $this->x;
    }

    /**
     * @param float $x
     */
    function SetX($x)
    {
        if ($x >= 0) $this->x = $x;
        else $this->x = $this->w + $x;
    }

    /**
     * @return float
     */
    function GetY()
    {
        return $this->y;
    }

    /**
     * @param float $y
     * @param bool $resetX
     */
    function SetY($y, $resetX = true)
    {
        if ($y >= 0) $this->y = $y;
        else $this->y = $this->h + $y;
        if ($resetX) $this->x = $this->lMargin;
    }

    /**
     * @param float $x
     * @param float $y
     */
    function SetXY($x, $y)
    {
        $this->SetY($y, false);
        $this->SetX($x);
    }

    /**
     * @param string $dest
     * @param string $name
     * @param bool $isUTF8
     * @return string
     */
    function Output($dest = '', $name = '', $isUTF8 = false)
    {
        if ($this->state < 3) $this->Close();
        if (empty($dest)) {
            $dest = 'I';
            $name = 'doc.pdf';
        }
        switch (strtoupper($dest)) {
            case 'I':
                $this->_checkoutput();
                header('Content-Type: application/pdf');
                header('Content-Disposition: inline; ' . $this->_httpdigest($name, $isUTF8));
                echo $this->buffer;
                break;
            case 'D':
                $this->_checkoutput();
                header('Content-Type: application/x-download');
                header('Content-Disposition: attachment; ' . $this->_httpdigest($name, $isUTF8));
                echo $this->buffer;
                break;
            case 'F':
                file_put_contents($name, $this->buffer);
                break;
            case 'S':
                return $this->buffer;
        }
        return '';
    }

    function Image($file, $x = null, $y = null, $w = 0, $h = 0, $type = '', $link = '')
    {
        if (!isset($this->images[$file])) {
            if ($type == '') {
                $pos = strrpos($file, '.');
                if (!$pos) $this->Error('Image file has no extension and no type was specified: ' . $file);
                $type = substr($file, $pos + 1);
            }
            $type = strtolower($type);
            if ($type == 'jpeg' || $type == 'jpg') $info = $this->_parsejpg($file);
            elseif ($type == 'png') $info = $this->_parsepng($file);
            else $this->Error('Unsupported image type: ' . $type);
            $info['i'] = count($this->images) + 1;
            $this->images[$file] = $info;
        } else {
            $info = $this->images[$file];
        }

        if ($w == 0 && $h == 0) {
            $w = -96;
            $h = -96;
        }
        if ($w < 0) $w = -$w * 72 / $info['w'] / $this->k;
        if ($h < 0) $h = -$h * 72 / $info['h'] / $this->k;
        if ($w == 0) $w = $h * $info['w'] / $info['h'];
        if ($h == 0) $h = $w * $info['h'] / $info['w'];

        if ($y === null) {
            if ($this->y + $h > $this->PageBreakTrigger && !$this->InHeader && !$this->InFooter && $this->AcceptPageBreak()) {
                $x2 = $this->x;
                $this->AddPage($this->CurOrientation, $this->CurPageSize, $this->CurRotation);
                $this->x = $x2;
            }
            $y = $this->y;
            $this->y += $h;
        }

        if ($x === null) $x = $this->x;
        $this->_out(sprintf('q %.2F 0 0 %.2F %.2F %.2F cm /I%d Do Q', $w * $this->k, $h * $this->k, $x * $this->k, ($this->h - ($y + $h)) * $this->k, $info['i']));
        if ($link) $this->Link($x, $y, $w, $h, $link);
    }

    protected function _parsepng($file)
    {
        $f = fopen($file, 'rb');
        if (!$f) $this->Error('Can\'t open image file: ' . $file);
        $info = $this->_parsepngstream($f, $file);
        fclose($f);
        return $info;
    }

    protected function _parsepngstream($f, $file)
    {
        if (fread($f, 8) != chr(137) . 'PNG' . chr(13) . chr(10) . chr(26) . chr(10))
            $this->Error('Not a PNG file: ' . $file);

        fread($f, 4);
        if (fread($f, 4) != 'IHDR')
            $this->Error('Incorrect PNG file: ' . $file);

        $w = $this->_readint($f);
        $h = $this->_readint($f);
        $bpc = ord(fread($f, 1));
        if ($bpc > 8) $this->Error('16-bit depth not supported: ' . $file);
        $ct = ord(fread($f, 1));
        if ($ct == 0 || $ct == 4) $colspace = 'DeviceGray';
        elseif ($ct == 2 || $ct == 6) $colspace = 'DeviceRGB';
        elseif ($ct == 3) $colspace = 'Indexed';
        else $this->Error('Unknown color type: ' . $file);

        if (ord(fread($f, 1)) != 0) $this->Error('Unknown compression method: ' . $file);
        if (ord(fread($f, 1)) != 0) $this->Error('Unknown filter method: ' . $file);
        if (ord(fread($f, 1)) != 0) $this->Error('Interlacing not supported: ' . $file);
        fread($f, 4);

        $dp = '/Predictor 15 /Colors ' . ($colspace == 'DeviceRGB' ? 3 : 1) . ' /BitsPerComponent ' . $bpc . ' /Columns ' . $w;

        $pal = '';
        $trns = '';
        $data = '';
        do {
            $n = $this->_readint($f);
            $type = fread($f, 4);
            if ($type == 'PLTE') {
                $pal = fread($f, $n);
                fread($f, 4);
            } elseif ($type == 'tRNS') {
                $t = fread($f, $n);
                if ($ct == 0)
                    $trns = array(ord(substr($t, 1, 1)));
                elseif ($ct == 2)
                    $trns = array(ord(substr($t, 1, 1)), ord(substr($t, 3, 1)), ord(substr($t, 5, 1)));
                else {
                    $pos = strpos($t, chr(0));
                    if ($pos !== false)
                        $trns = array($pos);
                }
                fread($f, 4);
            } elseif ($type == 'IDAT') {
                $data .= fread($f, $n);
                fread($f, 4);
            } elseif ($type == 'IEND') {
                break;
            } else {
                fread($f, $n + 4);
            }
        } while ($n);

        if ($colspace == 'Indexed' && empty($pal))
            $this->Error('Missing palette in ' . $file);

        $info = array('w' => $w, 'h' => $h, 'cs' => $colspace, 'bpc' => $bpc, 'f' => 'FlateDecode', 'dp' => $dp, 'pal' => $pal, 'trns' => $trns, 'data' => $data);
        if ($ct >= 4) {
            if (!function_exists('gzuncompress'))
                $this->Error('Zlib not available, cannot handle PNG alpha channel: ' . $file);
            $data = gzuncompress($data);
            $color = '';
            $alpha = '';
            if ($ct == 4) {
                $len = 2 * $w;
                for ($i = 0; $i < $h; $i++) {
                    $pos = (1 + $len) * $i;
                    $color .= $data[$pos];
                    $alpha .= $data[$pos];
                    $line = substr($data, $pos + 1, $len);
                    $color .= preg_replace('/(.)./s', '$1', $line);
                    $alpha .= preg_replace('/.(.)/s', '$1', $line);
                }
            } else {
                $len = 4 * $w;
                for ($i = 0; $i < $h; $i++) {
                    $pos = (1 + $len) * $i;
                    $color .= $data[$pos];
                    $alpha .= $data[$pos];
                    $line = substr($data, $pos + 1, $len);
                    $color .= preg_replace('/(...)/s', '$1', $line);
                    $alpha .= preg_replace('/...(.)/s', '$1', $line);
                }
            }
            $data = gzcompress($color);
            $info['data'] = $data;
            $info['smask'] = gzcompress($alpha);
            $this->PDFVersion = max($this->PDFVersion, '1.4');
        }
        return $info;
    }

    protected function _readint($f)
    {
        $a = unpack('Ni', fread($f, 4));
        return $a['i'];
    }

    protected function _parsejpg($file)
    {
        $a = getimagesize($file);
        if (!$a) $this->Error('Missing or incorrect image file: ' . $file);
        if ($a[2] != IMAGETYPE_JPEG) $this->Error('Not a JPEG file: ' . $file);
        $channels = isset($a['channels']) ? $a['channels'] : 3;
        if ($channels == 3) $colspace = 'DeviceRGB';
        elseif ($channels == 4) $colspace = 'DeviceCMYK';
        else $colspace = 'DeviceGray';
        $bpc = isset($a['bits']) ? $a['bits'] : 8;
        $data = file_get_contents($file);
        return array('w' => $a[0], 'h' => $a[1], 'cs' => $colspace, 'bpc' => $bpc, 'f' => 'DCTDecode', 'data' => $data);
    }

    protected function _dochecks() {}

    protected function _checkoutput()
    {
        if (PHP_SAPI != 'cli' && headers_sent($file, $line))
            $this->Error("Some data has already been output, can't send PDF file (output started at $file:$line)");
    }

    /**
     * @param string|array $size
     * @return array
     */
    protected function _getpagesize($size)
    {
        if (is_string($size)) {
            $size = strtolower($size);
            if (!isset($this->StdPageSizes[$size])) $this->Error('Unknown page size: ' . $size);
            $a = $this->StdPageSizes[$size];
            return array($a[0] / $this->k, $a[1] / $this->k);
        } else {
            if ($size[0] > $size[1]) return array($size[1], $size[0]);
            else return $size;
        }
    }

    /**
     * @param string $orientation
     * @param string|array $size
     * @param int $rotation
     */
    protected function _beginpage($orientation, $size, $rotation)
    {
        $this->page++;
        $this->pages[$this->page] = '';
        $this->state = 2;
        $this->x = $this->lMargin;
        $this->y = $this->tMargin;
        $this->FontFamily = '';
        if ($orientation == '') $orientation = $this->DefOrientation;
        else $orientation = strtoupper($orientation[0]);
        if ($size == '') $size = $this->DefPageSize;
        else $size = $this->_getpagesize($size);
        if ($orientation != $this->CurOrientation || $size[0] != $this->CurPageSize[0] || $size[1] != $this->CurPageSize[1]) {
            if ($orientation == 'P') {
                $this->w = $size[0];
                $this->h = $size[1];
            } else {
                $this->w = $size[1];
                $this->h = $size[0];
            }
            $this->wPt = $this->w * $this->k;
            $this->hPt = $this->h * $this->k;
            $this->PageBreakTrigger = $this->h - $this->bMargin;
            $this->CurOrientation = $orientation;
            $this->CurPageSize = $size;
        }
        $this->CurRotation = $rotation;
    }

    protected function _endpage()
    {
        $this->state = 1;
    }

    /**
     * @param string $font
     */
    protected function _loadfont($font)
    {
        $cw = array(
            ' ' => 278, '!' => 278, '"' => 355, '#' => 556, '$' => 556, '%' => 889, '&' => 667, "'" => 191, '(' => 333, ')' => 333, '*' => 389, '+' => 584, ',' => 278, '-' => 333, '.' => 278, '/' => 278,
            '0' => 556, '1' => 556, '2' => 556, '3' => 556, '4' => 556, '5' => 556, '6' => 556, '7' => 556, '8' => 556, '9' => 556, ':' => 278, ';' => 278, '<' => 584, '=' => 584, '>' => 584, '?' => 556,
            '@' => 1015, 'A' => 667, 'B' => 667, 'C' => 722, 'D' => 722, 'E' => 667, 'F' => 611, 'G' => 778, 'H' => 722, 'I' => 278, 'J' => 500, 'K' => 667, 'L' => 556, 'M' => 833, 'N' => 722, 'O' => 778,
            'P' => 667, 'Q' => 778, 'R' => 722, 'S' => 667, 'T' => 611, 'U' => 722, 'V' => 667, 'W' => 944, 'X' => 667, 'Y' => 667, 'Z' => 611, '[' => 333, '\\' => 278, ']' => 333, '^' => 469, '_' => 556,
            '`' => 333, 'a' => 556, 'b' => 556, 'c' => 500, 'd' => 556, 'e' => 556, 'f' => 278, 'g' => 556, 'h' => 556, 'i' => 222, 'j' => 222, 'k' => 500, 'l' => 222, 'm' => 833, 'n' => 556, 'o' => 556,
            'p' => 556, 'q' => 556, 'r' => 333, 's' => 500, 't' => 278, 'u' => 556, 'v' => 500, 'w' => 722, 'x' => 500, 'y' => 500, 'z' => 500, '{' => 334, '|' => 260, '}' => 334, '~' => 584
        );
        $i = count($this->fonts) + 1;
        $font_lower = strtolower($font);
        $font_map = array(
            'helvetica' => 'Helvetica',
            'helveticab' => 'Helvetica-Bold',
            'helveticai' => 'Helvetica-Oblique',
            'helveticabi' => 'Helvetica-BoldOblique',
            'times' => 'Times-Roman',
            'timesb' => 'Times-Bold',
            'timesi' => 'Times-Italic',
            'timesbi' => 'Times-BoldItalic',
            'courier' => 'Courier',
            'courierb' => 'Courier-Bold',
            'courieri' => 'Courier-Oblique',
            'courierbi' => 'Courier-BoldOblique',
            'symbol' => 'Symbol',
            'zapfdingbats' => 'ZapfDingbats'
        );
        $name = isset($font_map[$font_lower]) ? $font_map[$font_lower] : $font;

        $this->fonts[$font] = array('i' => $i, 'type' => 'core', 'name' => $name, 'cw' => $cw);
    }

    /**
     * @param string $s
     */
    protected function _out($s)
    {
        if ($this->state == 2) $this->pages[$this->page] .= $s . "\n";
        else $this->buffer .= $s . "\n";
    }

    protected function _enddoc()
    {
        $this->_putheader();
        $this->_putpages();
        $this->_putresources();
        $this->_putinfo();
        $this->_putcatalog();
        $this->_putxreftable();
        $this->_puttrailer();
        $this->state = 3;
    }

    protected function _putxreftable()
    {
        $this->_out('xref');
        $this->_out('0 ' . ($this->n + 1));
        $this->_out('0000000000 65535 f ');
        for ($i = 1; $i <= $this->n; $i++) {
            $this->_out(sprintf('%010d 00000 n ', $this->offsets[$i]));
        }
    }

    protected function _putheader()
    {
        $this->_out('%PDF-' . $this->PDFVersion);
    }

    protected function _putpages()
    {
        $nb = $this->page;
        $page_obj_nums = [];

        for ($n = 1; $n <= $nb; $n++) {
            // Page dictionary object
            $this->_newobj();
            $page_obj_nums[$n] = $this->n;
            $this->_out('<<');
            $this->_out('/Type /Page');
            $this->_out('/Parent 1 0 R');
            $this->_out('/MediaBox [0 0 ' . sprintf('%.2F %.2F', $this->CurPageSize[0] * $this->k, $this->CurPageSize[1] * $this->k) . ']');
            $this->_out('/Resources 2 0 R');
            $this->_out('/Contents ' . ($this->n + 1) . ' 0 R');
            $this->_out('>>');
            $this->_out('endobj');

            // Page content stream object
            $p = $this->compress ? gzcompress($this->pages[$n]) : $this->pages[$n];
            $this->_newobj();
            $this->_out('<<');
            $this->_out('/Length ' . strlen($p));
            if ($this->compress) $this->_out('/Filter /FlateDecode');
            $this->_out('>>');
            $this->_putstream($p);
            $this->_out('endobj');
        }

        // Write the Pages dictionary (object 1) - the root of the page tree
        $this->offsets[1] = strlen($this->buffer);
        $this->_out('1 0 obj');
        $this->_out('<<');
        $this->_out('/Type /Pages');
        $kids = [];
        for ($n = 1; $n <= $nb; $n++) {
            $kids[] = $page_obj_nums[$n] . ' 0 R';
        }
        $this->_out('/Kids [' . implode(' ', $kids) . ']');
        $this->_out('/Count ' . $nb);
        $this->_out('>>');
        $this->_out('endobj');
    }

    protected function _putfonts()
    {
        foreach ($this->fonts as $k => $font) {
            $this->_newobj();
            $this->fonts[$k]['n'] = $this->n;
            $this->_out('<</Type /Font');
            $this->_out('/BaseFont /' . $font['name']);
            $this->_out('/Subtype /Type1');
            $this->_out('/Encoding /WinAnsiEncoding');
            $this->_out('>>');
            $this->_out('endobj');
        }
    }

    protected function _putimages()
    {
        foreach ($this->images as $file => $info) {
            $this->_putimage($info);
            $this->images[$file]['n'] = $this->n;
        }
    }

    protected function _putimage(&$info)
    {
        $this->_newobj();
        $info['n'] = $this->n;
        $this->_out('<</Type /XObject');
        $this->_out('/Subtype /Image');
        $this->_out('/Width ' . $info['w']);
        $this->_out('/Height ' . $info['h']);
        if ($info['cs'] == 'Indexed')
            $this->_out('/ColorSpace [/Indexed /DeviceRGB ' . (strlen($info['pal']) / 3 - 1) . ' ' . ($this->n + 1) . ' 0 R]');
        else {
            $this->_out('/ColorSpace /' . $info['cs']);
            if ($info['cs'] == 'DeviceCMYK')
                $this->_out('/Decode [1 0 1 0 1 0 1 0]');
        }
        $this->_out('/BitsPerComponent ' . $info['bpc']);
        if (isset($info['f']))
            $this->_out('/Filter /' . $info['f']);
        if (isset($info['dp']))
            $this->_out('/DecodeParms <<' . $info['dp'] . '>>');
        if (isset($info['trns']) && is_array($info['trns'])) {
            $trns = '';
            for ($i = 0; $i < count($info['trns']); $i++)
                $trns .= $info['trns'][$i] . ' ' . $info['trns'][$i] . ' ';
            $this->_out('/Mask [' . $trns . ']');
        }
        if (isset($info['smask']))
            $this->_out('/SMask ' . ($this->n + 1) . ' 0 R');
        $this->_out('/Length ' . strlen($info['data']) . '>>');
        $this->_putstream($info['data']);
        $this->_out('endobj');

        if (isset($info['smask'])) {
            $dp = '/Predictor 15 /Colors 1 /BitsPerComponent ' . $info['bpc'] . ' /Columns ' . $info['w'];
            $smask = array('w' => $info['w'], 'h' => $info['h'], 'cs' => 'DeviceGray', 'bpc' => $info['bpc'], 'f' => 'FlateDecode', 'dp' => $dp, 'data' => $info['smask']);
            $this->_putimage($smask);
        }
        if ($info['cs'] == 'Indexed') {
            $this->_newobj();
            $this->_out('<<' . (isset($this->compress) && $this->compress ? '/Filter /FlateDecode ' : '') . '/Length ' . strlen($info['pal']) . '>>');
            $this->_putstream($info['pal']);
            $this->_out('endobj');
        }
    }

    protected function _putresources()
    {
        $this->_putfonts();
        $this->_putimages();
        $this->_newobj(2);
        $this->_out('<</ProcSet [/PDF /Text /ImageB /ImageC /ImageI]');
        $this->_out('/Font <<');
        foreach ($this->fonts as $font) $this->_out('/F' . $font['i'] . ' ' . $font['n'] . ' 0 R');
        $this->_out('>>');
        if (!empty($this->images)) {
            $this->_out('/XObject <<');
            foreach ($this->images as $image)
                $this->_out('/I' . $image['i'] . ' ' . $image['n'] . ' 0 R');
            $this->_out('>>');
        }
        $this->_out('>>');
        $this->_out('endobj');
    }

    protected function _putinfo()
    {
        $this->_newobj();
        $this->_out('<<');
        $this->_out('/Producer (FPDF ' . FPDF_VERSION . ')');
        if (!empty($this->metadata['Title'])) $this->_out('/Title (' . $this->_escape($this->metadata['Title']) . ')');
        $this->_out('/CreationDate (D:' . date('YmdHis') . ')');
        $this->_out('>>');
        $this->_out('endobj');
    }

    protected function _putcatalog()
    {
        $this->_newobj();
        $this->_out('<</Type /Catalog');
        $this->_out('/Pages 1 0 R');
        $this->_out('>>');
        $this->_out('endobj');
    }

    protected function _puttrailer()
    {
        $this->_out('trailer');
        $this->_out('<</Size ' . ($this->n + 1));
        $this->_out('/Root ' . $this->n . ' 0 R');
        $this->_out('>>');
        $this->_out('startxref');
        $this->_out(strlen($this->buffer));
        $this->_out('%%EOF');
    }

    /**
     * @param int|null $n
     */
    protected function _newobj($n = null)
    {
        if ($n === null) $n = ++$this->n;
        $this->offsets[$n] = strlen($this->buffer);
        $this->_out($n . ' 0 obj');
    }

    /**
     * @param string $s
     */
    protected function _putstream($s)
    {
        $this->_out('stream');
        $this->_out($s);
        $this->_out('endstream');
    }

    /**
     * @param string $s
     * @return string
     */
    protected function _escape($s)
    {
        return str_replace(array('\\', '(', ')', "\r"), array('\\\\', '\\(', '\\)', ""), $s);
    }

    /**
     * @param float $x
     * @param float $y
     * @param string $txt
     * @return string
     */
    protected function _dounderline($x, $y, $txt)
    {
        $w = $this->GetStringWidth($txt) + $this->ws * substr_count($txt, ' ');
        return sprintf('%.2F %.2F %.2F %.2F re f', $x * $this->k, ($this->h - ($y - $this->FontSize * .1)) * $this->k, $w * $this->k, -$this->FontSize * .1 * $this->k);
    }

    /**
     * @param string $name
     * @param bool $isUTF8
     * @return string
     */
    protected function _httpdigest($name, $isUTF8)
    {
        return 'filename="' . $name . '"';
    }
}