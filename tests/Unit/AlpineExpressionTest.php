<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/*
 * Alpine membungkus ekspresi x-on/@event/x-init/x-effect sebagai `__self.result = <ekspresi>`; pernyataan seperti
 * `try { … }` di awal atribut = SyntaxError di setiap halaman ("Unexpected token 'try'"). Logika berblok harus
 * dipindah ke method x-data lalu dipanggil (lihat livewire/cashier/self-order-badge).
 */
it('atribut Alpine tidak diawali pernyataan berblok (try/for/while/switch)', function () {
    $offenders = [];
    $pattern = '/\s(?:x-on:[\w.:-]+|@[\w.:-]+|x-init|x-effect)="\s*(try|for|while|switch|do)\b/';

    foreach (Finder::create()->files()->in(resource_path('views'))->name('*.blade.php') as $file) {
        if (preg_match_all($pattern, $file->getContents(), $matches) > 0) {
            $offenders[] = $file->getRelativePathname().' ('.implode(', ', array_unique($matches[1])).')';
        }
    }

    expect($offenders)->toBe([]);
});
