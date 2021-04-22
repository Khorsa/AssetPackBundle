<?php

namespace flexycms\AssetPackBundle\Utils;

class AssetPack
{
    /**
     * @param string $scssPath
     * @return string
     * @throws \ScssPhp\ScssPhp\Exception\CompilerException
     */
    public function compileSCSS(string $scssPath): string
    {
        $scssFile = $_SERVER['DOCUMENT_ROOT'] . $scssPath;

        //Если файла нет или если он не scss, возвращаем исходный - будет проще отследить или проигнорировать ошибку
        if (!file_exists($scssFile)) return $scssPath;
        $path_parts = pathinfo($scssFile);
        if ($path_parts['extension'] != 'scss') return $scssPath;

        $nn = substr($path_parts['basename'], 0, -5);

        $newName = $path_parts['dirname'] . '/' . $nn . '.css';
        $newPath = substr($newName, strlen($_SERVER['DOCUMENT_ROOT']));

        if (is_file($newName)) unlink($newName);

        try {
            $scss = new \ScssPhp\ScssPhp\Compiler();
            $scss->addImportPath(dirname($scssFile));
            $scssContent = file_get_contents($scssFile);
            $style = $scss->compile($scssContent);
            file_put_contents($newName, $style);
        } catch (\Exception $ex) {
            return $scssPath;
        }
        return $newPath;
    }

