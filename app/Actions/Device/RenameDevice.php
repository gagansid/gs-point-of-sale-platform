<?php

declare(strict_types=1);

namespace App\Actions\Device;

use App\Models\Device;

/** Ganti nama perangkat (mis. "Kasir depan", "Laptop bar") agar mudah dikenali di daftar & laporan. */
final class RenameDevice
{
    public function handle(Device $device, string $name): Device
    {
        $device->forceFill(['name' => mb_substr(trim($name), 0, 100)])->save();

        return $device;
    }
}
