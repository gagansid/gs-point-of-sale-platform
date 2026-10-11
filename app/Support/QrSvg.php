<?php

declare(strict_types=1);

namespace App\Support;

use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/** QR code sebagai SVG inline (tanpa file/gambar eksternal). */
final class QrSvg
{
    public static function make(string $data): string
    {
        $options = new QROptions([
            'outputType' => QROutputInterface::MARKUP_SVG,
            'outputBase64' => false,
            'addQuietzone' => true,
            'quietzoneSize' => 2,
            'svgUseFillAttributes' => true,
        ]);

        return (new QRCode($options))->render($data);
    }
}
