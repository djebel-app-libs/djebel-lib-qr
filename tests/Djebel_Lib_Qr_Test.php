<?php

use PHPUnit\Framework\TestCase;

class Djebel_Lib_Qr_Test extends TestCase {
    const SAMPLE_URL = 'https://app.example.com/v/AB1234CD';

    /**
     * Listener for the answer filter: marks the answer and copies the text it was told about,
     * so a test can see both that it ran and what it was handed.
     * @param Dj_App_Result $res_obj
     * @param array $ctx
     * @return Dj_App_Result
     */
    public static function filterAnswer($res_obj, $ctx = []) {
        $res_obj->output = 'changed by the listener';
        $res_obj->listener_text = $ctx['params']['text'];

        return $res_obj;
    }

    /**
     * Pins the shape a page relies on: plain markup with a viewBox, no XML header, not a
     * base64 data address, and no word when none was asked for.
     */
    public function testGenerateDrawsACode() {
        $qr_obj = Djebel_Lib_Qr::getInstance();
        $res_obj = $qr_obj->generate([ 'text' => self::SAMPLE_URL, ]);

        $this->assertTrue($res_obj->isSuccess(), 'an address is drawn');
        $this->assertEmpty($res_obj->code, 'a drawn code carries no error code');

        $svg = $res_obj->output;

        $this->assertStringStartsWith('<svg', $svg, 'the answer is the markup itself');
        $this->assertStringContainsString('viewBox=', $svg, 'it scales by its viewBox');
        $this->assertStringContainsString('</svg>', $svg, 'the markup is closed');
        $this->assertStringNotContainsString('<?xml', $svg, 'no XML header: it goes inside a page');
        $this->assertStringNotContainsString('data:image', $svg, 'not a base64 data address');
        $this->assertStringNotContainsString('<text', $svg, 'no word was asked for');
    }

    /**
     * Pins that the type is one optional argument: left out or empty it means SVG, named it
     * gives the same answer, and the code always comes back in the same field.
     */
    public function testGenerateDefaultsToSvg() {
        $qr_obj = Djebel_Lib_Qr::getInstance();
        $default_res_obj = $qr_obj->generate([ 'text' => self::SAMPLE_URL, ]);

        $this->assertStringStartsWith('<svg', $default_res_obj->output, 'with no type the answer is SVG');

        $svg_res_obj = $qr_obj->generate([ 'text' => self::SAMPLE_URL, 'type' => Djebel_Lib_Qr::TYPE_SVG, ]);

        $this->assertSame($default_res_obj->output, $svg_res_obj->output, 'naming the SVG type gives the same answer');

        $empty_type_res_obj = $qr_obj->generate([ 'text' => self::SAMPLE_URL, 'type' => '', ]);

        $this->assertSame($default_res_obj->output, $empty_type_res_obj->output, 'an empty type means the default');
    }

    /**
     * Pins that a type the library cannot give is refused by name, never answered with SVG:
     * a caller that asked for a picture must not be handed markup as if it were one.
     */
    public function testGenerateRefusesAnUnknownType() {
        $qr_obj = Djebel_Lib_Qr::getInstance();

        $unknown_type_cases = [
            'a type that does not exist' => 'no_such_type',
            'a type that is the number 0' => 0,
            'a type that is an array' => [ Djebel_Lib_Qr::TYPE_SVG, ],
        ];

        foreach ($unknown_type_cases as $case_name => $type) {
            $res_obj = $qr_obj->generate([ 'text' => self::SAMPLE_URL, 'type' => $type, ]);

            $this->assertTrue($res_obj->isError(), $case_name . ' is refused');
            $this->assertSame('qr.type_unsupported', $res_obj->code, $case_name . ' names the reason');
            $this->assertEmpty($res_obj->output, $case_name . ' draws nothing');
        }
    }

