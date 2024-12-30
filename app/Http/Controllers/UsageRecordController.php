<?php

namespace App\Http\Controllers;

use App\Models\Menu;
use App\Models\StoreBranch;
use Illuminate\Http\Request;
use Inertia\Inertia;

class UsageRecordController extends Controller
{
    public function index()
    {


        return Inertia::render('UsageRecord/Index', []);
    }

    public function create()
    {
        $menus = Menu::options();
        $branches = StoreBranch::options();
        return Inertia::render('UsageRecord/Create', [
            'menus' => $menus,
            'branches' => $branches
        ]);
    }
}
