<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use Illuminate\Http\Request;
use Inertia\Inertia;

class OrderingTemplateApprovalController extends Controller
{
    public function index()
    {
        $suppliers = Supplier::where('is_active', 1)->get(['id', 'supplier_code', 'name', 'is_forapproval_massorders']);
        return Inertia::render('OrderingTemplateApproval/Index', [
            'suppliers' => $suppliers
        ]);
    }

    public function update(Request $request, Supplier $supplier)
    {
        $request->validate([
            'is_forapproval_massorders' => 'required|boolean'
        ]);

        if (Supplier::isUnrestrictedTemplate($supplier->supplier_code) && ! $request->boolean('is_forapproval_massorders')) {
            return redirect()->back()->withErrors(['error' => "{$supplier->supplier_code} orders always go to Mass Orders Approval, whose approval commits them, so this switch stays on."]);
        }

        $supplier->update([
            'is_forapproval_massorders' => $request->is_forapproval_massorders
        ]);

        return redirect()->back()->with('success', 'Supplier updated successfully.');
    }
}
