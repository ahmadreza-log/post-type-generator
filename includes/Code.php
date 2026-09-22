<?php
/**
 * PHP export for saved post types.
 *
 * The exporter prints the same argument array Registrar would pass to
 * `register_post_type()` on this request, including labels already translated
 * for the current locale. The result is a standalone file: it does not call
 * this plugin and does not depend on the text domain.
 *
 * Paste that file into another plugin, or into a theme `functions.php`, only
 * when Post Type Generator will be deactivated. Leaving both active registers
 * the key twice; the second call is ignored by WordPress.
 *
 * @package PostTypeGenerator
 */

declare(strict_types=1);

namespace PostTypeGenerator;

defined('ABSPATH') || exit;

final class Code
{
    /**
     * Wrap one or more types in an `init` callback.
     *
     * Rows that are not arrays are skipped so a bad export cannot fatal.
     *
     * @param array<string, array<string, mixed>> $types Definitions keyed by slug.
     * @return string PHP file contents, including the opening `<?php` tag.
     */
    public static function bundle(array $types): string
    {
        $blocks = [];

        foreach ($types as $type) {
            if (!is_array($type)) {
                continue;
            }

            $blocks[] = self::snippet($type);
        }

        $body = trim(implode("\n", $blocks));

        if ($body !== '') {
            $indented = preg_replace('/^/m', '    ', $body);
            $body = is_string($indented) ? $indented : $body;
        }

        return "<?php\n\nadd_action('init', function (): void {\n{$body}\n});\n";
    }

    /**
     * Print one `register_post_type()` call.
     *
     * @param array<string, mixed> $type Saved definition.
     * @return string PHP statement ending in a newline.
     */
    public static function snippet(array $type): string
    {
        $slug = (string) $type['slug'];

        return 'register_post_type(' . self::literal($slug) . ', ' . self::export(Registrar::args($type)) . ");\n";
    }

    /**
     * Render a PHP value with trailing commas and four-space indentation.
     *
     * Booleans become `true` or `false`. Integers stay unquoted. Everything
     * else is exported as a single-quoted PHP string so quotes and Persian text are safe.
     *
     * @param mixed $value Value stored in a register_post_type() argument.
     * @param int   $depth Indentation depth. Zero is the root array.
     * @return string PHP literal.
     */
    private static function export(mixed $value, int $depth = 0): string
    {
        if (!is_array($value)) {
            if (is_bool($value)) {
                return $value ? 'true' : 'false';
            }

            if (is_int($value)) {
                return (string) $value;
            }

            if ($value === null) {
                return 'null';
            }

            return self::literal((string) $value);
        }

        if ($value === []) {
            return '[]';
        }

        $listed = self::isList($value);
        $pad = str_repeat('    ', $depth + 1);
        $close = str_repeat('    ', $depth);
        $lines = [];

        foreach ($value as $key => $item) {
            $prefix = $listed ? '' : self::literal((string) $key) . ' => ';
            $lines[] = $pad . $prefix . self::export($item, $depth + 1);
        }

        return "[\n" . implode(",\n", $lines) . ",\n" . $close . ']';
    }

    /**
     * Quote a string the way a PHP source literal needs it.
     *
     * @param string $value Text to place inside single quotes.
     * @return string Single-quoted PHP string.
     */
    private static function literal(string $value): string
    {
        return "'" . strtr($value, [
            '\\' => '\\\\',
            "'" => "\\'",
        ]) . "'";
    }

    /**
     * Report whether an array uses consecutive keys starting at zero.
     *
     * A list is exported without keys. An associative array keeps quoted keys.
     *
     * @param array<mixed> $value Array to inspect.
     * @return bool True when the array is a list.
     */
    private static function isList(array $value): bool
    {
        foreach (array_keys($value) as $index => $key) {
            if ($key !== $index) {
                return false;
            }
        }

        return true;
    }
}
