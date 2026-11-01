<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Modules\Sales\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    private function workspaceId(Request $request): int
    {
        return (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);
    }

    public function index(Request $request): Response
    {
        $wid = $this->workspaceId($request);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(Product::STATUSES)],
        ]);

        $products = Product::forWorkspace($wid)
            ->when(filled($filters['status'] ?? null), fn ($query) => $query->where('status', $filters['status']))
            ->when(filled($filters['q'] ?? null), function ($query) use ($filters) {
                $term = '%'.$filters['q'].'%';
                $query->where(function ($inner) use ($term) {
                    $inner->where('name', 'like', $term)->orWhere('sku', 'like', $term);
                });
            })
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Products/Index', [
            'products' => $products,
            'filters' => [
                'q' => $filters['q'] ?? '',
                'status' => $filters['status'] ?? '',
            ],
            'currencies' => $this->currencies(),
            'defaultCurrency' => Currency::defaultCode(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $wid = $this->workspaceId($request);

        Product::create($this->validated($request, $wid));

        return back()->with('success', __('Product created.'));
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $wid = $this->workspaceId($request);
        abort_unless((int) $product->workspace_id === $wid, 403);

        $product->update($this->validated($request, $wid, $product));

        return back()->with('success', __('Product updated.'));
    }

    public function destroy(Request $request, Product $product): RedirectResponse
    {
        $wid = $this->workspaceId($request);
        abort_unless((int) $product->workspace_id === $wid, 403);

        $product->delete();

        return back()->with('success', __('Product deleted.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, int $workspaceId, ?Product $product = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:256'],
            'sku' => [
                'nullable', 'string', 'max:64',
                Rule::unique('products', 'sku')
                    ->where(fn ($query) => $query->where('workspace_id', $workspaceId))
                    ->ignore($product?->id),
            ],
            'price' => ['required', 'numeric', 'min:0', 'max:99999999999'],
            'currency' => ['nullable', 'string', 'max:8'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'unit' => ['nullable', 'string', 'max:32'],
            'description' => ['nullable', 'string', 'max:5000'],
            'status' => ['required', Rule::in(Product::STATUSES)],
        ]);

        return [
            'workspace_id' => $workspaceId,
            'name' => $data['name'],
            'sku' => $data['sku'] ?? null,
            'price' => $data['price'],
            'currency' => $data['currency'] ?? null,
            'tax_rate' => $data['tax_rate'] ?? 0,
            'unit' => $data['unit'] ?? null,
            'description' => $data['description'] ?? null,
            'status' => $data['status'],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function currencies(): array
    {
        $codes = Currency::where('enabled', true)->orderBy('code')->pluck('code')->all();

        return $codes !== [] ? $codes : ['USD', 'EUR', 'GBP', 'AED', 'SAR', 'EGP'];
    }
}
