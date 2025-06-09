<?php

namespace App\Http\Controllers;

use App\Models\DeliverySchedule;
use App\Models\StoreBranch;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Str;
use App\Http\Requests\DeliverySchedule\UpdateDeliveryScheduleRequest;
use App\Http\Services\DeliveryScheduleService;

class DeliveryScheduleController extends Controller
{
    protected $deliveryScheduleService;

    public function __construct(DeliveryScheduleService $deliveryScheduleService)
    {
        $this->deliveryScheduleService = $deliveryScheduleService;
    }
    public function index()
    {
        return Inertia::render('DTSDeliverySchedule/Index', [
            'branches' => $this->deliveryScheduleService->getBranchList(),
            'filters' => request()->only(['search'])
        ]);
    }

    public function edit($id)
    {
        $schedules = $this->deliveryScheduleService->getSchedules($id);

        return Inertia::render('DTSDeliverySchedule/Edit', [
            'branch' => $schedules['branch'],
            'schedules' =>  $schedules['groupedSchedules'],
            'deliverySchedules' => $schedules['deliveryScfhedules']
        ]);
    }

    public function update(UpdateDeliveryScheduleRequest $request, $id)
    {
        $this->deliveryScheduleService->updateDeliveryScedule($request->validated(), $id);
        return redirect()->route('delivery-schedules.index');
    }
}
