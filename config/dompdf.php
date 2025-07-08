<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Settings
    |--------------------------------------------------------------------------
    */

    'show_warnings' => false,
    'public_path' => null,
    'convert_entities' => true,
    'options' => [
        /**
         * The location of the DOMPDF font directory
         */
        "font_dir" => storage_path('fonts/'),

        /**
         * The location of the DOMPDF font cache directory
         */
        "font_cache" => storage_path('fonts/'),

        /**
         * The location of temporary directory.
         */
        "temp_dir" => sys_get_temp_dir(),

        /**
         * dompdf's "chroot"; limits file access to given directory.
         */
        "chroot" => realpath(base_path()),

        /**
         * Protocol whitelist
         */
        "allowed_protocols" => [
            "file://" => ["rules" => []],
            "http://" => ["rules" => []],
            "https://" => ["rules" => []]
        ],

        /**
         * @deprecated
         */
        "log_output_file" => null,

        /**
         * Whether to enable font subsetting or not.
         */
        "enable_font_subsetting" => false,

        /**
         * The PDF rendering backend to use
         */
        "pdf_backend" => "CPDF",

        /**
         * Default font family
         */
        "default_font" => "serif",

        /**
         * The default paper size.
         */
        "default_paper_size" => "a4",

        /**
         * The default paper orientation.
         */
        "default_paper_orientation" => "portrait",

        /**
         * Whether to enable PHP
         */
        "enable_php" => false,

        /**
         * Whether to enable Javascript
         */
        "enable_javascript" => true,

        /**
         * Whether to enable remote file access
         */
        "enable_remote" => true,

        /**
         * A ratio applied to the fonts. Set to 1 for
         * debugging or something.
         */
        "font_height_ratio" => 1.1,

        /**
         * Use the HTML5 Lib parser
         */
        "enable_html5_parser" => true,
    ],
];
