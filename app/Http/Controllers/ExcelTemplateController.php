<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

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
        $path = 'storage\excel-templates\fruits-and-vegetables-template.xlsx';
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
}
