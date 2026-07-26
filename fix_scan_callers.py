#!/usr/bin/env python3
"""Remove $assetType argument from all scan() call sites in Console commands."""
import re
import os
import glob

base = '/var/www/html/laravel-invest/app/Console/Commands'
files = glob.glob(os.path.join(base, 'TradePipeline*.php'))
files += glob.glob(os.path.join(base, 'GenerateBacktestAlerts.php'))
files += glob.glob(os.path.join(base, 'TradingRealtimeBacktestCommand.php'))

for filepath in sorted(files):
    with open(filepath, 'r') as f:
        content = f.read()

    original = content

    # Pattern 1: ->scan($assetType, ...) -> ->scan(...)
    content = re.sub(r'->scan\(\s*\$assetType,\s*', '->scan(', content)

    # Pattern 2: ->scan('stock', ...) -> ->scan(...)
    # But also handle ->scan( $assetType, ...) which is multi-line
    content = re.sub(r"->scan\(\s*'stock',\s*", '->scan(', content)

    if content != original:
        with open(filepath, 'w') as f:
            f.write(content)
        print(f'Modified: {os.path.basename(filepath)}')

# Also fix app/Jobs/RealtimeBacktestSymbolBatchJob.php
job_path = '/var/www/html/laravel-invest/app/Jobs/RealtimeBacktestSymbolBatchJob.php'
if os.path.exists(job_path):
    with open(job_path, 'r') as f:
        content = f.read()
    original = content
    content = re.sub(r"->scan\(\s*'stock',\s*", '->scan(', content)
    if content != original:
        with open(job_path, 'w') as f:
            f.write(content)
        print(f'Modified: RealtimeBacktestSymbolBatchJob.php')

print('\nDone.')
