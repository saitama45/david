<?php

namespace App\Http\Controllers;

use App\Exports\SupplierItemsExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class ExcelTemplateController extends Controller
{
    public function gsiBakeryTemplate()
    {
        $path = 'storage\excel-templates\gsi_order_template.xlsx';
        return response()->download($path);
    }

    public function gsiPrTemplate()
    {
        $path = 'storage\excel-templates\gsi_ot_order_template.xlsx';
        return response()->download($path);
    }

    public function pulTemplate()
    {
        $path = 'storage\excel-templates\pul_order_template.xlsx';
        return response()->download($path);
    }

    public function productsTemplate()
    {
        $path = 'storage\excel-templates\product_template.xlsx';
        return response()->download($path);
    }

    public function sapMasterfileTemplate()
    {
        // Define the path to your new template file
        // Use `storage_path('app/public/excel-templates/sapmasterfile_template.xlsx')`
        // for a more robust path that works across different OS.
        $path = 'storage\excel-templates\sapmasterfile_template.xlsx';

        // Check if the file exists before attempting to download
        if (!file_exists($path)) {
            // Log an error or return a 404 response if the file is not found
            // For production, you might want a more user-friendly error page.
            abort(404, 'SAP Masterfile template file not found.');
        }

        // Return the download response
        return response()->download($path);
    }

    public function POSMasterfileTemplate()
    {
        // Define the path to your new template file
        // Use `storage_path('app/public/excel-templates/POSMasterfile_template.xlsx')`
        // for a more robust path that works across different OS.
        $path = 'storage\excel-templates\POSMasterfile_template.xlsx';

        // Check if the file exists before attempting to download
        if (!file_exists($path)) {
            // Log an error or return a 404 response if the file is not found
            // For production, you might want a more user-friendly error page.
            abort(404, 'POS Masterfile template file not found.');
        }

        // Return the download response
        return response()->download($path);
    }

    public function posBomTemplate()
    {
        // Define the path to your new template file
        // Use `storage_path('app/public/excel-templates/sapmasterfile_template.xlsx')`
        // for a more robust path that works across different OS.
        $path = 'storage\excel-templates\BOM_template.xlsx';

        // Check if the file exists before attempting to download
        if (!file_exists($path)) {
            // Log an error or return a 404 response if the file is not found
            // For production, you might want a more user-friendly error page.
            abort(404, 'BOM template file not found.');
        }

        // Return the download response
        return response()->download($path);
    }

    public function SupplierItemsTemplate()
    {
        $user = Auth::user();
        if (!$user) {
            return back()->with('error', 'Please log in to download the template.');
        }

        $assignedSupplierCodes = $user->suppliers->pluck('supplier_code')->toArray();

        return Excel::download(new SupplierItemsExport(null, null, $assignedSupplierCodes), 'SupplierItems_template.xlsx');
    }

    public function storeTransactionsTemplate()
    {
        $path = 'storage\excel-templates\store_transactions_template.xlsx';
        return response()->download($path);
    }

    public function menuTemplate()
    {
        $path = 'storage\excel-templates\menu_template.xlsx';
        return response()->download($path);
    }

    public function fruitsAndVegetablesTemplate()
    {
        $path = 'storage\excel-templates\fruits-and-vegetables-south-template.xlsx';
        return response()->download($path);
    }

    public function fruitsAndVegetablesMMTemplate()
    {
        $path = 'storage\excel-templates\fruits-and-vegetables-mm-template.xlsx';
        return response()->download($path);
    }

    public function iceCreamTemplate()
    {
        $path = 'storage\excel-templates\ice-cream-template.xlsx';
        return response()->download($path);
    }

    public function salmonTemplate()
    {
        $path = 'storage\excel-templates\salmon-template.xlsx';
        return response()->download($path);
    }

    public function wipListTemplate()
    {
        $path = 'storage\excel-templates\wip_list_template.xlsx';
        return response()->download($path);
    }

    public function wipIngredientsTemplate()
    {
        $path = 'storage\excel-templates\wip_ingredients_template.xlsx';
        return response()->download($path);
    }

    public function bomListTemplate()
    {
        $path = 'storage\excel-templates\bom_list_template.xlsx';
        return response()->download($path);
    }

    public function bomIngredientsTemplate()
    {
        $path = 'storage\excel-templates\bom_ingredients_template.xlsx';
        return response()->download($path);
    }

    // NEW: Method to serve the Store Order template
    public function storeOrderTemplate()
    {
        $path = 'storage\excel-templates\store_order_template.xlsx'; // Assuming this file exists
        if (!file_exists($path)) {
            abort(404, 'Store Order template file not found.');
        }
        return response()->download($path);
    }
}
