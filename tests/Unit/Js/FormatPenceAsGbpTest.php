<?php

namespace Tests\Unit\Js;

use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class FormatPenceAsGbpTest extends TestCase
{
    public function test_format_pence_as_gbp_uses_smart_precision(): void
    {
        $result = Process::path(base_path())
            ->run([
                'node',
                '--experimental-strip-types',
                '--input-type=module',
                '-e',
                <<<'JS'
import { formatPenceAsGbp } from './resources/js/lib/money.ts';

const autoCases = [
  [0, '£0'],
  [0.01, '£0.0001'],
  [1.03, '£0.0103'],
  [1.3, '£0.013'],
  [103, '£1.03'],
  [630, '£6.30'],
  [630.12, '£6.3012'],
  [1900, '£19'],
  [-1.03, '-£0.0103'],
];

for (const [pence, expected] of autoCases) {
  const actual = formatPenceAsGbp(pence);
  if (actual !== expected) {
    console.error(JSON.stringify({ mode: 'auto', pence, expected, actual }));
    process.exit(1);
  }
}

const catalogCases = [
  [1000, '£10'],
  [1900, '£19'],
  [3000, '£30'],
  [1050, '£10.50'],
];

for (const [pence, expected] of catalogCases) {
  const actual = formatPenceAsGbp(pence, { decimals: 2 });
  if (actual !== expected) {
    console.error(JSON.stringify({ mode: 'catalog', pence, expected, actual }));
    process.exit(1);
  }
}

const signed = formatPenceAsGbp(1.03, { signed: true });
if (signed !== '+£0.0103') {
  console.error(JSON.stringify({ expected: '+£0.0103', actual: signed }));
  process.exit(1);
}

const fixed4Cases = [
  [0, '£0.0000'],
  [1.03, '£0.0103'],
  [103, '£1.0300'],
  [1900, '£19.0000'],
];

for (const [pence, expected] of fixed4Cases) {
  const actual = formatPenceAsGbp(pence, { decimals: 4 });
  if (actual !== expected) {
    console.error(JSON.stringify({ mode: 'fixed4', pence, expected, actual }));
    process.exit(1);
  }
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
