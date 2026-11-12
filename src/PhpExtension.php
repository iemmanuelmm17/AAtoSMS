<?php

declare(strict_types=1);

namespace AAtoSMS;

/**
 * Finds out why a PHP extension that the application needs is not loaded.
 *
 * PHP itself only names the reason while it starts (on the command line: the
 * warnings above the output of "php -v"). Here the cause is worked out again
 * from php.ini and from the extension file, so that the installation check can
 * say what has to be changed.
 */
final class PhpExtension
{
    private const LABELS = ['curl' => 'cURL'];

    /**
     * What is known about the PHP that runs this script.
     *
     * @return array{version:string, sapi:string, windows:bool, thread_safe:bool, binary:string, ini:string,
     *               ini_files:list<string>, extension_dir:string, build:string, loaded:list<string>}
     */
    public static function environment(): array
    {
        $ini = php_ini_loaded_file();
        $files = is_string($ini) ? [$ini] : [];
        foreach (explode(',', (string) php_ini_scanned_files()) as $file) {
            if (trim($file) !== '') {
                $files[] = trim($file);
            }
        }

        return [
            'version' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'windows' => PHP_OS_FAMILY === 'Windows',
            'thread_safe' => (bool) PHP_ZTS,
            'binary' => PHP_BINARY,
            'ini' => is_string($ini) ? $ini : '',
            'ini_files' => $files,
            'extension_dir' => (string) ini_get('extension_dir'),
            'build' => self::ownBuild(),
            'loaded' => array_map('strtolower', get_loaded_extensions()),
        ];
    }

    /**
     * The build an extension file was made for, as PHP compares it when it loads
     * the file: "API20250925,TS,VS17" = PHP 8.5, thread safe, Visual Studio 17.
     * '' when the file cannot be read or holds no such mark.
     */
    public static function buildOf(string $file): string
    {
        $data = @file_get_contents($file, false, null, 0, 32 * 1024 * 1024);

        return is_string($data) && preg_match('/API[0-9]{8},N?TS(?:,[A-Za-z0-9._-]+)*/', $data, $match) === 1 ? $match[0] : '';
    }

    /**
     * Why the extension is not loaded and what to change.
     *
     * @param array{sapi:string, windows:bool, thread_safe:bool, binary:string, ini:string, ini_files:list<string>,
     *              extension_dir:string, build:string} $php see environment()
     * @return non-empty-list<string> the problem in one line, then the lines that explain it
     */
    public static function whyMissing(string $name, array $php): array
    {
        $label = self::LABELS[$name] ?? $name;
        $restart = $php['sapi'] === 'cli' ? [] : ['Restart the web server after the change.'];
        $switch = self::iniLine($name, $php['ini_files']);

        if ($switch === null) {
            if (!$php['windows']) {
                return [
                    sprintf('The %s extension is not installed or not switched on.', $label),
                    sprintf('Debian/Ubuntu: install the package php-%s. Elsewhere: add "extension=%s" to php.ini.', $name, $name),
                    ...$restart,
                ];
            }
            if ($php['ini'] === '') {
                return [
                    sprintf('The %s extension is not loaded: this PHP runs without a php.ini, so no extension is switched on.', $label),
                    'In the folder of PHP copy php.ini-production to php.ini. In that file remove the ";" in front of',
                    sprintf('  extension_dir = "ext"   and   extension=%s', $name),
                    ...$restart,
                ];
            }

            return [
                sprintf('The %s extension is not switched on.', $label),
                sprintf('In %s remove the ";" in front of "extension=%s" (or add that line).', $php['ini'], $name),
                ...$restart,
            ];
        }

        [$value, $iniFile] = $switch;
        $file = self::fileFor($name, $value, $php);
        if (!is_file($file)) {
            return [
                sprintf('The %s extension is not loaded: %s switches it on, but its file does not exist:', $label, $iniFile),
                '  ' . $file,
                '"extension_dir" in php.ini has to name the "ext" folder of the PHP that runs'
                    . ($php['extension_dir'] !== '' ? sprintf(' (now: %s).', $php['extension_dir']) : ' (now: not set).'),
                ...$restart,
            ];
        }

        $fileBuild = self::buildOf($file);
        $otherBuild = $fileBuild !== '' && ($php['build'] !== ''
            ? $fileBuild !== $php['build']
            : str_contains($fileBuild, ',NTS') === $php['thread_safe']);
        if (!$otherBuild) {
            if ($php['sapi'] === 'cli') {
                return [
                    sprintf('The %s extension is not loaded: PHP could not load %s', $label, $file),
                    'PHP names the reason when it starts: run "php -v" and read the warnings above the version.',
                ];
            }

            return [
                sprintf('The %s extension is not loaded: PHP could not load %s', $label, $file),
                'PHP names the reason when it starts: look in the error log of PHP or of the web server.',
                ...($php['windows'] ? [
                    'Often a file that it needs is not found: libssh2.dll, libssl-*.dll and libcrypto-*.dll are in the',
                    'folder of PHP, and that folder has to be in the PATH that the web server runs with.',
                ] : []),
                ...$restart,
            ];
        }

        $lines = [
            sprintf('The %s extension is not loaded: its file was made for another PHP than the one that runs.', $label),
            '  ' . $file . ' ... ' . $fileBuild,
            '  this PHP ' . str_repeat('.', max(3, strlen($file) - 5)) . ' '
                . ($php['build'] !== '' ? $php['build'] : ($php['thread_safe'] ? 'thread safe (TS)' : 'non thread safe (NTS)')),
            'The two have to be equal: the number stands for the PHP version, TS = thread safe, NTS = non thread safe.',
        ];
        if ($php['sapi'] !== 'cli' || $php['binary'] === '') {
            return [...$lines, 'The "ext" folder and the PHP that the web server runs must come from the same PHP download.', ...$restart];
        }

        $home = Config::nativePath(self::parent(self::absolute($php['extension_dir'], $php)));
        if (self::sameFolder($home, self::parent($php['binary']), $php['windows'])) {
            $lines[] = sprintf('The program and its "ext" folder are both in %s, so two PHP downloads are mixed there.', $home);
            $lines[] = 'Unpack one complete PHP download into that folder again (and keep php.ini). The Apache module';
            $lines[] = 'of PHP needs the "Thread Safe" download; IIS and FastCGI use the "Non Thread Safe" one.';
            if ($php['windows']) {
                $lines[] = 'Stop the web server and every running PHP first: Windows does not replace files that are in use.';
            }

            return $lines;
        }

        $other = $home . DIRECTORY_SEPARATOR . ($php['windows'] ? 'php.exe' : 'php');
        if (!is_file($other)) {
            return [...$lines, sprintf('"extension_dir" in php.ini names %s, which is not the "ext" folder of this PHP. Correct it.', $php['extension_dir'])];
        }
        $lines[] = sprintf('The extension files belong to the PHP in %s, not to the one that runs. Use that PHP:', $home);
        $lines[] = sprintf('  "%s" bin%scheck.php', $other, DIRECTORY_SEPARATOR);

        return $lines;
    }