    /**
     * @param string $stylePath
     * @return string
     */
    public function minimizeCSS(string $stylePath): string
    {
        $styleFile = $_SERVER['DOCUMENT_ROOT'] . $stylePath;

        //Если файла нет или если он не css, возвращаем исходный - будет проще отследить или проигнорировать ошибку
        if (!file_exists($styleFile)) return $stylePath;
        $path_parts = pathinfo($styleFile);
        if ($path_parts['extension'] != 'scss') return $stylePath;

        $nn = substr($path_parts['basename'], 0, -4);
        $newName = $path_parts['dirname'] . '/' . $nn . '.min.css';
        $newPath = substr($newName, strlen($_SERVER['DOCUMENT_ROOT']));

        if (file_exists($newName)) unlink($newName);

        $replace = array(
            "\r\n" => "\n",
            "\n\n" => "\n",

            "\t" => " ",
            "  " => " ",

            "\n " => "\n",
            " \n" => "\n",
            " {" => "{",
            "{ " => "{",
            " }" => "}",
            "} " => "}",

            "\n{" => "{",
            "{\n" => "{",
            "\n}" => "}",
            ",\n" => ",",
            ", " => ",",
            ";\n" => ";",

            ": " => ":",
            " :" => ":",
            "'" => "\"",
        );

        $style = file_get_contents($styleFile);
        $style = preg_replace( '!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $style);

        $search = array_keys( $replace );
        $values = array_values( $replace );

        $tries = 0;
        do {
            $counter = 0;
            $style = str_replace( $search, $values, $style, $counter );
            $tries++;
        } while (($counter != 0) && ($tries < 100));

        //Проверим, нет ли первой строки-переноса (может оставаться после удаления начальных комментариев)
        if (strpos($style, "\n") === 0) $style = substr($style, 1);

        file_put_contents($newName, $style);

        return $newPath;
    }



    public static function minimizeJS($scriptsPath)
    {
        $scriptsFile = $_SERVER['DOCUMENT_ROOT'] . $scriptsPath;
        $path_parts = pathinfo($scriptsFile);
        if (file_exists($scriptsFile) && $path_parts['extension'] == 'js')
        {

            //Добавляем подчерк, чтобы отделить сгенерированные скрипты от исходных
            $nn = substr($path_parts['basename'], 0, -3);
            if (strpos($nn, '_') !== 0) $nn = '_'.$nn;

            $newName = $path_parts['dirname'] . '/' . $nn . '.min.js';
            $newPath = substr($newName, strlen($_SERVER['DOCUMENT_ROOT']));

            if (file_exists($newName))
            {
                if (self::$forceJSProcessing)
                    unlink($newName);
                else
                    return $newPath;
            }

            $replace = array(
                '#\'([^\n\']*?)/\*([^\n\']*)\'#' => "'\1/'+\'\'+'*\2'", // remove comments from ' strings
                '#\"([^\n\"]*?)/\*([^\n\"]*)\"#' => '"\1/"+\'\'+"*\2"', // remove comments from " strings
                '#/\*.*?\*/#s'            => "",      // strip C style comments
                '#[\r\n]+#'               => "\n",    // remove blank lines and \r's
                '#\n([ \t]*//.*?\n)*#s'   => "\n",    // strip line comments (whole line only)
                '#([^\\])//([^\'"\n]*)\n#s' => "\\1\n",
                // strip line comments
                // (that aren't possibly in strings or regex's)
                '#\n\s+#'                 => "\n",    // strip excess whitespace
                '#\s+\n#'                 => "\n",    // strip excess whitespace
                '#(//[^\n]*\n)#s'         => "\\1\n", // extra line feed after any comments left
                // (important given later replacements)
                '#/([\'"])\+\'\'\+([\'"])\*#' => "/*" // restore comments in strings
            );

            $scripts = file_get_contents($scriptsFile);

            $search = array_keys( $replace );
            $scripts = preg_replace( $search, $replace, $scripts );

            $replace = array(

                "\r\n" => "\n",
                "\n\n" => "\n",
                "\t" => " ",
                "  " => " ",
                "( " => "(",
                " (" => "(",
                ") " => ")",
                " )" => ")",
                ": " => ":",
                " :" => ":",

                "\n " => "\n",
                " \n" => "\n",
            );

            $search = array_keys( $replace );
            $tries = 0;
            do
            {
                $counter = 0;
                $scripts = str_replace( $search, $replace, $scripts, $counter );
                $tries++;
            } while (($counter != 0) || ($tries < 20));

            file_put_contents($newName, $scripts);

            return $newPath;
        }
        //Если файла нет или если он не js, возвращаем исходный - будет проще отследить или проигнорировать ошибку
        return $scriptsPath;
    }


    public function processJs($scriptArray, $packedScripts): int
    {
        //Проверяем дату модификации файлов
        if (is_file($_SERVER["DOCUMENT_ROOT"] . $packedScripts))
        {
            $packedMTime = filemtime($_SERVER['DOCUMENT_ROOT'] . $packedScripts);
            $modified = false;
            foreach ($scriptArray as $scriptFile) {
                if (!is_file($_SERVER["DOCUMENT_ROOT"] . $scriptFile)) continue;
                $mtime = filemtime($_SERVER['DOCUMENT_ROOT'] . $scriptFile);
                if ($mtime > $packedMTime) $modified = true;
            }
            if (!$modified) return $packedMTime; // Файл up-to-date, ничего не делаем

            unlink($_SERVER["DOCUMENT_ROOT"] . $packedScripts);
        }

        //Собираем файлы
        $combinedJs = '';
        foreach($scriptArray as $scriptFile) {
            if (!is_file($_SERVER["DOCUMENT_ROOT"] . $scriptFile)) continue;
            $js = file_get_contents($_SERVER["DOCUMENT_ROOT"] . $scriptFile);
            $combinedJs .= $js . "\n";
        }

        //Пакуем
        $replace = array(

            "\r\n" => "\n",
            "\n\n" => "\n",
            "\t" => " ",
            "  " => " ",
            "( " => "(",
            " (" => "(",
            ") " => ")",
            " )" => ")",
            ": " => ":",
            " :" => ":",

            "\n " => "\n",
            " \n" => "\n",
        );

        $search = array_keys( $replace );
        $tries = 0;
        do {
            $counter = 0;
            $combinedJs = str_replace( $search, $replace, $combinedJs, $counter );
            $tries++;
        } while (($counter != 0) || ($tries < 20));

        file_put_contents($_SERVER["DOCUMENT_ROOT"] . $packedScripts, $combinedJs, LOCK_EX);

        $packedMTime = filemtime($_SERVER['DOCUMENT_ROOT'] . $packedScripts);
        return $packedMTime;
    }
}