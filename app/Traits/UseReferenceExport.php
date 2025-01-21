<?php

namespace App\Traits;

trait UseReferenceExport
{
    protected $search;

    public function __construct($search = null)
    {
        $this->search = $search;
    }
    public function query()
    {
        $query = $this->getModel()::query();

        if ($this->search)
            $query->where('name', 'like', "%$this->search%");

        return $query;
    }

    public function headings(): array
    {
        return [
            'ID',
            'Name',
            'Remarks',
            'Created at'
        ];
    }

    public function map($row): array
    {
        return [
            $row->id,
            $row->name,
            $row->remarks,
            $row->created_at
        ];
    }

    abstract protected function getModel();
}
