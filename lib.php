<?php
/*
lib_id: djebel-lib-qr
lib_name: Djebel QR
version: 1.0.0
description: Draws a text as a QR code (Djebel_Lib_Qr), in SVG unless asked otherwise, with an optional word in its middle. The package that builds the code is read only when a code is drawn.
min_php_ver: 7.4
*/

// Not a plugin: it registers no hooks. It applies one filter, on the answer it is about to return.
// A pre-defined / custom implementation wins: if the class already exists, don't redeclare.
if (class_exists('Djebel_Lib_Qr')) {
    return;
}

class Djebel_Lib_Qr {
    // What generate() can give back, picked with its 'type' param. The first one is the default.
    const TYPE_SVG = 'svg';

    // Where the two packages sit, relative to this file, each in the folder its author released.
    const PACKAGES_DIR = 'shared/qr-lib';

    // Namespace prefix => the folder its classes are in, relative to PACKAGES_DIR.
    const PACKAGE_NAMESPACE_DIRS = [
        'chillerlan\\QRCode\\' => 'php-qrcode/src',
        'chillerlan\\Settings\\' => 'php-settings-container/src',
    ];

    // The most bytes the largest QR code holds at the error-correction level used here.
    const MAX_TEXT_LENGTH = 1273;

    // White squares around the code. Four is what the QR standard asks for and scanners expect.
    const QUIET_ZONE_SIZE = 4;

    // The space cleared for the word, as a share of the code's side. Together they cover about
    // 7% of the code, far under the 30% this error-correction level restores.
    const LABEL_WIDTH_RATIO = 0.52;
    const LABEL_HEIGHT_RATIO = 0.14;

    // The letters' size as a share of the cleared space's height, which leaves room above and below.
    const LABEL_FONT_RATIO = 0.7;

    // The letters that fit the cleared space at that size. A longer word would be cut at its edges.
    const MAX_LABEL_LENGTH = 10;

    // The longest text that was scanned back with a word in its middle. The largest code stopped
    // scanning once the word was in, so a text past this is refused when a word is asked for.
    const MAX_LABELED_TEXT_LENGTH = 300;

    // sprintf() template of the word. It is its own nested <svg>, the size of the cleared space,
    // because a nested <svg> cuts off what does not fit: letters can never cover the code's squares.
    // Takes: x, y, width, height (in squares), font size, the escaped word.
    const LABEL_TPL = "\n<svg x='%.1F' y='%.1F' width='%d' height='%d'>\n<text x='50%%' y='50%%' dy='0.35em' font-family='Arial, Helvetica, sans-serif' font-weight='bold' font-size='%.2F' text-anchor='middle' fill='#000'>%s</text>\n</svg>\n";

    /**
     * The one shared instance. The library keeps no state between calls, so one is enough.
     * @return Djebel_Lib_Qr
     */
    public static function getInstance() {
        static $instance = null;

        if (is_null($instance)) {
            $instance = new static();
        }

        return $instance;
    }

