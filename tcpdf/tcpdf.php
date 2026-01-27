<?php
// tcpdf/tcpdf.php - Simple TCPDF Implementation
if (!class_exists('TCPDF')) {
    class TCPDF
    {
        protected $page;
        protected $pages;
        protected $currentPage;
        protected $margin_left;
        protected $margin_top;
        protected $margin_right;
        protected $margin_bottom;
        protected $orientation;
        protected $unit;
        protected $page_format;
        protected $font;
        protected $font_size;
        protected $font_style;
        protected $fill_color;
        protected $text_color;
        protected $draw_color;
        protected $line_width;
        protected $auto_page_break;
        protected $page_break_trigger;
        protected $header_fn;
        protected $footer_fn;
        protected $x;
        protected $y;
        protected $w;
        protected $h;
        protected $lasth;
        protected $line_height;

        // Constants
        const PORTRAIT = 'P';
        const LANDSCAPE = 'L';

        public function __construct($orientation = 'P', $unit = 'mm', $format = 'A4', $unicode = true, $encoding = 'UTF-8', $diskcache = false, $pdfa = false)
        {
            $this->orientation = $orientation;
            $this->unit = $unit;
            $this->page_format = $format;
            $this->pages = array();
            $this->currentPage = 0;
            $this->font = 'helvetica';
            $this->font_size = 10;
            $this->font_style = '';
            $this->fill_color = array(255, 255, 255);
            $this->text_color = array(0, 0, 0);
            $this->draw_color = array(0, 0, 0);
            $this->line_width = 0.2;
            $this->auto_page_break = true;
            $this->page_break_trigger = 250;
            $this->line_height = 5;
            $this->x = 0;
            $this->y = 0;
        }

        public function SetCreator($creator)
        {
            // PDF metadata - not implemented in simple version
        }

        public function SetAuthor($author)
        {
            // PDF metadata - not implemented in simple version
        }

        public function SetTitle($title)
        {
            // PDF metadata - not implemented in simple version
        }

        public function SetSubject($subject)
        {
            // PDF metadata - not implemented in simple version
        }

        public function SetMargins($left, $top, $right = -1, $keepmargins = false)
        {
            $this->margin_left = $left;
            $this->margin_top = $top;
            $this->margin_right = ($right == -1) ? $left : $right;
        }

        public function SetHeaderMargin($margin)
        {
            // Header margin - not implemented
        }

        public function SetFooterMargin($margin)
        {
            // Footer margin - not implemented
        }

        public function SetAutoPageBreak($auto, $margin = 0)
        {
            $this->auto_page_break = $auto;
            $this->margin_bottom = $margin;
        }

        public function AddPage($orientation = '', $format = '', $keepmargins = false, $tocpage = false)
        {
            $this->currentPage++;
            $this->pages[$this->currentPage] = '';
            $this->x = $this->margin_left;
            $this->y = $this->margin_top;
        }

        public function SetFont($family, $style = '', $size = null, $fontfile = '', $subset = 'default', $out = true)
        {
            $this->font = $family;
            $this->font_style = $style;
            if ($size !== null) {
                $this->font_size = $size;
            }
        }

        public function Cell($w, $h = 0, $txt = '', $border = 0, $ln = 0, $align = '', $fill = false, $link = '', $stretch = 0, $ignore_min_height = false, $calign = 'T', $valign = 'M')
        {
            $txt = htmlspecialchars_decode($txt);

            if ($fill) {
                $this->pages[$this->currentPage] .= "<div style='background-color:rgb({$this->fill_color[0]},{$this->fill_color[1]},{$this->fill_color[2]});padding:2px;'>$txt</div>";
            } else {
                $this->pages[$this->currentPage] .= "<div>$txt</div>";
            }

            $this->lasth = $h;
            if ($ln > 0) {
                $this->y += $h;
                $this->x = $this->margin_left;
            } else {
                $this->x += $w;
            }
        }

        public function MultiCell($w, $h, $txt, $border = 0, $align = 'J', $fill = false, $ln = 1, $x = '', $y = '', $reseth = true, $stretch = 0, $ishtml = false, $autopadding = true, $maxh = 0, $valign = 'T', $fitcell = false)
        {
            $txt = htmlspecialchars_decode($txt);

            if ($fill) {
                $this->pages[$this->currentPage] .= "<div style='background-color:rgb({$this->fill_color[0]},{$this->fill_color[1]},{$this->fill_color[2]});padding:2px;margin-bottom:{$h}mm;'>$txt</div>";
            } else {
                $this->pages[$this->currentPage] .= "<div style='margin-bottom:{$h}mm;'>$txt</div>";
            }

            $this->y += $h;
            $this->x = $this->margin_left;
        }

        public function Ln($h = null)
        {
            $h = $h ? $h : $this->lasth;
            $this->y += $h;
            $this->x = $this->margin_left;
            $this->pages[$this->currentPage] .= "<br style='line-height:{$h}mm'>";
        }

        public function SetFillColor($r, $g = null, $b = null)
        {
            if (is_array($r)) {
                $this->fill_color = $r;
            } else {
                $this->fill_color = array($r, $g, $b);
            }
        }

        public function SetTextColor($r, $g = null, $b = null)
        {
            if (is_array($r)) {
                $this->text_color = $r;
            } else {
                $this->text_color = array($r, $g, $b);
            }
        }

        public function SetDrawColor($r, $g = null, $b = null)
        {
            if (is_array($r)) {
                $this->draw_color = $r;
            } else {
                $this->draw_color = array($r, $g, $b);
            }
        }

        public function SetLineWidth($width)
        {
            $this->line_width = $width;
        }

        public function Output($name = 'doc.pdf', $dest = 'I')
        {
            // For simplicity, we'll output as HTML that can be printed as PDF
            header('Content-Type: text/html; charset=utf-8');

            echo '<!DOCTYPE html>
            <html>
            <head>
                <title>Compensation Report</title>
                <style>
                    body { font-family: Arial, sans-serif; margin: 20px; }
                    .page { margin-bottom: 30px; padding: 20px; border: 1px solid #ccc; }
                    .header { text-align: center; margin-bottom: 20px; }
                    .table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
                    .table th, .table td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                    .table th { background-color: #f2f2f2; font-weight: bold; }
                    .total-row { background-color: #e6f3ff; font-weight: bold; }
                    .summary { margin-bottom: 15px; padding: 10px; background-color: #f8f9fa; }
                    @media print {
                        body { margin: 0; }
                        .page { border: none; page-break-after: always; }
                        .no-print { display: none; }
                    }
                </style>
            </head>
            <body>
                <div class="no-print" style="margin-bottom: 20px;">
                    <button onclick="window.print()" style="padding: 10px 20px; background: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer;">
                        Print as PDF
                    </button>
                    <button onclick="window.close()" style="padding: 10px 20px; background: #6c757d; color: white; border: none; border-radius: 4px; cursor: pointer; margin-left: 10px;">
                        Close
                    </button>
                </div>';

            foreach ($this->pages as $pageNumber => $pageContent) {
                echo '<div class="page">';
                echo $pageContent;
                echo '</div>';
            }

            echo '</body></html>';
            exit;
        }
    }
}
