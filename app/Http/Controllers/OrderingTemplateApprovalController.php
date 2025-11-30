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

        $supplier->update([
            'is_forapproval_massorders' => $request->is_forapproval_massorders
        ]);

        return redirect()->back()->with('success', 'Supplier updated successfully.');
    }
}
