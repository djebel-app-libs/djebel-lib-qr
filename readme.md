# djebel-lib-qr

Draws a text as a QR code — `Djebel_Lib_Qr`. One call, `generate()`, and the code comes back as
SVG unless another type is asked for. An optional short word can sit in the middle of the code,
for a brand name on a printed sticker. Nothing is stored and nothing is fetched: a text goes in,
the code comes out.

## Usage

```php
Dj_App_Lib::loadLib('djebel-lib-qr');
$qr_obj = Djebel_Lib_Qr::getInstance();

$qr_params = [
    'text' => 'https://example.com/v/AB1234CD',
    'label' => 'Example',
];

$res_obj = $qr_obj->generate($qr_params);

if ($res_obj->isError()) {
    return $res_obj;
}

echo $res_obj->output;
```

| Param | | |
|---|---|---|
| `text` | required | What the code holds. Spaces around it are dropped. |
| `label` | optional | A short word written in the middle of the code. |
| `type` | optional | What comes back: one of the `Djebel_Lib_Qr::TYPE_*` constants. `TYPE_SVG` when left out. |

The code is always in the Result's `output`, whatever the type, so switching type is a change
of that one param.

`TYPE_SVG` gives an `<svg>` with a `viewBox` and no fixed size, so the page or the printer
decides how large it is. It carries no XML header, since it is meant to go inside a page.

## When it refuses

`generate()` always returns a `Dj_App_Result`. On failure `output` is empty, `code` says why, and
`msg` is a sentence that is safe to show.

| Code | Meaning |
|---|---|
| `qr.type_unsupported` | The `type` asked for is not one of the `TYPE_*` constants. |
| `qr.text_missing` | No text was given. |
| `qr.text_too_long` | The text is longer than a QR code can hold (`Djebel_Lib_Qr::MAX_TEXT_LENGTH` bytes). |
| `qr.label_too_long` | The word is longer than `Djebel_Lib_Qr::MAX_LABEL_LENGTH` letters. |
| `qr.label_invalid` | The word is not valid UTF-8. |
| `qr.labeled_text_too_long` | A word was asked for and the text is longer than `Djebel_Lib_Qr::MAX_LABELED_TEXT_LENGTH` bytes. |
| `qr.package_missing` | The package under `shared/qr-lib/` is not there. |
| `qr.draw_failed` | The package could not draw the code. The log has its message. |

### The word in the middle

The code is built at the highest error-correction level, which is what lets part of it be
covered. The word sits in a space cleared for it and is cut off at that space's edges, so its
letters never cover the code's own squares.

A word is refused on long texts on purpose. Codes with a word were scanned back for texts up to
`MAX_LABELED_TEXT_LENGTH` bytes; the largest code a text can make stopped scanning once the word
was in. The same text without a word is still drawn.

## Hook

| Hook | Kind | Receives | Purpose |
|---|---|---|---|
| `app.lib.qr.filter.output` | filter | `Dj_App_Result` | change or replace the answer before it reaches the caller |

The listener also receives a `$ctx` array with `params`, what `generate()` was called with.

## Requirements

- PHP 7.4 or newer.
- The `mbstring` and `json` extensions, which the two packages ask for.

## Credits

The QR code itself is built by **chillerlan/php-qrcode**. This library is a thin layer over it,
and the two packages below sit in `shared/qr-lib/` exactly as their authors released them, with
their own license files.

| Package | Version | Author | License | Source |
|---|---|---|---|---|
| php-qrcode | 5.0.5 | Smiley | MIT, © 2015 Smiley. Parts are ported from ZXing (© 2007 ZXing authors) and from Ashot Khanamiryan's php-qrcode-detector-decoder, under Apache 2.0 | https://github.com/chillerlan/php-qrcode |
| php-settings-container | 2.1.6 | Smiley | MIT, © 2018 Smiley | https://github.com/chillerlan/php-settings-container |

Each package's folder holds its full license text: `LICENSE-MIT`, `LICENSE-ASL-2.0` and `NOTICE`
for php-qrcode, `LICENSE` for php-settings-container. php-qrcode needs php-settings-container,
which is why both are here.

The files came from the projects' own release archives:

- https://github.com/chillerlan/php-qrcode/archive/refs/tags/5.0.5.tar.gz
- https://github.com/chillerlan/php-settings-container/archive/refs/tags/2.1.6.tar.gz

## Updating the packages

No package manager is involved. The library reads the packages' classes itself, so an update is a
change of files:

1. Download the release archive of the new version from the project's releases page.
2. Replace the whole folder, `shared/qr-lib/php-qrcode/` or `shared/qr-lib/php-settings-container/`,
   with the archive's content. Keep the folder's name.
3. Update the version in the table above.
4. Run the tests (below).

These versions are the newest that run on PHP 7.4. php-qrcode 6 needs PHP 8.2 and
php-settings-container 3 needs PHP 8.1, so moving to them means raising `min_php_ver` in
`lib.php` first.

## Tests

The tests run through djebel-app's shared runner, which needs PHP 8.3 or newer:

```bash
php <djebel-app>/tools/testing/run.php <this folder>
```
