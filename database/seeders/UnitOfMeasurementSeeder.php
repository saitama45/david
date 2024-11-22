<?php

namespace Database\Seeders;

use App\Models\UnitOfMeasurement;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class UnitOfMeasurementSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $unitOfMeasurement = [
            "BAG",
            "BOT",
            "BOTTLE",
            "BOX",
            "BUNDLE",
            "Bag",
            "Batch",
            "Bottle",
            "Box",
            "Bundle",
            "CAN",
            "CASE",
            "Case",
            "GAL",
            "GRAM",
            "Gal",
            "Galon",
            "KG",
            "Kg",
            "LIT",
            "LITER",
            "LOAF",
            "LOT",
            "ML",
            "OZ",
            "PACK",
            "PACK(10)",
            "PACK(100)",
            "PACK(1000)",
            "PACK(150)",
            "PACK(24)",
            "PACK(30)",
            "PACK(40)",
            "PACK(50)",
            "PACK(500)",
            "PAD",
            "PAD(50)",
            "PC",
            "PC(300)",
            "PC(50)",
            "PC(8)",
            "Pack",
            "Pc",
            "ROLL",
            "Ream",
            "SERVING",
            "SET",
            "SHEETS",
            "SLAB",
            "SLV",
            "SLV(25)",
            "Set",
            "Sleeve",
            "TIN",
            "TUB",
            "UNIT",
            "UOM",
            "Unit",
            "WHOLE",
            "Whole",
            "batch",
            "bot",
            "bottle",
            "box",
            "bundle",
            "can",
            "case",
            "gal",
            "grams",
            "kg",
            "ml",
            "order",
            "pack",
            "pail",
            "pc",
            "roll",
            "tetra",
            "tin",
            "whole"
        ];


        foreach ($unitOfMeasurement as $name) {
            UnitOfMeasurement::create([
                'name' => $name
            ]);
        }
    }
}