    /**
     * Draws a text as a QR code and returns it in the type asked for: SVG markup when none is.
     * The markup has a viewBox and no fixed size, so it scales to any screen or print size. A
     * label is written into a space cleared in the middle of the code.
     *
     * $qr_obj = Djebel_Lib_Qr::getInstance();
     * $res_obj = $qr_obj->generate([ 'text' => 'https://example.com/v/AB1234CD', 'label' => 'Example', ]);
     * echo $res_obj->output;
     *
     * @param array $params text  (required) what the code holds, MAX_TEXT_LENGTH bytes at most,
     *                            and MAX_LABELED_TEXT_LENGTH at most when a label is given;
     *                      label (optional) a short word, MAX_LABEL_LENGTH characters at most;
     *                      type  (optional) what comes back, one of the TYPE_* constants;
     *                            TYPE_SVG when left out.
     * @return Dj_App_Result output (string, empty when it failed): the code, in the type asked
     *                       for. On failure code says why and msg is safe to show.
     */
    public function generate($params = []) {
        $res_obj = new Dj_App_Result();
        $res_obj->output = '';

        $ctx = [
            'params' => $params,
        ];

        try {
            $type = Dj_App_Util::getField('type', $params, self::TYPE_SVG);

            // Strict on purpose: on PHP 7.4 a loose compare reads the number 0 as equal to any word.
            if ($type !== self::TYPE_SVG) {
                throw new Dj_App_Validation_Exception('This type of QR code output is not supported.', [
                    'code' => 'qr.type_unsupported',
                    'type' => $type,
                ]);
            }

            $text = Dj_App_Util::getField('text', $params);

            // A text is a string or a number; an array or an object is read as no text at all.
            if (!is_scalar($text)) {
                $text = '';
            }

            $text = Dj_App_String_Util::trim($text);

            // strlen() reads a stored length, so a missing or oversized text is refused here,
            // before any of the package's files are read.
            $text_length = strlen($text);

            if ($text_length == 0) {
                throw new Dj_App_Validation_Exception('The text for the QR code is missing.', [ 'code' => 'qr.text_missing', ]);
            }

            if ($text_length > self::MAX_TEXT_LENGTH) {
                throw new Dj_App_Validation_Exception('The text is too long for a QR code.', [
                    'code' => 'qr.text_too_long',
                    'text_length' => $text_length,
                    'max_text_length' => self::MAX_TEXT_LENGTH,
                ]);
            }

            $label = Dj_App_Util::getField('label', $params);

            if (!is_scalar($label)) {
                $label = '';
            }

            $label = Dj_App_String_Util::trim($label);

            // Counted in characters: a Cyrillic word is as long as its letters, not its bytes.
            $label_length = mb_strlen($label, 'UTF-8');

            if ($label_length > self::MAX_LABEL_LENGTH) {
                throw new Dj_App_Validation_Exception('The word in the middle of the QR code is too long.', [
                    'code' => 'qr.label_too_long',
                    'label_length' => $label_length,
                    'max_label_length' => self::MAX_LABEL_LENGTH,
                ]);
            }

            // A word that is not valid UTF-8 would be escaped to nothing and leave an empty box.
            if (!mb_check_encoding($label, 'UTF-8')) {
                throw new Dj_App_Validation_Exception('The word in the middle of the QR code is not valid text.', [ 'code' => 'qr.label_invalid', ]);
            }

            if ($label_length > 0 && $text_length > self::MAX_LABELED_TEXT_LENGTH) {
                throw new Dj_App_Validation_Exception('The text is too long for a QR code with a word in the middle.', [
                    'code' => 'qr.labeled_text_too_long',
                    'text_length' => $text_length,
                    'max_labeled_text_length' => self::MAX_LABELED_TEXT_LENGTH,
                ]);
            }

            // The loader is registered here, on the first code drawn, so a request that draws
            // none never reads the package's files. Registering it again changes nothing.
            $is_loader_registered = spl_autoload_register([$this, 'loadPackageClass']);

            if (empty($is_loader_registered) || !class_exists('chillerlan\\QRCode\\QRCode')) {
                $packages_dir = __DIR__ . '/' . self::PACKAGES_DIR;

                throw new Dj_App_Exception('The QR code package is missing.', [
                    'code' => 'qr.package_missing',
                    'packages_dir' => $packages_dir,
                ]);
            }

            // The highest error-correction level: a code stays readable with up to 30% of it
            // covered, which is what lets a word sit in its middle. The markup comes back as
            // markup, not as a base64 data address, and without an XML header, since it goes
            // inside a page.
            $qr_options = [
                'eccLevel' => \chillerlan\QRCode\Common\EccLevel::H,
                'quietzoneSize' => self::QUIET_ZONE_SIZE,
                'outputBase64' => false,
                'svgAddXmlHeader' => false,
            ];

            $options_obj = new \chillerlan\QRCode\QROptions($qr_options);
            $qr_obj = new \chillerlan\QRCode\QRCode($options_obj);
            $qr_obj = $qr_obj->addByteSegment($text);
            $matrix_obj = $qr_obj->getQRMatrix();
            $label_svg = '';

            if ($label_length > 0) {
                $matrix_size = $matrix_obj->getSize();
                $code_size = $matrix_size - 2 * self::QUIET_ZONE_SIZE;
                $label_width = (int) round($code_size * self::LABEL_WIDTH_RATIO);
                $label_height = (int) round($code_size * self::LABEL_HEIGHT_RATIO);
                $matrix_obj = $matrix_obj->setLogoSpace($label_width, $label_height);

                // The word's box is centered in the whole drawing, quiet zone included.
                $label_x = ($matrix_size - $label_width) / 2;
                $label_y = ($matrix_size - $label_height) / 2;
                $font_size = $label_height * self::LABEL_FONT_RATIO;
                $label_esc = Dj_App_HTML::escHtml($label);
                $label_svg = sprintf(self::LABEL_TPL, $label_x, $label_y, $label_width, $label_height, $font_size, $label_esc);
            }

            $svg = $qr_obj->renderMatrix($matrix_obj);

            if (!empty($label_svg)) {
                // Before the closing tag, so the word is drawn last, on top of the cleared space.
                $svg_end_pos = strrpos($svg, '</svg>');

                if ($svg_end_pos === false) {
                    throw new Dj_App_Exception('The QR code markup has no closing tag.', [ 'code' => 'qr.draw_failed', ]);
                }

                $svg = substr_replace($svg, $label_svg, $svg_end_pos, 0);
            }

            $res_obj->output = $svg;
            $res_obj->status = true;
        } catch (Dj_App_Validation_Exception $e) {
            $res_obj->code = $e->getErrorCode();
            $res_obj->msg = $e->getMessage();
        } catch (Exception $e) {
            // The package's own exceptions land here too, and they carry no code.
            $error_code = ($e instanceof Dj_App_Exception) ? $e->getErrorCode() : '';
            $error_code = empty($error_code) ? 'qr.draw_failed' : $error_code;
            $res_obj->code = $error_code;
            $res_obj->msg = 'The QR code could not be drawn.';

            $error_message = $e->getMessage();
            $error_class = get_class($e);

            // The text is left out on purpose: a code can hold a password as easily as an address.
            $log_data = [
                'code' => $error_code,
                'error' => $error_message,
                'error_class' => $error_class,
            ];

            Dj_App_Log::error($log_data, __METHOD__);
        }

        // A listener can change or replace the answer before it reaches the caller.
        $res_obj = Dj_App_Hooks::applyFilter('app.lib.qr.filter.output', $res_obj, $ctx);

        return $res_obj;
    }