    /**
     * Pins that one text always gives one drawing: a printed sticker and a later download of
     * the same vehicle must be the same code. Spaces around the text are not part of it.
     */
    public function testGenerateGivesTheSameCodeForTheSameText() {
        $qr_obj = Djebel_Lib_Qr::getInstance();
        $first_res_obj = $qr_obj->generate([ 'text' => self::SAMPLE_URL, ]);

        $this->assertNotEmpty($first_res_obj->output, 'the first draw gave markup');

        $second_res_obj = $qr_obj->generate([ 'text' => self::SAMPLE_URL, ]);

        $this->assertSame($first_res_obj->output, $second_res_obj->output, 'the same text draws the same code');

        $padded_res_obj = $qr_obj->generate([ 'text' => '  ' . self::SAMPLE_URL . "\n", ]);

        $this->assertSame($first_res_obj->output, $padded_res_obj->output, 'spaces around the text change nothing');

        $other_res_obj = $qr_obj->generate([ 'text' => self::SAMPLE_URL . '9', ]);

        $this->assertNotSame($first_res_obj->output, $other_res_obj->output, 'another text draws another code');
    }

    /**
     * Pins where the word lands: inside the drawing, in its own nested box, before the closing
     * tag, so it is drawn on top of the space cleared for it.
     */
    public function testGenerateWritesTheLabel() {
        $qr_obj = Djebel_Lib_Qr::getInstance();
        $res_obj = $qr_obj->generate([ 'text' => self::SAMPLE_URL, 'label' => 'KolataTi', ]);

        $this->assertTrue($res_obj->isSuccess(), 'a code with a word is drawn');

        $svg = $res_obj->output;

        $this->assertStringContainsString('>KolataTi</text>', $svg, 'the word is written');
        $this->assertStringContainsString("<svg x='", $svg, 'the word sits in its own nested box');

        $label_pos = strpos($svg, '<text');
        $svg_end_pos = strrpos($svg, '</svg>');

        $this->assertLessThan($svg_end_pos, $label_pos, 'the word is inside the drawing');

        $plain_res_obj = $qr_obj->generate([ 'text' => self::SAMPLE_URL, ]);
        $label_start_pos = strpos($svg, "\n<svg x='");
        $squares_with_label = substr($svg, 0, $label_start_pos);

        $this->assertStringStartsNotWith($squares_with_label, $plain_res_obj->output, 'the squares under the word were cleared');
    }

    /**
     * Pins that a word cannot carry markup into the page that shows the code.
     */
    public function testGenerateEscapesTheLabel() {
        $qr_obj = Djebel_Lib_Qr::getInstance();
        $res_obj = $qr_obj->generate([ 'text' => self::SAMPLE_URL, 'label' => '<b>&"\'', ]);

        $this->assertTrue($res_obj->isSuccess(), 'a word with special characters is drawn');
        $this->assertStringContainsString('>&lt;b&gt;&amp;&quot;&#039;</text>', $res_obj->output, 'the word is escaped');
        $this->assertStringNotContainsString('<b>', $res_obj->output, 'no tag from the word reaches the markup');
    }

    /**
     * Pins that a word is measured in letters: ten Cyrillic letters are twenty bytes and still
     * fit, and the markup stays valid UTF-8.
     */
    public function testGenerateKeepsACyrillicLabelWhole() {
        $qr_obj = Djebel_Lib_Qr::getInstance();
        $res_obj = $qr_obj->generate([ 'text' => self::SAMPLE_URL, 'label' => 'КолатаТиБГ', ]);

        $this->assertTrue($res_obj->isSuccess(), 'ten Cyrillic letters fit');
        $this->assertStringContainsString('>КолатаТиБГ</text>', $res_obj->output, 'the word is whole');

        $is_utf8 = mb_check_encoding($res_obj->output, 'UTF-8');

        $this->assertNotFalse($is_utf8, 'the markup is valid UTF-8');

        $text_res_obj = $qr_obj->generate([ 'text' => 'Кола № 5', ]);

        $this->assertTrue($text_res_obj->isSuccess(), 'a Cyrillic text is drawn');
    }

    /**
     * Pins that nothing is drawn, and the reason is named, when there is no text to draw.
     */
    public function testGenerateRefusesAMissingText() {
        $qr_obj = Djebel_Lib_Qr::getInstance();

        $missing_text_cases = [
            'no params at all' => [],
            'an empty text' => [ 'text' => '', ],
            'a text of spaces' => [ 'text' => "  \t\n", ],
            'a text that is an array' => [ 'text' => [ 'a', 'b', ], ],
        ];

        foreach ($missing_text_cases as $case_name => $params) {
            $res_obj = $qr_obj->generate($params);

            $this->assertTrue($res_obj->isError(), $case_name . ' is refused');
            $this->assertSame('qr.text_missing', $res_obj->code, $case_name . ' names the reason');
            $this->assertNotEmpty($res_obj->msg, $case_name . ' says it in words');
            $this->assertEmpty($res_obj->output, $case_name . ' draws nothing');
        }
    }

