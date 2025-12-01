rule Image_Php_Polyglot_Webshell_v3_1
{
    meta:
        description         = "Detecta imágenes (JPG/PNG/GIF/WebP/AVIF/ICO) que contienen PHP con rasgos de webshell"
        author              = "Johan / Seguridad SaaS"
        last_modified       = "2025-11-26"
        version             = "3.1"
        severity            = "high"
        confidence          = 85
        false_positive_risk = "medium"

    strings:
        // Magic bytes de formatos soportados
        $jpeg_magic  = { FF D8 FF }
        $png_magic   = { 89 50 4E 47 0D 0A 1A 0A }
        $gif_magic1  = "GIF87a"
        $gif_magic2  = "GIF89a"
        $webp_magic  = { 52 49 46 46 ?? ?? ?? ?? 57 45 42 50 }
        $avif_ftyp1  = { ?? ?? ?? ?? 66 74 79 70 61 76 69 66 }
        $avif_ftyp2  = { ?? ?? ?? ?? 66 74 79 70 61 76 69 73 }
        $avif_ftyp3  = { ?? ?? ?? ?? 66 74 79 70 61 76 69 63 }
        $ico_magic   = { 00 00 01 00 } // ICO header

        // Aperturas PHP visibles y variantes script
        $php_open    = "<?php" nocase
        $php_short   = "<?=" nocase
        $php_script  = /<script\s+language\s*=\s*["']?php/i

        // PHP en base64
        $php_b64_open = /PD9waHA[A-Za-z0-9+\/]{3,}/
        $php_b64_echo = /PD89[A-Za-z0-9+\/]{10,}/

        // Funciones críticas / ejecución
        $fn_eval        = "eval(" nocase
        $fn_eval_split  = /e[\x00-\x20]{0,3}v[\x00-\x20]{0,3}a[\x00-\x20]{0,3}l[\x00-\x20]{0,3}\(/i
        $fn_assert      = "assert(" nocase
        $fn_shell_exec  = "shell_exec(" nocase
        $fn_system      = "system(" nocase
        $fn_exec        = "exec(" nocase
        $fn_passthru    = "passthru(" nocase
        $fn_proc_open   = "proc_open(" nocase
        $fn_preg_e      = /preg_replace\s*\([^)]{0,200}\/e/is
        $fn_create_func = "create_function" nocase
        $fn_call_user   = "call_user_func" nocase
        $fn_include     = "include(" nocase
        $fn_require     = "require(" nocase
        $fn_extract     = "extract(" nocase
        $fn_parse_str   = "parse_str(" nocase

        // Backticks y variantes
        $fn_backtick1 = "`$_" nocase
        $fn_backtick2 = "`{$_" nocase
        $fn_backtick3 = /`\s*\$_/i

        // Superglobales típicas
        $sg_post    = "$_POST" nocase
        $sg_get     = "$_GET" nocase
        $sg_request = "$_REQUEST" nocase
        $sg_files   = "$_FILES" nocase
        $sg_cookie  = "$_COOKIE" nocase
        $sg_server  = "$_SERVER" nocase

        // Operaciones de fichero
        $fn_file_put = "file_put_contents(" nocase
        $fn_move_upl = "move_uploaded_file(" nocase
        $fn_unlink   = "unlink(" nocase
        $fn_fwrite   = "fwrite(" nocase

        // Wrappers peligrosos / streams
        $wrapper_php    = "php://" nocase
        $wrapper_data   = "data://" nocase
        $wrapper_expect = "expect://" nocase
        $wrapper_input  = "php://input" nocase
        $wrapper_filter = "php://filter" nocase

        // PHAR indicators
        $phar_sig    = "__HALT_COMPILER();" nocase
        $phar_scheme = "phar://" nocase

        // Variable variables / concatenaciones sospechosas
        $var_var            = /\$\{['"_]+[A-Z_]+['"_]\s*\.\s*['"_]+[A-Z_]+['"_]\}/i
        $concat_suspicious  = /\$[a-z_][a-z0-9_]*\s*=\s*['"][a-z]{2,5}['"]\s*\.\s*['"][a-z]{2,5}['"]/i

        // PHP en comentarios base64 extendido
        $php_b64_inline = /PD9w[a-z0-9+\/]{2,}[\r\n]+/i

        // Upload wrappers
        $fn_include_wrapper = /(include|require)\s*\(\s*['"]?(php|data|expect|phar):\/\//i

    condition:
        // 1) El archivo aparenta ser una imagen conocida
        (
            $jpeg_magic  at 0 or
            $png_magic   at 0 or
            $gif_magic1  at 0 or
            $gif_magic2  at 0 or
            $webp_magic  at 0 or
            $avif_ftyp1  at 0 or
            $avif_ftyp2  at 0 or
            $avif_ftyp3  at 0 or
            $ico_magic   at 0
        )
        and filesize > 1024
        and filesize < 52428800 // < 50 MB
        // 2) PHP visible o codificado (evitar metadata inmediata)
        and
        (
            (
                $php_open and
                for any i in (1 .. #php_open) : ( @php_open[i] > 512 )
            )
            or
            (
                $php_short and
                for any i in (1 .. #php_short) : ( @php_short[i] > 512 )
            )
            or $php_script
            or $php_b64_open
            or $php_b64_echo
            or $php_b64_inline
        )
        // 3) Rasgos claros de shell
        and
        (
            // Función peligrosa + superglobal o wrapper
            (
                1 of (
                    $fn_eval,
                    $fn_eval_split,
                    $fn_assert,
                    $fn_shell_exec,
                    $fn_system,
                    $fn_exec,
                    $fn_passthru,
                    $fn_proc_open,
                    $fn_preg_e,
                    $fn_backtick1,
                    $fn_backtick2,
                    $fn_backtick3,
                    $fn_create_func,
                    $fn_call_user,
                    $fn_include,
                    $fn_require,
                    $fn_extract,
                    $fn_parse_str
                )
                and
                (
                    1 of (
                        $sg_post,
                        $sg_get,
                        $sg_request,
                        $sg_files,
                        $sg_cookie,
                        $sg_server
                    )
                    or
                    1 of (
                        $wrapper_php,
                        $wrapper_data,
                        $wrapper_expect,
                        $wrapper_input,
                        $wrapper_filter
                    )
                )
            )
            or
            // Función peligrosa + operación de fichero
            (
                1 of (
                    $fn_eval,
                    $fn_eval_split,
                    $fn_assert,
                    $fn_shell_exec,
                    $fn_system,
                    $fn_exec,
                    $fn_passthru,
                    $fn_proc_open,
                    $fn_preg_e,
                    $fn_backtick1,
                    $fn_backtick2,
                    $fn_backtick3,
                    $fn_create_func,
                    $fn_call_user
                )
                and
                1 of (
                    $fn_file_put,
                    $fn_move_upl,
                    $fn_unlink,
                    $fn_fwrite
                )
            )
            or
            // Include/require con wrapper o PHAR
            (
                1 of ($fn_include, $fn_require)
                and
                (
                    1 of (
                        $wrapper_php,
                        $wrapper_data,
                        $wrapper_expect,
                        $wrapper_input,
                        $wrapper_filter,
                        $phar_scheme
                    )
                    or $fn_include_wrapper
                )
            )
            or
            // PHAR embedded signature
            (
                $phar_sig and 1 of ($fn_include, $fn_require)
            )
            or
            // Variable variables / concatenación sospechosa + superglobal
            (
                (
                    $var_var
                    or $concat_suspicious
                )
                and
                1 of (
                    $sg_post,
                    $sg_get,
                    $sg_request,
                    $sg_files,
                    $sg_cookie
                )
            )
        )
}
