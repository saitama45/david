<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ExcelTemplateController extends Controller
{
    public function gsiBakeryTemplate()
    {
        $path = 'storage\excel-templates\GSI_OT_BAKERY_SAMPLE_SO_UPLOAD.xlsx';
        return response()->download($path);
    }

    public function gsiPrTemplate()
    {
        $path = 'storage\excel-templates\GSI_OT_PR_SAMPLE_SO_UPLOAD.xlsx';
        return response()->download($path);
    }

    public function pulTemplate()
    {
        $path = 'storage\excel-templates\PUL_SAMPLE_SO_UPLOAD.xlsx';
        return response()->download($path);
    }

    public function productsTemplate()
    {
        $path = 'storage\excel-templates\GSI_PRODUCTS.xlsx';
        return response()->download($path);
    }
}
