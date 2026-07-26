#!/usr/bin/env python3
"""Apply all structural changes to pipeline scanners and finders."""
import os, re

base = 'app/Services/Trading'
versions = ['V17_0','V25_2','V27_0','V35_0','V60_3','V90_1','V101_0','V103_0',
            'V1100_0','V120_0','V140_0','V400_0','V900_1','V1200_0','V1600_0','V2000_0']

def scanner_fix(v):
    f = os.path.join(base, f'FiveMinuteSignalScanner{v}.php')
    if not os.path.exists(f) or v == 'V25_2':
        return
    with open(f) as fh:
        c = fh.read()
    
    old = f'class FiveMinuteSignalScanner{v}\n{{\n    use HasPriceTables;'
    new = f'class FiveMinuteSignalScanner{v} extends AbstractSignalScanner\n{{'
    if old in c:
        c = c.replace(old, new)
    
    c = c.replace('public function scan(', 'protected function doScan(')
    
    if 'function scanConfig' not in c:
        c = re.sub(
            r'(public function getName\(\): string\s*\n\s*\{[^}]+\})',
            r'\1\n\n    /** @return array<string, mixed> */\n    public function scanConfig(): array\n    {\n        return [];\n    }',
            c
        )
    
    c = c.replace('private function getSpyMovement30m(', 'protected function getSpyMovement30m(')
    
    with open(f, 'w') as fh:
        fh.write(c)
    print(f'  Scanner {v} done')

def finder_fix(v):
    f = os.path.join(base, f'OneMinuteEntryFinder{v}.php')
    if not os.path.exists(f) or v == 'V25_2':
        return
    with open(f) as fh:
        c = fh.read()
    ver = v.lower().replace('_', '.')
    
    old = f'class OneMinuteEntryFinder{v}\n{{\n    use HasPriceTables;\n\n    private string $version = \'{ver}\';'
    if old in c:
        c = c.replace(old, f'class OneMinuteEntryFinder{v} extends AbstractOneMinuteEntryFinder\n{{')
    
    c = c.replace('public function findBestLong(', 'protected function doFindBestLong(')
    c = c.replace('return $this->version;', f"return '{ver}';")
    
    if 'function getName' not in c:
        c = re.sub(
            r'(public function getVersion\(\): string\s*\n\s*\{[^}]+\})',
            r'\1\n\n    public function getName(): string\n    {\n        return \'' + ver + r'\';\n    }\n\n    /** @return array<string, mixed> */\n    public function entryConfig(): array\n    {\n        return [\'version\' => $this->getVersion()];\n    }',
            c
        )
    
    for m in ['maybeLogDebug','isDebugEnabled','isAllowedTime','calculate5MinChoppiness','hasValidPriceData']:
        c = c.replace(f'private function {m}(', f'protected function {m}(')
    
    c = c.replace('isAllowedTime(string $tsEst): bool', 'isAllowedTime(string $time, bool $allowLunch = false): bool')
    
    with open(f, 'w') as fh:
        fh.write(c)
    print(f'  Finder {v} done')

print('=== SCANNERS ===')
for v in versions:
    scanner_fix(v)

print('=== FINDERS ===')
for v in versions:
    finder_fix(v)

print('ALL DONE')