    /**
     * Pins that a text of "0" is a text: an emptiness check that reads it as missing would
     * refuse a real value.
     */
    public function testGenerateTakesATextOfZero() {
        $qr_obj = Djebel_Lib_Qr::getInstance();
        $res_obj = $qr_obj->generate([ 'text' => '0', ]);

        $this->assertTrue($res_obj->isSuccess(), 'a text of 0 is drawn');
        $this->assertNotEmpty($res_obj->output, 'it gave markup');
    }

    /**
     * Pins the size limit on both sides: the longest text a code holds is drawn, one byte more
     * is refused with its own reason.
     */
    public function testGenerateRefusesATextOverTheLimit() {
        $qr_obj = Djebel_Lib_Qr::getInstance();
        $longest_text = str_repeat('x', Djebel_Lib_Qr::MAX_TEXT_LENGTH);
        $longest_res_obj = $qr_obj->generate([ 'text' => $longest_text, ]);

        $this->assertTrue($longest_res_obj->isSuccess(), 'a text at the limit is drawn');

        $too_long_text = $longest_text . 'x';
        $too_long_res_obj = $qr_obj->generate([ 'text' => $too_long_text, ]);

        $this->assertTrue($too_long_res_obj->isError(), 'one byte over the limit is refused');
        $this->assertSame('qr.text_too_long', $too_long_res_obj->code, 'the reason is the length');
        $this->assertEmpty($too_long_res_obj->output, 'nothing is drawn');
    }

    /**
     * Pins the word's length limit, counted in letters for Latin and Cyrillic alike.
     */
    public function testGenerateRefusesALabelOverTheLimit() {
        $qr_obj = Djebel_Lib_Qr::getInstance();
        $longest_label = str_repeat('a', Djebel_Lib_Qr::MAX_LABEL_LENGTH);
        $longest_res_obj = $qr_obj->generate([ 'text' => self::SAMPLE_URL, 'label' => $longest_label, ]);

        $this->assertTrue($longest_res_obj->isSuccess(), 'a word at the limit is drawn');

        $too_long_label = $longest_label . 'a';
        $too_long_res_obj = $qr_obj->generate([ 'text' => self::SAMPLE_URL, 'label' => $too_long_label, ]);

        $this->assertSame('qr.label_too_long', $too_long_res_obj->code, 'one letter over the limit is refused');
        $this->assertEmpty($too_long_res_obj->output, 'nothing is drawn');

        $too_long_cyrillic_label = str_repeat('я', Djebel_Lib_Qr::MAX_LABEL_LENGTH + 1);
        $cyrillic_res_obj = $qr_obj->generate([ 'text' => self::SAMPLE_URL, 'label' => $too_long_cyrillic_label, ]);

        $this->assertSame('qr.label_too_long', $cyrillic_res_obj->code, 'Cyrillic letters are counted the same way');
    }

    /**
     * Pins that a word that is not valid UTF-8 is refused: escaped, it would come out empty
     * and leave a blank box in a code reported as drawn.
     */
    public function testGenerateRefusesALabelThatIsNotUtf8() {
        $qr_obj = Djebel_Lib_Qr::getInstance();
        $res_obj = $qr_obj->generate([ 'text' => self::SAMPLE_URL, 'label' => "Kol\xD0", ]);

        $this->assertTrue($res_obj->isError(), 'a broken word is refused');
        $this->assertSame('qr.label_invalid', $res_obj->code, 'the reason is the word');
        $this->assertEmpty($res_obj->output, 'nothing is drawn');
    }

