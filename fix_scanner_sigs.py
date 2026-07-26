#!/usr/bin/env python3
"""Remove string $assetType from doScan signatures in Scanner files."""
import re
import glob

base = '/var/www/html/laravel-invest/app/Services/Trading'
files = glob.glob(os.path.join(base, '*Scanner*.php'))

for filepath in sorted(files):
    with open(filepath, 'r') as f:
        content = f.read()

    original = content

    # Pattern: remove "string $assetType," as the first param in doScan
    # Matches: "function doScan(\n        string $assetType," -> "function doScan("
    content = re.sub(
        r'(function doScan\(\s*)string \$assetType,\s*',
        r'\1',
        content
    )

    if content != original:
        with open(filepath, 'w') as f:
            f.write(content)
        print(f'Modified: {os.path.basename(filepath)}')

print('\nDone.')
