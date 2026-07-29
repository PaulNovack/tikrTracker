<?php

namespace App\Http\Controllers;

use App\Services\TradingV2\Repositories\AlertVersionRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class GenericTaGateVersionsController extends Controller
{
    public function __construct(
        private readonly AlertVersionRepository $versionRepo,
    ) {}

    /**
     * List all alert versions with their gates.
     */
    public function index(): Response
    {
        $versions = DB::table('alert_versions')
            ->orderBy('pipeline_letter')
            ->get()
            ->map(function ($v) {
                $gates5m = DB::table('alert_version_gates')
                    ->where('alert_version_id', $v->id)
                    ->where('timeframe', '5m')
                    ->orderBy('gate_name')
                    ->get();

                $gates1m = DB::table('alert_version_gates')
                    ->where('alert_version_id', $v->id)
                    ->where('timeframe', '1m')
                    ->orderBy('gate_name')
                    ->get();

                return [
                    'id' => $v->id,
                    'pipeline_letter' => $v->pipeline_letter,
                    'version_string' => $v->version_string,
                    'signal_type' => $v->signal_type,
                    'entry_finder_class' => $v->entry_finder_class,
                    'scanner_score_formula' => $v->scanner_score_formula,
                    'enabled' => (bool) $v->enabled,
                    'gates_5m' => $gates5m,
                    'gates_1m' => $gates1m,
                ];
            });

        // All known gate names for the dropdown
        $allGateNames = DB::table('alert_version_gates')
            ->select('gate_name')
            ->distinct()
            ->orderBy('gate_name')
            ->pluck('gate_name');

        return Inertia::render('system/GenericTaGateVersions', [
            'versions' => $versions,
            'allGateNames' => $allGateNames,
        ]);
    }

    /**
     * Create a new alert version.
     */
    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'pipeline_letter' => ['required', 'string', 'max:2'],
            'version_string' => ['required', 'string', 'max:20'],
            'signal_type' => ['required', 'string', 'max:50'],
            'scanner_score_formula' => ['nullable', 'string', 'max:255'],
            'enabled' => ['boolean'],
            'gates_5m' => ['nullable', 'array'],
            'gates_5m.*.gate_name' => ['required', 'string', 'max:64'],
            'gates_5m.*.operator' => ['required', 'in:>=,<=,>,<,==,bool'],
            'gates_5m.*.threshold' => ['nullable'],
            'gates_1m' => ['nullable', 'array'],
            'gates_1m.*.gate_name' => ['required', 'string', 'max:64'],
            'gates_1m.*.operator' => ['required', 'in:>=,<=,>,<,==,bool'],
            'gates_1m.*.threshold' => ['nullable'],
        ]);

        $id = DB::table('alert_versions')->insertGetId([
            'pipeline_letter' => $validated['pipeline_letter'],
            'version_string' => $validated['version_string'],
            'signal_type' => $validated['signal_type'],
            'scanner_score_formula' => $validated['scanner_score_formula'] ?? null,
            'enabled' => $validated['enabled'] ?? true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Insert 5m gates
        foreach (($validated['gates_5m'] ?? []) as $gate) {
            $threshold = $gate['threshold'] !== null && $gate['threshold'] !== ''
                ? (float) $gate['threshold'] : null;
            if (empty($gate['gate_name'])) {
                continue;
            }

            DB::table('alert_version_gates')->insert([
                'alert_version_id' => $id,
                'timeframe' => '5m',
                'gate_name' => $gate['gate_name'],
                'threshold' => $threshold,
                'operator' => $gate['operator'],
                'enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Insert 1m gates
        foreach (($validated['gates_1m'] ?? []) as $gate) {
            $threshold = $gate['threshold'] !== null && $gate['threshold'] !== ''
                ? (float) $gate['threshold'] : null;
            if (empty($gate['gate_name'])) {
                continue;
            }

            DB::table('alert_version_gates')->insert([
                'alert_version_id' => $id,
                'timeframe' => '1m',
                'gate_name' => $gate['gate_name'],
                'threshold' => $threshold,
                'operator' => $gate['operator'],
                'enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        \Illuminate\Support\Facades\Cache::forget('rt:config:tradingv2:versions');

        return back()->with('success', "Version {$validated['pipeline_letter']}/{$validated['version_string']} created.");
    }

    /**
     * Update an alert version.
     */
    public function update(Request $request, int $id): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'pipeline_letter' => ['required', 'string', 'max:2'],
            'version_string' => ['required', 'string', 'max:20'],
            'signal_type' => ['required', 'string', 'max:50'],
            'scanner_score_formula' => ['nullable', 'string', 'max:255'],
            'enabled' => ['boolean'],
        ]);

        DB::table('alert_versions')->where('id', $id)->update([
            ...$validated,
            'updated_at' => now(),
        ]);

        \Illuminate\Support\Facades\Cache::forget('rt:config:tradingv2:versions');

        return back()->with('success', 'Version updated.');
    }

    /**
     * Delete an alert version.
     */
    public function destroy(int $id): \Illuminate\Http\RedirectResponse
    {
        DB::table('alert_versions')->where('id', $id)->delete();

        \Illuminate\Support\Facades\Cache::forget('rt:config:tradingv2:versions');

        return back()->with('success', 'Version deleted.');
    }

    /**
     * Toggle a version's enabled state.
     */
    public function toggle(int $id): \Illuminate\Http\RedirectResponse
    {
        $version = DB::table('alert_versions')->find($id);
        DB::table('alert_versions')->where('id', $id)->update([
            'enabled' => ! $version->enabled,
            'updated_at' => now(),
        ]);

        \Illuminate\Support\Facades\Cache::forget('rt:config:tradingv2:versions');

        return back();
    }

    /**
     * Add or update a gate for a version.
     */
    public function upsertGate(Request $request, int $versionId): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'timeframe' => ['required', 'in:5m,1m'],
            'gate_name' => ['required', 'string', 'max:64'],
            'threshold_min' => ['nullable', 'numeric'],
            'threshold_max' => ['nullable', 'numeric'],
            'enabled' => ['boolean'],
        ]);

        DB::table('alert_version_gates')->updateOrInsert(
            [
                'alert_version_id' => $versionId,
                'timeframe' => $validated['timeframe'],
                'gate_name' => $validated['gate_name'],
            ],
            [
                'threshold_min' => $validated['threshold_min'] !== null ? $validated['threshold_min'] : null,
                'threshold_max' => $validated['threshold_max'] !== null ? $validated['threshold_max'] : null,
                'enabled' => $validated['enabled'] ?? true,
                'updated_at' => now(),
            ]
        );

        \Illuminate\Support\Facades\Cache::forget('rt:config:tradingv2:versions');

        return back()->with('success', 'Gate updated.');
    }

    /**
     * Clone a version, copying all its gates and assigning the next available version string.
     */
    public function clone(Request $request, int $id): \Illuminate\Http\RedirectResponse
    {
        $source = DB::table('alert_versions')->find($id);
        if (! $source) {
            return back()->with('error', 'Source version not found.');
        }

        $nextVersion = $this->nextAvailableVersion();
        $nextLetter = $this->nextAvailableLetter();

        $newId = DB::table('alert_versions')->insertGetId([
            'pipeline_letter' => $nextLetter,
            'version_string' => $nextVersion,
            'signal_type' => $source->signal_type,
            'scanner_score_formula' => $source->scanner_score_formula,
            'enabled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Clone all gates from source
        $sourceGates = DB::table('alert_version_gates')
            ->where('alert_version_id', $id)
            ->get();

        foreach ($sourceGates as $gate) {
            DB::table('alert_version_gates')->insert([
                'alert_version_id' => $newId,
                'timeframe' => $gate->timeframe,
                'gate_name' => $gate->gate_name,
                'threshold_min' => $gate->threshold_min,
                'threshold_max' => $gate->threshold_max,
                'enabled' => $gate->enabled,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        \Illuminate\Support\Facades\Cache::forget('rt:config:tradingv2:versions');

        return back()->with('success', "Version cloned to pipeline letter '{$nextLetter}' (disabled by default).");
    }

    /**
     * Compute the next available pipeline letter using Excel-style sequencing.
     * After Z → AA, after AZ → BA, etc.
     */
    private function nextAvailableLetter(): string
    {
        $existing = DB::table('alert_versions')
            ->pluck('pipeline_letter')
            ->filter()
            ->all();

        $candidate = 'A';
        while (in_array($candidate, $existing, true)) {
            $candidate = $this->incrementLetter($candidate);
        }

        return $candidate;
    }

    /**
     * Increment a base-26 letter sequence (A, B, ..., Z, AA, AB, ..., AZ, BA, ...).
     */
    private function incrementLetter(string $letter): string
    {
        $chars = str_split($letter);
        $i = count($chars) - 1;

        while ($i >= 0) {
            if ($chars[$i] !== 'Z') {
                $chars[$i] = chr(ord($chars[$i]) + 1);

                return implode('', $chars);
            }
            $chars[$i] = 'A';
            $i--;
        }

        return 'A'.implode('', $chars);
    }

    /**
     * Compute the next available version string in v{N} format.
     * Finds the lowest positive integer not already used as v1, v2, v3, etc.
     */
    private function nextAvailableVersion(): string
    {
        $existing = DB::table('alert_versions')
            ->pluck('version_string')
            ->map(function (string $v): int {
                if (preg_match('/^v(\d+)(?:\.\d+)?$/', $v, $m)) {
                    return (int) $m[1];
                }

                return 0;
            })
            ->filter(fn (int $n) => $n > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();

        $candidate = 1;
        foreach ($existing as $n) {
            if ($n === $candidate) {
                $candidate++;
            } elseif ($n > $candidate) {
                break;
            }
        }

        return 'v'.$candidate;
    }

    /**
     * Delete a gate from a version.
     */
    public function destroyGate(int $gateId): \Illuminate\Http\RedirectResponse
    {
        DB::table('alert_version_gates')->where('id', $gateId)->delete();

        \Illuminate\Support\Facades\Cache::forget('rt:config:tradingv2:versions');

        return back()->with('success', 'Gate removed.');
    }
}
