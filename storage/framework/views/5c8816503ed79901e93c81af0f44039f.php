<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Inventory Movement Report</title>
    <style>
        @page { margin: 10px; }
        body { font-family: sans-serif; font-size: 9px; margin: 0; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ccc; padding: 3px; text-align: center; }
        .text-left { text-align: left; }
        .text-right { text-align: right; }
        .font-bold { font-weight: bold; }
        .header { text-align: center; margin-bottom: 10px; }
        .header h1 { font-size: 16px; margin: 0; }
        .info { margin-bottom: 10px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Inventory Movement Report</h1>
        <p><?php echo e(\Carbon\Carbon::parse($filters['date_from'])->format('M d, Y')); ?> - <?php echo e(\Carbon\Carbon::parse($filters['date_to'])->format('M d, Y')); ?></p>
    </div>

    <div class="info">
        <strong>Branch:</strong> <?php echo e($branch ? $branch->name : 'N/A'); ?> | 
        <strong>Generated:</strong> <?php echo e($date_generated); ?> | 
        <strong>By:</strong> <?php echo e($generated_by); ?>

    </div>

    <table>
        <thead>
            <tr style="background-color: #eee;">
                <th class="text-left">Code</th>
                <th class="text-left" width="25%">Description</th>
                <th>UOM</th>
                <th>Ord</th>
                <th>Com</th>
                <th>Rec</th>
                <th>Beg</th>
                <th>Sales</th>
                <th>Wast</th>
                <th>In</th>
                <th>Out</th>
                <th>Theo</th>
                <th>Act</th>
            </tr>
        </thead>
        <tbody>
            <?php $__currentLoopData = $movementData; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $item): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <tr>
                    <td class="text-left"><?php echo e($item['sap_code']); ?></td>
                    <td class="text-left"><?php echo e($item['item_description']); ?></td>
                    <td><?php echo e($item['uom']); ?></td>
                    <td class="text-right"><?php echo e(number_format($item['ordered_qty'], 2)); ?></td>
                    <td class="text-right"><?php echo e(number_format($item['committed_qty'], 2)); ?></td>
                    <td class="text-right font-bold"><?php echo e(number_format($item['received_qty'], 2)); ?></td>
                    <td class="text-right font-bold"><?php echo e(number_format($item['beg_bal_qty'], 2)); ?></td>
                    <td class="text-right"><?php echo e(number_format($item['sales_qty'], 2)); ?></td>
                    <td class="text-right"><?php echo e(number_format($item['wastage_qty'], 2)); ?></td>
                    <td class="text-right"><?php echo e(number_format($item['interco_in_qty'], 2)); ?></td>
                    <td class="text-right"><?php echo e(number_format($item['interco_out_qty'], 2)); ?></td>
                    <td class="text-right font-bold"><?php echo e(number_format($item['theoretical_qty'], 2)); ?></td>
                    <td class="text-right font-bold">
                        <?php echo e($item['actual_mec'] !== null ? number_format($item['actual_mec'], 2) : '-'); ?>

                    </td>
                </tr>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </tbody>
    </table>
</body>
</html>
<?php /**PATH D:\CTO Profile\Documents\App\laravel\david\resources\views/pdf/inventory-movement-report.blade.php ENDPATH**/ ?>