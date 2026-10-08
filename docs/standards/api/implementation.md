# Pola Implementasi Endpoint

Alur standar satu endpoint tulis:

```
Route ─▶ Middleware ─▶ FormRequest (validasi + authorize) ─▶ Controller
      ─▶ Action (transaksi, aturan bisnis) ─▶ API Resource ─▶ ApiResponse
```

Contoh lengkap: `POST /api/v1/orders/{id}/void`.

## 1. Route

```php
// routes/api/v1.php — dikelompokkan per domain, urut sesuai tabel di SPEC
Route::controller(OrderController::class)->prefix('orders')->name('orders.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('{order}', 'show')->name('show');
    Route::put('{order}', 'saveOpenBill')->name('save');
    Route::post('{order}/payments', 'addPayment')->name('payments.store');
    Route::get('{order}/receipt', 'receipt')->name('receipt');
    Route::post('{order}/void', 'void')->name('void');
});
```

Route name lengkap: `api.v1.orders.void`.

## 2. FormRequest

```php
final class VoidOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Kasir tetap lolos: izin akhirnya diputuskan VerifyApproval (jalur PIN)
        return $this->user()->can('order.create');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:255'],
            'approver_user_id' => ['nullable', 'uuid', 'required_with:approver_pin'],
            'approver_pin' => ['nullable', 'digits:6', 'required_with:approver_user_id'],
        ];
    }

    public function approval(): ?ApprovalData
    {
        return $this->filled('approver_user_id')
            ? new ApprovalData($this->string('approver_user_id'), $this->string('approver_pin'))
            : null;
    }
}
```

Pesan validasi berasal dari `lang/id/validation.php`; atribut field ditulis di `attributes()` bila
nama default kurang jelas.

## 3. Controller

```php
final class OrderController extends Controller
{
    /**
     * Batalkan order.
     *
     * Kasir membutuhkan PIN approver (owner/manager/supervisor).
     * Hanya untuk order di shift yang masih terbuka.
     */
    public function void(VoidOrderRequest $request, Order $order, VoidOrder $action): JsonResponse
    {
        $order = $action->handle($request->user(), $order, $request->string('reason'), $request->approval());

        return ApiResponse::success(
            OrderResource::make($order->load(['items.options', 'payments']))->resolve(),
            'Transaksi berhasil dibatalkan',
        );
    }
}
```

Aturan controller: tanpa `try/catch` (exception dipetakan global di `bootstrap/app.php`),
tanpa `DB::`, tanpa perhitungan. PHPDoc method dipakai Scramble sebagai deskripsi endpoint.

## 4. API Resource

```php
/** @mixin Order */
final class OrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'order_type' => $this->order_type->value,
            'status' => $this->status->value,
            'table_label' => $this->table_label,
            'subtotal' => $this->subtotal,          // cast decimal:2 → "62000.00"
            'grand_total' => $this->grand_total,
            'void_reason' => $this->void_reason,
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
            'created_at' => $this->created_at?->utc()->toIso8601ZuluString(),
        ];
    }
}
```

Aturan Resource:

- Enum → `->value`. Uang → string dari cast `decimal:2`. Tanggal → `toIso8601ZuluString()` di UTC.
- Relasi hanya lewat `whenLoaded()` — Resource tidak boleh memicu query.
- Tidak pernah mengirim: `pin`, `password`, `tenant_id`, `cost_price` (kecuali untuk `product.manage`).

## 5. Pemetaan exception

Seluruh pemetaan exception → envelope ada di `bootstrap/app.php` (lihat `docs/SPEC.md`).
Jangan menangkap exception di controller untuk mengubah formatnya.

## 6. Scramble (OpenAPI)

- PHPDoc pada method controller: baris pertama = ringkasan, paragraf berikutnya = deskripsi.
- Tag per domain: `#[Group('Order')]` pada controller.
- Dokumentasi hanya aktif di `local` dan `staging` (gate di `AppServiceProvider`).
