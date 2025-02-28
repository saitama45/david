<?php

namespace App\Jobs;

use App\Models\Menu;
use App\Models\StoreBranch;
use App\Models\StoreTransaction;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class ProcessStoreTransactionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $rowData;
    protected $rowNumber;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var int
     */
    public $backoff = 60;

    /**
     * Create a new job instance.
     *
     * @param array $rowData
     * @param int $rowNumber
     * @return void
     */
    public function __construct(array $rowData, int $rowNumber)
    {
        $this->rowData = $rowData;
        $this->rowNumber = $rowNumber;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        try {
            $row = $this->rowData;

            if (empty($row['product_id'])) {
                return;
            }

            if (is_string($row['product_name']) && stripos($row['product_name'], 'SUBTOTAL:') !== false) {
                return;
            }

            $branch = StoreBranch::where('location_code', $row['branch'])->first();

            if (!$branch) {
                Log::error('Branch not found', [
                    'location_code' => $row['branch'],
                    'row_number' => $this->rowNumber
                ]);
                return;
            }

            $menu = Menu::firstOrNew([
                'product_id' => $row['product_id']
            ], [
                'category_id' => 1,
                'name' => $row['product_name'],
                'price' => $row['price']
            ]);

            if (!$menu->exists) {
                $menu->save();
            }

            $transaction = StoreTransaction::updateOrCreate([
                'store_branch_id' => $branch->id,
                'order_date' => $this->transformDate($row['date']),
                'posted' => $row['posted'],
                'tim_number' => $row['tm'],
                'receipt_number' => $row['receipt_no'],
            ]);

            $transaction->store_transaction_items()->updateOrCreate([
                'product_id' => $row['product_id'],
                'base_quantity' => $row['base_qty'],
                'quantity' => $row['qty'],
                'price' => $row['price'],
                'discount' => $row['discount'],
                'line_total' => $row['line_total'],
                'net_total' => $row['net_total'],
            ]);

            Log::info('Transaction processed successfully', [
                'row_number' => $this->rowNumber,
                'transaction_id' => $transaction->id
            ]);
        } catch (Exception $e) {
            Log::error('Error processing transaction job', [
                'row_number' => $this->rowNumber,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Re-throw for automatic retry if within tries limit
            throw $e;
        }
    }

    /**
     * Transform Excel date value to Y-m-d format
     * 
     * @param mixed $value
     * @return string
     */
    private function transformDate($value)
    {
        try {
            if (empty($value)) {
                throw new Exception('Date value is empty');
            }

            if (is_numeric($value)) {
                return Carbon::createFromDate(1900, 1, 1)
                    ->addDays((int)$value - 2)
                    ->format('Y-m-d');
            }

            if (is_string($value)) {
                return Carbon::parse($value)->format('Y-m-d');
            }

            throw new Exception('Invalid date format');
        } catch (Exception $e) {
            Log::error('Date transformation failed', [
                'value' => $value,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
}
