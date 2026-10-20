@props(['url'])
{{-- Kop email gs.POS: ikon PNG (klien email jarang mendukung SVG) + nama merek sebagai teks cadangan --}}
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block; text-decoration: none;">
<img src="{{ asset('images/brand/icon-192.png') }}" width="36" height="36" alt="" style="width: 36px; height: 36px; border-radius: 8px; vertical-align: middle; border: 0;">
<span style="vertical-align: middle; margin-left: 8px; font-size: 20px; font-weight: 800; color: #1b2a55;"><span style="color: #8a919e;">gs</span>.POS</span>
</a>
</td>
</tr>
