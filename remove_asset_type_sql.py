#!/usr/bin/env python3
"""Comprehensively remove asset_type from SQL queries in all Scanner and EntryFinder files."""
import re
import os
import glob

base = '/var/www/html/laravel-invest/app/Services/Trading'
patterns = [
    '*Scanner*.php',
    '*EntryFinder*.php',
    '*Biased*.php',
]
# Also include AbstractSignalScanner
all_files = set()
for p in patterns:
    all_files.update(glob.glob(os.path.join(base, p)))
all_files.add(os.path.join(base, 'AbstractSignalScanner.php'))
all_files = sorted(all_files)

count_total = 0

for filepath in sorted(all_files):
    with open(filepath, 'r') as f:
        content = f.read()

    original = content

    # 1. Remove lines with standalone WHERE/AND asset_type conditions
    #    "WHERE asset_type = ?" -> remove
    #    "AND asset_type = ?"  -> remove
    #    "WHERE asset_type = 'stock'" -> remove
    #    "AND asset_type = 'stock'" -> remove
    #    "AND f.asset_type = ?" etc
    content = re.sub(r'^\s*(?:WHERE|AND)\s+\w*\.?\s*asset_type\s*=\s*(\?|\'stock\')\s*$', '', content, flags=re.MULTILINE)

    # 2. Remove asset_type from PARTITION BY clause
    #    "PARTITION BY symbol, asset_type" -> "PARTITION BY symbol"
    content = re.sub(r'(PARTITION\s+BY\s+\w+),\s*asset_type', r'\1', content)
    content = re.sub(r'(PARTITION\s+BY\s+\w+\.\w+),\s*\w*\.?\s*asset_type', r'\1', content)

    # 3. Remove asset_type from GROUP BY clause
    #    "GROUP BY symbol, asset_type" -> "GROUP BY symbol"
    #    "GROUP BY symbol, asset_type, trading_date_est" -> "GROUP BY symbol, trading_date_est"
    content = re.sub(r',\s*\w*\.?\s*asset_type\b', '', content)
    content = re.sub(r'GROUP BY \w+\.?asset_type\s*,?\s*', 'GROUP BY ', content)

    # 4. Remove JOIN conditions on asset_type
    #    "AND r.asset_type = a.asset_type" -> remove
    content = re.sub(r'\s*AND\s+\w+\.asset_type\s*=\s*\w+\.asset_type\s*', ' ', content)
    #    "JOIN ... ON r.symbol=a.symbol AND r.asset_type=a.asset_type" -> "JOIN ... ON r.symbol=a.symbol"
    #    Already handled by the pattern above

    # 5. Remove asset_type from SELECT column lists
    #    "SELECT symbol, asset_type," -> "SELECT symbol,"
    #    "f.asset_type," -> remove
    #    "e.asset_type," -> remove
    #    "a.asset_type," -> remove
    content = re.sub(r',?\s*\w*\.?\s*asset_type\s*,', ',', content)
    content = re.sub(r'SELECT\s+\?\s+AS\s+asset_type\s*,?\s*', 'SELECT ', content)

    # 6. Remove $assetType from parameter binding arrays
    #    [, $assetType, ...] -> [,...]
    #    [$assetType, ...] -> [...]
    content = re.sub(r',\s*\$assetType\s*,', ',', content)
    content = re.sub(r'\[\s*\$assetType\s*,', '[', content)
    content = re.sub(r',\s*\$assetType\s*\]', ']', content)
    content = re.sub(r'\[\s*\$assetType\s*\]', '[]', content)

    # 7. Remove asset_type from validateSignalRow required keys
    #    'asset_type' must stay in the validation array since concrete scanners still output it
    #    ... actually skip this, it's a return value key not SQL

    if content != original:
        with open(filepath, 'w') as f:
            f.write(content)
        print(f'Modified: {os.path.basename(filepath)}')
        count_total += 1

print(f'\nTotal files modified: {count_total}')