    /**
     * The line of php.ini that switches the extension on: extension=curl,
     * extension=php_curl.dll or extension=C:\php\ext\php_curl.dll.
     *
     * @param list<string> $iniFiles
     * @return array{0:string, 1:string}|null the value of the line and the file it is in
     */
    private static function iniLine(string $name, array $iniFiles): ?array
    {
        foreach ($iniFiles as $iniFile) {
            $text = @file_get_contents($iniFile, false, null, 0, 1024 * 1024);
            foreach (preg_split('/\r\n|\n|\r/', is_string($text) ? $text : '') ?: [] as $line) {
                if (preg_match('/^\s*extension\s*=\s*["\']?([^"\';]+?)["\']?\s*(?:;.*)?$/i', $line, $match) !== 1) {
                    continue;
                }
                $base = strtolower(basename(str_replace('\\', '/', $match[1])));
                if (preg_replace('/^php_|\.(?:dll|so)$/', '', $base) === $name) {
                    return [$match[1], $iniFile];
                }
            }
        }

        return null;
    }

    /**
     * The file PHP loads for a php.ini value, found the way PHP looks for it.
     *
     * @param array{windows:bool, binary:string, extension_dir:string} $php
     */
    private static function fileFor(string $name, string $value, array $php): string
    {
        if (preg_match('~[/\\\\]~', $value) === 1) {
            return $value; // a full path in php.ini
        }
        $folder = self::absolute($php['extension_dir'], $php) . DIRECTORY_SEPARATOR;
        $standard = $folder . ($php['windows'] ? 'php_' . $name . '.dll' : $name . '.so');

        return is_file($folder . $value) ? $folder . $value : $standard;
    }

    /**
     * A relative extension_dir ("ext") starts at the folder of the program.
     *
     * @param array{binary:string} $php
     */
    private static function absolute(string $folder, array $php): string
    {
        return Config::nativePath($php['binary'] === '' || $folder === '' ? $folder : Config::resolvePath($folder, self::parent($php['binary'])));
    }

    /** The folder a file is in, whichever kind of slash its path is written with. */
    private static function parent(string $path): string
    {
        return dirname(str_replace('\\', '/', $path));
    }

    private static function sameFolder(string $a, string $b, bool $windows): bool
    {
        $clean = static function (string $path) use ($windows): string {
            $path = rtrim(str_replace('\\', '/', $path), '/');

            return $windows ? strtolower($path) : $path;
        };

        return $clean($a) === $clean($b);
    }

    /** "API20250925,NTS,VS17" for the running PHP; '' when phpinfo() is not available. */
    private static function ownBuild(): string
    {
        if (!function_exists('phpinfo')) {
            return '';
        }
        ob_start();
        @phpinfo(INFO_GENERAL);
        $text = strip_tags((string) ob_get_clean());

        return preg_match('/PHP Extension Build\s*(?:=>)?\s*(API[0-9]{8},N?TS(?:,[A-Za-z0-9._-]+)*)/', $text, $match) === 1 ? $match[1] : '';
    }
}
