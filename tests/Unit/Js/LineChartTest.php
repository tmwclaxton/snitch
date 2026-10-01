<?php

namespace Tests\Unit\Js;

use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class LineChartTest extends TestCase
{
    public function test_connected_line_data_drops_null_weeks_and_keeps_order(): void
    {
        $result = Process::path(base_path())
            ->run([
                'node',
                '--experimental-strip-types',
                '--input-type=module',
                '-e',
                <<<'JS'
import {
  connectedLineData,
  lineChartTimestamp,
  rivalStrokeWidth,
  youStrokeWidth,
} from './resources/js/lib/lineChart.ts';

const points = [
  { date: '2025-09-15', value: 2.1 },
  { date: '2025-09-01', value: 1.4 },
  { date: '2025-09-08', value: null },
  { date: '2025-09-22', value: undefined },
  { date: '', value: 9 },
  { date: '2025-09-29', value: Number.NaN },
];

const data = connectedLineData(points);

if (data.length !== 2) {
  console.error(JSON.stringify({ expectedLength: 2, actual: data }));
  process.exit(1);
}

if (data[0].x !== lineChartTimestamp('2025-09-01') || data[0].y !== 1.4) {
  console.error(JSON.stringify({ expectedFirst: { x: lineChartTimestamp('2025-09-01'), y: 1.4 }, actual: data[0] }));
  process.exit(1);
}

if (data[1].x !== lineChartTimestamp('2025-09-15') || data[1].y !== 2.1) {
  console.error(JSON.stringify({ expectedSecond: { x: lineChartTimestamp('2025-09-15'), y: 2.1 }, actual: data[1] }));
  process.exit(1);
}

if (youStrokeWidth() !== 3 || rivalStrokeWidth() !== 2) {
  console.error(JSON.stringify({ you: youStrokeWidth(), rival: rivalStrokeWidth() }));
  process.exit(1);
}

if (connectedLineData(null).length !== 0 || connectedLineData([]).length !== 0) {
  console.error('empty input should yield empty series');
  process.exit(1);
}

console.log('ok');
JS
            ]);

        $this->assertTrue(
            $result->successful(),
            $result->errorOutput() !== '' ? $result->errorOutput() : $result->output(),
        );
        $this->assertSame('ok', trim($result->output()));
    }
}
