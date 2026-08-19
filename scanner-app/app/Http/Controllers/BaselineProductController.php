<?php

namespace App\Http\Controllers;

use App\Models\BaselineProduct;
use Illuminate\Http\Request;

class BaselineProductController extends Controller
{
    public function index()
    {
        $products = BaselineProduct::orderBy('provider')->get();
        return response()->json(['data' => $products]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'criteria' => 'nullable|string',
            'provider' => 'required|string',
            'package_name' => 'required|string',
            'rbp_vori' => 'nullable|string',
            'rbp_rebuy' => 'nullable|string',
            'rbp_inject' => 'nullable|string',
            'price' => 'required|numeric',
            'quota_s' => 'required|numeric',
            'quota_e' => 'required|numeric',
            'quota_a' => 'required|numeric',
            'days' => 'required|integer',
        ]);

        $product = BaselineProduct::create($validated);
        return response()->json(['status' => 'success', 'data' => $product]);
    }

    public function update(Request $request, BaselineProduct $baselineProduct)
    {
        $validated = $request->validate([
            'criteria' => 'nullable|string',
            'provider' => 'required|string',
            'package_name' => 'required|string',
            'rbp_vori' => 'nullable|string',
            'rbp_rebuy' => 'nullable|string',
            'rbp_inject' => 'nullable|string',
            'price' => 'required|numeric',
            'quota_s' => 'required|numeric',
            'quota_e' => 'required|numeric',
            'quota_a' => 'required|numeric',
            'days' => 'required|integer',
        ]);

        $baselineProduct->update($validated);
        return response()->json(['status' => 'success', 'data' => $baselineProduct]);
    }

    public function destroy(BaselineProduct $baselineProduct)
    {
        $baselineProduct->delete();
        return response()->json(['status' => 'success']);
    }

    public function bulkUpdate(Request $request)
    {
        $validated = $request->validate([
            'packages' => 'required|array',
            'packages.*.id' => 'nullable|integer|exists:baseline_products,id',
            'packages.*.criteria' => 'nullable|string',
            'packages.*.provider' => 'required|string',
            'packages.*.package_name' => 'required|string',
            'packages.*.price' => 'required|numeric',
            'packages.*.quota_s' => 'required|numeric',
            'packages.*.quota_e' => 'required|numeric',
            'packages.*.quota_a' => 'required|numeric',
            'packages.*.days' => 'required|integer',
        ]);

        foreach ($validated['packages'] as $pkg) {
            if (isset($pkg['id'])) {
                BaselineProduct::where('id', $pkg['id'])->update([
                    'criteria' => $pkg['criteria'] ?? null,
                    'provider' => $pkg['provider'],
                    'package_name' => $pkg['package_name'],
                    'price' => $pkg['price'],
                    'quota_s' => $pkg['quota_s'],
                    'quota_e' => $pkg['quota_e'],
                    'quota_a' => $pkg['quota_a'],
                    'days' => $pkg['days'],
                ]);
            } else {
                BaselineProduct::create($pkg);
            }
        }
        return response()->json(['status' => 'success']);
    }
}