    /**
     * Class loader for the two packages under PACKAGES_DIR: turns one of their class names into
     * its file and reads it. Any other class name is left to the other loaders.
     *
     * A class's file is its name after the namespace prefix, with the separators as folders:
     * chillerlan\QRCode\Data\QRMatrix is Data/QRMatrix under php-qrcode/src.
     *
     * @param string $class_name
     * @return bool true when the class's file was read
     */
    public function loadPackageClass($class_name) {
        foreach (self::PACKAGE_NAMESPACE_DIRS as $namespace_prefix => $src_dir) {
            if (strpos($class_name, $namespace_prefix) !== 0) {
                continue;
            }

            $prefix_length = strlen($namespace_prefix);
            $relative_class = substr($class_name, $prefix_length);

            // Letters, digits, _ and the namespace separator only: Data\QRMatrix passes and
            // ..\..\secret does not, so a made-up class name cannot point at a file outside
            // the package.
            if (!preg_match('/^[\w\\\\]+$/', $relative_class)) {
                return false;
            }

            $relative_file = str_replace('\\', '/', $relative_class);
            $class_file = __DIR__ . '/' . self::PACKAGES_DIR . '/' . $src_dir . '/' . $relative_file . '.php';

            if (!is_file($class_file)) {
                return false;
            }

            require_once $class_file;

            return true;
        }

        return false;
    }
}