    /**
     * Pins the limit that keeps a code with a word scannable: past it the word is refused,
     * while the same text without a word is still drawn.
     */
    public function testGenerateRefusesALongTextWithALabel() {
        $qr_obj = Djebel_Lib_Qr::getInstance();
        $longest_text = str_repeat('x', Djebel_Lib_Qr::MAX_LABELED_TEXT_LENGTH);
        $longest_res_obj = $qr_obj->generate([ 'text' => $longest_text, 'label' => 'KolataTi', ]);

        $this->assertTrue($longest_res_obj->isSuccess(), 'a text at the limit takes a word');

        $too_long_text = $longest_text . 'x';
        $too_long_res_obj = $qr_obj->generate([ 'text' => $too_long_text, 'label' => 'KolataTi', ]);

        $this->assertSame('qr.labeled_text_too_long', $too_long_res_obj->code, 'one byte more and the word is refused');
        $this->assertEmpty($too_long_res_obj->output, 'nothing is drawn');

        $plain_res_obj = $qr_obj->generate([ 'text' => $too_long_text, ]);

        $this->assertTrue($plain_res_obj->isSuccess(), 'the same text without a word is drawn');
    }

    /**
     * Pins the seam other code extends the library through: the answer goes through the filter
     * last, and the listener is told what was asked for.
     */
    public function testGeneratePassesItsAnswerThroughTheFilter() {
        $qr_obj = Djebel_Lib_Qr::getInstance();
        $is_removed = false;

        try {
            Dj_App_Hooks::addFilter('app.lib.qr.filter.output', ['Djebel_Lib_Qr_Test', 'filterAnswer']);

            $res_obj = $qr_obj->generate([ 'text' => self::SAMPLE_URL, ]);

            $this->assertSame('changed by the listener', $res_obj->output, 'the listener had the last word');
            $this->assertSame(self::SAMPLE_URL, $res_obj->listener_text, 'the listener was told what was asked for');
        } finally {
            $is_removed = Dj_App_Hooks::removeFilter('app.lib.qr.filter.output', ['Djebel_Lib_Qr_Test', 'filterAnswer']);
        }

        $this->assertNotEmpty($is_removed, 'the listener was removed');

        $unfiltered_res_obj = $qr_obj->generate([ 'text' => self::SAMPLE_URL, ]);

        $this->assertStringStartsWith('<svg', $unfiltered_res_obj->output, 'with the listener gone the markup is back');
    }

    /**
     * Pins that drawing many codes leaves one class loader behind, not one per code.
     */
    public function testGenerateRegistersItsClassLoaderOnce() {
        $qr_obj = Djebel_Lib_Qr::getInstance();
        $first_res_obj = $qr_obj->generate([ 'text' => self::SAMPLE_URL, ]);

        $this->assertTrue($first_res_obj->isSuccess(), 'the first code is drawn');

        $second_res_obj = $qr_obj->generate([ 'text' => self::SAMPLE_URL . '2', ]);

        $this->assertTrue($second_res_obj->isSuccess(), 'the second code is drawn');

        $class_loaders = spl_autoload_functions();
        $own_loaders = [];

        foreach ($class_loaders as $class_loader) {
            if (is_array($class_loader) && $class_loader[0] === $qr_obj) {
                $own_loaders[] = $class_loader;
            }
        }

        $this->assertCount(1, $own_loaders, 'the loader is registered once');
    }

    /**
     * Pins what the class loader will and will not read: a class of the packages, never a
     * made-up name that climbs out of their folder, and nothing of another namespace.
     */
    public function testLoadPackageClassReadsOnlyThePackages() {
        $qr_obj = Djebel_Lib_Qr::getInstance();
        $is_package_class_read = $qr_obj->loadPackageClass('chillerlan\\QRCode\\QRCode');

        $this->assertTrue($is_package_class_read, 'a class of the package is read');

        // Four steps up from the package's classes is this library's own entry file: a file
        // that exists, so only the check on the name can be what refuses it.
        $is_outside_class_read = $qr_obj->loadPackageClass('chillerlan\\QRCode\\..\\..\\..\\..\\lib');

        $this->assertFalse($is_outside_class_read, 'a name that climbs out of the package is refused');

        $is_missing_class_read = $qr_obj->loadPackageClass('chillerlan\\QRCode\\No_Such_Class');

        $this->assertFalse($is_missing_class_read, 'a class the package does not have is not read');

        $is_other_class_read = $qr_obj->loadPackageClass('Some_Other_Class');

        $this->assertFalse($is_other_class_read, 'a class of another namespace is left alone');
    }
}
