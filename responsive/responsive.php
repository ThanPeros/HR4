<?php
// responsive.php - Auto-Responsive System for HR Management
// Include this file at the beginning of all your system files

ob_start();

/**
 * Auto-Responsive System Class
 */
class AutoResponsiveSystem
{

    private $css_rules = '';
    private $js_code = '';

    public function __construct()
    {
        $this->generateResponsiveCSS();
        $this->generateResponsiveJS();
    }

    /**
     * Generate responsive CSS rules
     */
    private function generateResponsiveCSS()
    {
        $this->css_rules = '
        /* AUTO-RESPONSIVE SYSTEM CSS */
        
        /* Base responsive container */
        .auto-responsive-container {
            width: 100%;
            max-width: 100%;
            padding-right: 15px;
            padding-left: 15px;
            margin-right: auto;
            margin-left: auto;
        }
        
        /* Make all tables responsive */
        table:not(.no-auto-responsive) {
            width: 100%;
            max-width: 100%;
            margin-bottom: 1rem;
            background-color: transparent;
        }
        
        .auto-responsive-table-wrapper {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            -ms-overflow-style: -ms-autohiding-scrollbar;
        }
        
        /* Make all forms responsive */
        form:not(.no-auto-responsive) {
            width: 100%;
        }
        
        .auto-responsive-form-group {
            margin-bottom: 1rem;
        }
        
        /* Make all form inputs responsive */
        input:not(.no-auto-responsive),
        select:not(.no-auto-responsive),
        textarea:not(.no-auto-responsive) {
            max-width: 100%;
            box-sizing: border-box;
        }
        
        /* Make all images responsive */
        img:not(.no-auto-responsive) {
            max-width: 100%;
            height: auto;
        }
        
        /* Make all containers fluid */
        .container:not(.no-auto-responsive),
        .content-area:not(.no-auto-responsive),
        .main-content:not(.no-auto-responsive) {
            width: 100%;
            max-width: 100%;
            padding-left: 15px;
            padding-right: 15px;
            box-sizing: border-box;
        }
        
        /* Responsive grid for all data tables */
        .data-table:not(.no-auto-responsive) {
            display: block;
            width: 100%;
            overflow-x: auto;
        }
        
        .data-table:not(.no-auto-responsive) table {
            min-width: 600px;
        }
        
        /* Make all buttons responsive */
        .btn:not(.no-auto-responsive) {
            display: inline-block;
            max-width: 100%;
            white-space: normal;
            word-wrap: break-word;
        }
        
        /* Make all cards responsive */
        .card:not(.no-auto-responsive),
        .stat-card:not(.no-auto-responsive),
        .form-container:not(.no-auto-responsive) {
            width: 100%;
            max-width: 100%;
            margin-bottom: 15px;
            box-sizing: border-box;
        }
        
        /* Make all navigation responsive */
        .tabs:not(.no-auto-responsive) {
            flex-wrap: wrap;
        }
        
        .tab:not(.no-auto-responsive) {
            flex: 1;
            min-width: 120px;
            text-align: center;
        }
        
        /* Auto-responsive utility classes */
        .auto-responsive-hide-on-mobile { display: block; }
        .auto-responsive-show-on-mobile { display: none; }
        
        .auto-responsive-text-center { text-align: center; }
        .auto-responsive-text-left { text-align: left; }
        .auto-responsive-text-right { text-align: right; }
        
        /* ========== MOBILE STYLES (max-width: 768px) ========== */
        @media (max-width: 768px) {
            /* Hide/show elements on mobile */
            .auto-responsive-hide-on-mobile { display: none !important; }
            .auto-responsive-show-on-mobile { display: block !important; }
            
            /* Make all containers full width */
            .container:not(.no-auto-responsive),
            .content-area:not(.no-auto-responsive),
            .main-content:not(.no-auto-responsive),
            .form-container:not(.no-auto-responsive) {
                padding-left: 10px;
                padding-right: 10px;
                margin-left: 0;
                margin-right: 0;
            }
            
            /* Stack all grid layouts */
            .form-grid:not(.no-auto-responsive),
            .stats-container:not(.no-auto-responsive),
            .grid:not(.no-auto-responsive) {
                display: block !important;
            }
            
            /* Stack all form groups */
            .form-grid:not(.no-auto-responsive) > *,
            .stats-container:not(.no-auto-responsive) > *,
            .grid:not(.no-auto-responsive) > * {
                width: 100% !important;
                margin-bottom: 15px;
            }
            
            /* Make all tables scroll horizontally */
            table:not(.no-auto-responsive) {
                display: block;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
            
            /* Stack table headers */
            .data-table:not(.no-auto-responsive) thead {
                display: none;
            }
            
            .data-table:not(.no-auto-responsive) tbody tr {
                display: block;
                margin-bottom: 15px;
                border: 1px solid #dee2e6;
                border-radius: 4px;
            }
            
            .data-table:not(.no-auto-responsive) tbody td {
                display: block;
                text-align: right;
                padding: 8px;
                position: relative;
                padding-left: 50%;
                border-bottom: 1px solid #dee2e6;
            }
            
            .data-table:not(.no-auto-responsive) tbody td:before {
                content: attr(data-label);
                position: absolute;
                left: 8px;
                width: calc(50% - 16px);
                padding-right: 10px;
                text-align: left;
                font-weight: bold;
                white-space: nowrap;
            }
            
            /* Stack all form actions */
            .form-actions:not(.no-auto-responsive) {
                flex-direction: column;
            }
            
            .form-actions:not(.no-auto-responsive) .btn {
                width: 100%;
                margin-bottom: 10px;
            }
            
            /* Stack all tabs */
            .tabs:not(.no-auto-responsive) {
                flex-direction: column;
            }
            
            .tab:not(.no-auto-responsive) {
                width: 100%;
                text-align: left;
                padding: 12px 15px;
                border-bottom: 1px solid #e3e6f0;
            }
            
            /* Make all buttons full width */
            .btn:not(.btn-sm):not(.no-auto-responsive) {
                width: 100%;
                margin-bottom: 10px;
            }
            
            /* Adjust font sizes for mobile */
            h1:not(.no-auto-responsive) { font-size: 1.5rem !important; }
            h2:not(.no-auto-responsive) { font-size: 1.3rem !important; }
            h3:not(.no-auto-responsive) { font-size: 1.2rem !important; }
            h4:not(.no-auto-responsive) { font-size: 1.1rem !important; }
            h5:not(.no-auto-responsive) { font-size: 1rem !important; }
            h6:not(.no-auto-responsive) { font-size: 0.9rem !important; }
            
            /* Adjust padding for mobile */
            .page-header:not(.no-auto-responsive),
            .nav-container:not(.no-auto-responsive),
            .form-container:not(.no-auto-responsive) {
                padding: 15px !important;
            }
            
            /* Make search/filter containers stack */
            .search-filter-container:not(.no-auto-responsive) {
                flex-direction: column;
            }
            
            .search-filter-container:not(.no-auto-responsive) > * {
                width: 100%;
                margin-bottom: 10px;
            }
            
            /* Adjust table container padding */
            .table-container:not(.no-auto-responsive) {
                padding: 0 10px 15px 10px !important;
            }
            
            /* Stack action buttons in tables */
            .actions-cell:not(.no-auto-responsive) {
                flex-direction: column;
            }
            
            .actions-cell:not(.no-auto-responsive) .btn {
                width: 100%;
                margin-bottom: 5px;
            }
            
            /* Make modals full width on mobile */
            .modal:not(.no-auto-responsive) {
                width: 95% !important;
                margin: 10px auto !important;
            }
            
            /* Adjust stat cards */
            .stat-card:not(.no-auto-responsive) {
                margin-bottom: 15px;
            }
            
            /* Auto text alignment for mobile */
            .auto-responsive-text-center { text-align: center !important; }
            .auto-responsive-text-left { text-align: left !important; }
            .auto-responsive-text-right { text-align: right !important; }
        }
        
        /* ========== TABLET STYLES (769px - 1024px) ========== */
        @media (min-width: 769px) and (max-width: 1024px) {
            .form-grid:not(.no-auto-responsive) {
                grid-template-columns: repeat(2, 1fr) !important;
            }
            
            .stats-container:not(.no-auto-responsive) {
                grid-template-columns: repeat(2, 1fr) !important;
            }
            
            .tabs:not(.no-auto-responsive) .tab {
                min-width: 100px;
            }
        }
        
        /* ========== VERY SMALL MOBILE (max-width: 480px) ========== */
        @media (max-width: 480px) {
            /* Further reduce padding */
            .container:not(.no-auto-responsive),
            .content-area:not(.no-auto-responsive),
            .main-content:not(.no-auto-responsive) {
                padding-left: 5px;
                padding-right: 5px;
            }
            
            /* Reduce font sizes further */
            h1:not(.no-auto-responsive) { font-size: 1.3rem !important; }
            h2:not(.no-auto-responsive) { font-size: 1.2rem !important; }
            h3:not(.no-auto-responsive) { font-size: 1.1rem !important; }
            
            /* Make form inputs easier to tap */
            input:not(.no-auto-responsive),
            select:not(.no-auto-responsive),
            textarea:not(.no-auto-responsive) {
                font-size: 16px !important; /* Prevents iOS zoom */
                padding: 12px !important;
            }
            
            /* Increase button tap targets */
            .btn:not(.no-auto-responsive) {
                padding: 12px !important;
                min-height: 44px;
            }
            
            /* Stack everything */
            .form-grid:not(.no-auto-responsive),
            .stats-container:not(.no-auto-responsive) {
                display: block !important;
            }
            
            /* Hide complex table columns on very small screens */
            .data-table:not(.no-auto-responsive) td:nth-child(n+5),
            .data-table:not(.no-auto-responsive) th:nth-child(n+5) {
                display: none;
            }
            
            .data-table:not(.no-auto-responsive) td:before {
                width: calc(100% - 16px);
            }
            
            .data-table:not(.no-auto-responsive) td {
                padding-left: 8px;
                text-align: left;
            }
        }
        
        /* ========== PRINT STYLES ========== */
        @media print {
            .no-print,
            .auto-responsive-hide-on-print {
                display: none !important;
            }
            
            .auto-responsive-show-on-print {
                display: block !important;
            }
            
            table:not(.no-auto-responsive) {
                width: 100% !important;
                max-width: 100% !important;
            }
            
            .btn:not(.no-auto-responsive),
            .form-actions:not(.no-auto-responsive),
            .actions-cell:not(.no-auto-responsive) {
                display: none !important;
            }
        }
        
        /* ========== DARK MODE RESPONSIVE ADJUSTMENTS ========== */
        @media (prefers-color-scheme: dark) {
            body.dark-mode .data-table:not(.no-auto-responsive) tbody tr {
                border-color: #4a5568;
            }
            
            body.dark-mode .data-table:not(.no-auto-responsive) tbody td {
                border-color: #4a5568;
            }
            
            body.dark-mode .data-table:not(.no-auto-responsive) tbody td:before {
                color: #a0aec0;
            }
        }
        ';

        return $this->css_rules;
    }

    /**
     * Generate responsive JavaScript
     */
    private function generateResponsiveJS()
    {
        $this->js_code = '
        <script>
        // AUTO-RESPONSIVE SYSTEM JS
        document.addEventListener("DOMContentLoaded", function() {
            // Add data-label attributes to table cells for mobile display
            function addTableLabels() {
                const tables = document.querySelectorAll("table.data-table:not(.no-auto-responsive)");
                
                tables.forEach(table => {
                    const headers = table.querySelectorAll("thead th");
                    const rows = table.querySelectorAll("tbody tr");
                    
                    rows.forEach(row => {
                        const cells = row.querySelectorAll("td");
                        cells.forEach((cell, index) => {
                            if (headers[index]) {
                                cell.setAttribute("data-label", headers[index].textContent.trim());
                            }
                        });
                    });
                });
            }
            
            // Make tables horizontally scrollable on mobile
            function makeTablesScrollable() {
                const tables = document.querySelectorAll("table:not(.no-auto-responsive)");
                
                tables.forEach(table => {
                    if (!table.parentElement.classList.contains("auto-responsive-table-wrapper")) {
                        const wrapper = document.createElement("div");
                        wrapper.className = "auto-responsive-table-wrapper";
                        table.parentNode.insertBefore(wrapper, table);
                        wrapper.appendChild(table);
                    }
                });
            }
            
            // Adjust form layout for mobile
            function adjustFormLayout() {
                const forms = document.querySelectorAll("form:not(.no-auto-responsive)");
                
                forms.forEach(form => {
                    // Add auto-responsive classes to form groups
                    const formGroups = form.querySelectorAll(".form-group");
                    formGroups.forEach(group => {
                        if (!group.classList.contains("auto-responsive-form-group")) {
                            group.classList.add("auto-responsive-form-group");
                        }
                    });
                    
                    // Make sure form inputs don\'t overflow
                    const inputs = form.querySelectorAll("input, select, textarea");
                    inputs.forEach(input => {
                        if (!input.classList.contains("no-auto-responsive")) {
                            input.style.maxWidth = "100%";
                            input.style.boxSizing = "border-box";
                        }
                    });
                });
            }
            
            // Add responsive classes to main containers
            function addResponsiveContainers() {
                // Main content containers
                const mainContainers = document.querySelectorAll(".main-content, .content-area, .container");
                mainContainers.forEach(container => {
                    if (!container.classList.contains("no-auto-responsive") && 
                        !container.classList.contains("auto-responsive-container")) {
                        container.classList.add("auto-responsive-container");
                    }
                });
                
                // Form containers
                const formContainers = document.querySelectorAll(".form-container, .card, .stat-card");
                formContainers.forEach(container => {
                    if (!container.classList.contains("no-auto-responsive")) {
                        container.style.width = "100%";
                        container.style.maxWidth = "100%";
                        container.style.boxSizing = "border-box";
                    }
                });
            }
            
            // Adjust button sizes for mobile
            function adjustButtons() {
                const buttons = document.querySelectorAll(".btn:not(.btn-sm):not(.no-auto-responsive)");
                
                buttons.forEach(button => {
                    // Add responsive text class for very long button text
                    if (button.textContent.length > 20) {
                        button.style.whiteSpace = "normal";
                        button.style.wordWrap = "break-word";
                    }
                });
            }
            
            // Handle window resize
            function handleResize() {
                const isMobile = window.innerWidth <= 768;
                
                // Add/remove mobile class to body
                if (isMobile) {
                    document.body.classList.add("auto-responsive-mobile");
                    document.body.classList.remove("auto-responsive-desktop");
                } else {
                    document.body.classList.add("auto-responsive-desktop");
                    document.body.classList.remove("auto-responsive-mobile");
                }
                
                // Adjust form grid layout
                const formGrids = document.querySelectorAll(".form-grid:not(.no-auto-responsive)");
                formGrids.forEach(grid => {
                    if (isMobile) {
                        grid.style.display = "block";
                    } else {
                        grid.style.display = "grid";
                    }
                });
            }
            
            // Initialize all responsive functions
            function initResponsive() {
                addTableLabels();
                makeTablesScrollable();
                adjustFormLayout();
                addResponsiveContainers();
                adjustButtons();
                handleResize();
                
                // Add resize listener
                window.addEventListener("resize", handleResize);
                
                // Add touch device detection
                if ("ontouchstart" in window || navigator.maxTouchPoints > 0) {
                    document.body.classList.add("auto-responsive-touch");
                    
                    // Increase tap target sizes for touch devices
                    const tapTargets = document.querySelectorAll(".btn, .nav-link, .tab, .form-check-label");
                    tapTargets.forEach(target => {
                        if (!target.classList.contains("no-auto-responsive")) {
                            target.style.minHeight = "44px";
                            target.style.minWidth = "44px";
                        }
                    });
                }
            }
            
            // Initialize when DOM is loaded
            initResponsive();
            
            // Re-initialize after dynamic content loads (if using AJAX)
            if (typeof MutationObserver !== "undefined") {
                const observer = new MutationObserver(function(mutations) {
                    mutations.forEach(function(mutation) {
                        if (mutation.addedNodes.length) {
                            setTimeout(initResponsive, 100);
                        }
                    });
                });
                
                observer.observe(document.body, {
                    childList: true,
                    subtree: true
                });
            }
        });
        </script>
        ';

        return $this->js_code;
    }

    /**
     * Get CSS rules (public accessor)
     */
    public function getCSS()
    {
        return $this->css_rules;
    }

    /**
     * Get JS code (public accessor)
     */
    public function getJS()
    {
        return $this->js_code;
    }

    /**
     * Inject responsive CSS and JS into the page
     */
    public function injectResponsiveAssets()
    {
        // Inject CSS
        echo '<style>' . $this->getCSS() . '</style>';

        // Inject JS
        echo $this->getJS();
    }

    /**
     * Process existing HTML and make it responsive
     */
    public function processHTML($html)
    {
        // Add responsive wrapper to tables
        $html = preg_replace_callback(
            '/(<table[^>]*class="[^"]*data-table[^"]*"[^>]*>.*?<\/table>)/is',
            function ($matches) {
                if (strpos($matches[1], 'no-auto-responsive') === false) {
                    return '<div class="auto-responsive-table-wrapper">' . $matches[1] . '</div>';
                }
                return $matches[1];
            },
            $html
        );

        // Add responsive classes to main containers
        $html = preg_replace_callback(
            '/(<(div|section|main)[^>]*class="[^"]*(main-content|content-area|container)[^"]*"[^>]*>)/i',
            function ($matches) {
                if (
                    strpos($matches[1], 'no-auto-responsive') === false &&
                    strpos($matches[1], 'auto-responsive-container') === false
                ) {
                    return str_replace('class="', 'class="auto-responsive-container ', $matches[1]);
                }
                return $matches[1];
            },
            $html
        );

        // Make sure form inputs are responsive
        $html = preg_replace_callback(
            '/(<(input|select|textarea)[^>]*>)/i',
            function ($matches) {
                if (strpos($matches[1], 'no-auto-responsive') === false) {
                    return str_replace('>', ' style="max-width:100%;box-sizing:border-box;">', $matches[1]);
                }
                return $matches[1];
            },
            $html
        );

        return $html;
    }

    /**
     * Check if device is mobile
     */
    public static function isMobile()
    {
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $mobileAgents = [
            'Android',
            'webOS',
            'iPhone',
            'iPad',
            'iPod',
            'BlackBerry',
            'Windows Phone',
            'Mobile',
            'Opera Mini',
            'IEMobile'
        ];

        foreach ($mobileAgents as $agent) {
            if (stripos($userAgent, $agent) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get device type
     */
    public static function getDeviceType()
    {
        $width = $_GET['screen_width'] ?? (isset($_SERVER['HTTP_X_SCREEN_WIDTH']) ? $_SERVER['HTTP_X_SCREEN_WIDTH'] : null);

        if (!$width && isset($_SERVER['HTTP_USER_AGENT'])) {
            $userAgent = $_SERVER['HTTP_USER_AGENT'];

            if (preg_match('/Mobile|Android|iPhone|iPad|iPod|BlackBerry|Windows Phone/i', $userAgent)) {
                return 'mobile';
            } elseif (preg_match('/Tablet|iPad/i', $userAgent)) {
                return 'tablet';
            }
        }

        return 'desktop';
    }
}

/**
 * Global function to include responsive system
 */
function include_auto_responsive()
{
    static $responsive = null;

    if ($responsive === null) {
        $responsive = new AutoResponsiveSystem();
    }

    // Start output buffering to process HTML
    ob_start(function ($buffer) use ($responsive) {
        // Process the HTML to make it responsive
        $processed = $responsive->processHTML($buffer);

        // Find the position to inject CSS (before </head>)
        $headPos = strpos($processed, '</head>');
        if ($headPos !== false) {
            $css = '<style>' . $responsive->getCSS() . '</style>';
            $processed = substr_replace($processed, $css, $headPos, 0);
        }

        // Find the position to inject JS (before </body>)
        $bodyPos = strpos($processed, '</body>');
        if ($bodyPos !== false) {
            $js = $responsive->getJS();
            $processed = substr_replace($processed, $js, $bodyPos, 0);
        }

        return $processed;
    });

    // Register shutdown function to flush buffer
    register_shutdown_function(function () use ($responsive) {
        $output = ob_get_contents();
        if ($output) {
            ob_end_clean();

            // Process and output
            $processed = $responsive->processHTML($output);

            // Inject CSS and JS
            $headPos = strpos($processed, '</head>');
            if ($headPos !== false) {
                $css = '<style>' . $responsive->getCSS() . '</style>';
                $processed = substr_replace($processed, $css, $headPos, 0);
            }

            $bodyPos = strpos($processed, '</body>');
            if ($bodyPos !== false) {
                $js = $responsive->getJS();
                $processed = substr_replace($processed, $js, $bodyPos, 0);
            }

            echo $processed;
        }
    });
}

// Auto-initialize if not included manually
if (!defined('AUTO_RESPONSIVE_LOADED')) {
    define('AUTO_RESPONSIVE_LOADED', true);
    include_auto_responsive();
}
